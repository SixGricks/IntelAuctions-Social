<?php
/**
 * Intel Auctions Social — the two tables.
 *
 *   {prefix}ias_posts    — one row per social post: its listing(s), type,
 *                          status, score, window, scheduled time, OneUp id,
 *                          content (JSON), images used, approvals and the log
 *                          of what happened to it. `slot_key` is the post's
 *                          identity ("listing:123", "open_house:123:1",
 *                          "auction_week:2026-11-18:12,13,14", "roundup:2026-11-20"),
 *                          so planning the same post twice updates one row.
 *   {prefix}ias_history  — every opener, call to action, hashtag set, template
 *                          and image a post has used, for the no-repeat rules.
 *
 * Photo grades live on the attachment (post meta `_ias_photo`), so each
 * picture is graded once whichever post asks. Both tables, and everything
 * else this plugin stores, go on uninstall (uninstall.php).
 *
 * Versioned by `ias_db_version`; migrated on admin_init and at the start of
 * every cron run (auto-updates never fire activation hooks).
 */

if (!defined('ABSPATH')) exit;

if (!defined('IAS_DB_VERSION')) define('IAS_DB_VERSION', '0.1.0');

/** Fully-prefixed table name: ias_table('posts') → wp_ias_posts. */
if (!function_exists('ias_table')) {
  function ias_table(string $name): string {
    global $wpdb;
    return $wpdb->prefix . 'ias_' . $name;
  }
}

if (!function_exists('ias_db_ready')) {
  function ias_db_ready(): bool {
    return get_option('ias_db_version') === IAS_DB_VERSION;
  }
}

if (!function_exists('ias_db_migrate')) {
  function ias_db_migrate(): void {
    if (ias_db_ready()) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $posts   = ias_table('posts');
    $history = ias_table('history');

    dbDelta("CREATE TABLE {$posts} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slot_key varchar(160) NOT NULL,
  type varchar(20) NOT NULL,
  listing_id bigint(20) unsigned NOT NULL DEFAULT 0,
  listing_ids varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'planned',
  lane char(1) NOT NULL DEFAULT 'B',
  weight smallint(5) unsigned NOT NULL DEFAULT 0,
  score decimal(8,2) NOT NULL DEFAULT 0,
  guaranteed tinyint(1) NOT NULL DEFAULT 0,
  county varchar(80) NOT NULL DEFAULT '',
  division varchar(20) NOT NULL DEFAULT '',
  window_start date DEFAULT NULL,
  window_ideal date DEFAULT NULL,
  window_end date DEFAULT NULL,
  scheduled_at datetime DEFAULT NULL,
  locked tinyint(1) NOT NULL DEFAULT 0,
  oneup_post_id varchar(64) NOT NULL DEFAULT '',
  content longtext,
  images_used text,
  facts_hash char(32) NOT NULL DEFAULT '',
  flags text,
  approvals text,
  note text,
  metrics text,
  generated_at datetime DEFAULT NULL,
  approved_at datetime DEFAULT NULL,
  approved_by varchar(100) NOT NULL DEFAULT '',
  pushed_at datetime DEFAULT NULL,
  published_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slot_key (slot_key),
  KEY listing_id (listing_id),
  KEY status (status),
  KEY scheduled_at (scheduled_at)
) {$charset};");

    dbDelta("CREATE TABLE {$history} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL,
  value_hash char(32) NOT NULL,
  value text,
  listing_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  used_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY kind_hash (kind,value_hash),
  KEY kind_used (kind,used_at),
  KEY listing_kind (listing_id,kind)
) {$charset};");

    // Only stamp the version when both tables are really there.
    foreach ([$posts, $history] as $table) {
      if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return;
    }
    update_option('ias_db_version', IAS_DB_VERSION, true);
  }
}
add_action('admin_init', 'ias_db_migrate', 6);

/* ---------------- Posts ---------------- */

/** The columns that hold JSON. */
if (!function_exists('ias_post_json_columns')) {
  function ias_post_json_columns(): array {
    return ['content', 'images_used', 'flags', 'approvals', 'metrics'];
  }
}

/** A row from the table with its JSON decoded and its numbers typed. */
if (!function_exists('ias_post_decode')) {
  function ias_post_decode(array $row): array {
    foreach (ias_post_json_columns() as $col) {
      $val = isset($row[$col]) ? json_decode((string) $row[$col], true) : null;
      $row[$col] = is_array($val) ? $val : [];
    }
    foreach (['id', 'listing_id', 'weight', 'guaranteed', 'locked'] as $col) {
      if (isset($row[$col])) $row[$col] = (int) $row[$col];
    }
    $row['score'] = isset($row['score']) ? (float) $row['score'] : 0.0;
    $row['listing_ids'] = ias_ids_from_column((string) ($row['listing_ids'] ?? ''));
    if (!$row['listing_ids'] && !empty($row['listing_id'])) $row['listing_ids'] = [(int) $row['listing_id']];
    return $row;
  }
}

/** ",12,13," → [12, 13]. */
if (!function_exists('ias_ids_from_column')) {
  function ias_ids_from_column(string $col): array {
    return array_values(array_filter(array_map('intval', explode(',', $col))));
  }
}

/** [12, 13] → ",12,13," (leading and trailing commas make LIKE '%,12,%' exact). */
if (!function_exists('ias_ids_to_column')) {
  function ias_ids_to_column(array $ids): string {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return $ids ? ',' . implode(',', $ids) . ',' : '';
  }
}

/** Prepare a row for writing: JSON-encode, list ids, stamp updated_at. */
if (!function_exists('ias_post_encode')) {
  function ias_post_encode(array $fields): array {
    foreach (ias_post_json_columns() as $col) {
      if (array_key_exists($col, $fields) && !is_string($fields[$col])) {
        $fields[$col] = wp_json_encode($fields[$col] ?: []);
      }
    }
    if (array_key_exists('listing_ids', $fields) && is_array($fields['listing_ids'])) {
      $fields['listing_ids'] = ias_ids_to_column($fields['listing_ids']);
    }
    unset($fields['id']);
    $fields['updated_at'] = ias_now_sql();
    return $fields;
  }
}

if (!function_exists('ias_post_get')) {
  function ias_post_get(int $id): ?array {
    if ($id <= 0 || !ias_db_ready()) return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . ias_table('posts') . ' WHERE id = %d', $id), ARRAY_A);
    return is_array($row) ? ias_post_decode($row) : null;
  }
}

if (!function_exists('ias_post_by_slot')) {
  function ias_post_by_slot(string $slot_key): ?array {
    if ($slot_key === '' || !ias_db_ready()) return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . ias_table('posts') . ' WHERE slot_key = %s', $slot_key), ARRAY_A);
    return is_array($row) ? ias_post_decode($row) : null;
  }
}

/** Insert a new post row; returns its id (0 on failure). */
if (!function_exists('ias_post_insert')) {
  function ias_post_insert(array $fields): int {
    if (!ias_db_ready() || empty($fields['slot_key']) || empty($fields['type'])) return 0;
    global $wpdb;
    $fields = ias_post_encode($fields);
    $fields['created_at'] = $fields['updated_at'];
    $ok = $wpdb->insert(ias_table('posts'), $fields);
    return $ok ? (int) $wpdb->insert_id : 0;
  }
}

if (!function_exists('ias_post_update')) {
  function ias_post_update(int $id, array $fields): bool {
    if ($id <= 0 || !ias_db_ready()) return false;
    global $wpdb;
    return $wpdb->update(ias_table('posts'), ias_post_encode($fields), ['id' => $id]) !== false;
  }
}

/**
 * Posts matching $args, as decoded rows:
 *   status (string|array), type (string|array), listing (int: the listing alone
 *   or inside a merged post), from / to (Y-m-d: scheduled_at, or the window's
 *   ideal day when unscheduled, inside the range), order ('scheduled'|'id'), limit.
 */
if (!function_exists('ias_posts_query')) {
  function ias_posts_query(array $args = []): array {
    if (!ias_db_ready()) return [];
    global $wpdb;
    $t = ias_table('posts');
    $where = ['1=1'];
    $vals  = [];
    foreach (['status', 'type'] as $col) {
      if (!isset($args[$col]) || $args[$col] === '' || $args[$col] === []) continue;
      $list = array_values(array_filter(array_map('strval', (array) $args[$col])));
      if (!$list) continue;
      $where[] = "{$col} IN (" . implode(',', array_fill(0, count($list), '%s')) . ')';
      $vals = array_merge($vals, $list);
    }
    if (!empty($args['listing'])) {
      $where[] = '(listing_id = %d OR listing_ids LIKE %s)';
      $vals[] = (int) $args['listing'];
      $vals[] = '%,' . (int) $args['listing'] . ',%';
    }
    if (!empty($args['from'])) {
      $where[] = 'COALESCE(DATE(scheduled_at), window_ideal) >= %s';
      $vals[] = (string) $args['from'];
    }
    if (!empty($args['to'])) {
      $where[] = 'COALESCE(DATE(scheduled_at), window_ideal) <= %s';
      $vals[] = (string) $args['to'];
    }
    $order = ($args['order'] ?? 'scheduled') === 'id'
      ? 'id DESC'
      : 'COALESCE(scheduled_at, window_ideal) IS NULL, COALESCE(scheduled_at, window_ideal) ASC, id ASC';
    $limit = max(1, min(2000, (int) ($args['limit'] ?? 500)));
    $sql = "SELECT * FROM {$t} WHERE " . implode(' AND ', $where) . " ORDER BY {$order} LIMIT {$limit}";
    $rows = $vals ? $wpdb->get_results($wpdb->prepare($sql, $vals), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    return array_map('ias_post_decode', is_array($rows) ? $rows : []);
  }
}

/** Every post for one listing, merged posts included, soonest first. */
if (!function_exists('ias_posts_for_listing')) {
  function ias_posts_for_listing(int $listing_id): array {
    return $listing_id > 0 ? ias_posts_query(['listing' => $listing_id]) : [];
  }
}

/**
 * Append a line to a post's own history (kept in flags['trail'], newest last,
 * 30 lines at most) — the calendar's detail view shows it.
 */
if (!function_exists('ias_post_trail')) {
  function ias_post_trail(int $id, string $what, string $actor = 'system'): void {
    $post = ias_post_get($id);
    if (!$post) return;
    $flags = $post['flags'];
    $trail = isset($flags['trail']) && is_array($flags['trail']) ? $flags['trail'] : [];
    $trail[] = ['at' => ias_now_sql(), 'by' => $actor, 'what' => $what];
    $flags['trail'] = array_slice($trail, -30);
    ias_post_update($id, ['flags' => $flags]);
  }
}

/* ---------------- Usage history (no-repeat rules) ---------------- */

/** Normalise text for "is this the same line": case, punctuation and spacing ignored. */
if (!function_exists('ias_history_norm')) {
  function ias_history_norm(string $value): string {
    $value = strtolower(html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8'));
    $value = preg_replace('/[^\p{L}\p{N}#]+/u', ' ', $value);
    return trim((string) $value);
  }
}

if (!function_exists('ias_history_add')) {
  function ias_history_add(string $kind, string $value, int $listing_id = 0, int $post_id = 0): void {
    if (!ias_db_ready() || trim($value) === '') return;
    global $wpdb;
    $wpdb->insert(ias_table('history'), [
      'kind'       => substr($kind, 0, 20),
      'value_hash' => md5(ias_history_norm($value)),
      'value'      => $value,
      'listing_id' => $listing_id,
      'post_id'    => $post_id,
      'used_at'    => ias_now_sql(),
    ], ['%s', '%s', '%s', '%d', '%d', '%s']);
  }
}

/** Was this value used (by any post other than $except_post) in the last $days days? */
if (!function_exists('ias_history_used')) {
  function ias_history_used(string $kind, string $value, int $days = 30, int $except_post = 0): bool {
    if (!ias_db_ready() || trim($value) === '') return false;
    global $wpdb;
    $since = ias_now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
    return (bool) $wpdb->get_var($wpdb->prepare(
      'SELECT id FROM ' . ias_table('history') . ' WHERE kind = %s AND value_hash = %s AND used_at >= %s AND post_id <> %d LIMIT 1',
      $kind, md5(ias_history_norm($value)), $since, $except_post
    ));
  }
}

/** Values of one kind used in the last $days days (newest first), optionally for one listing. */
if (!function_exists('ias_history_recent')) {
  function ias_history_recent(string $kind, int $days = 30, int $listing_id = 0, int $limit = 60): array {
    if (!ias_db_ready()) return [];
    global $wpdb;
    $since = ias_now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
    $sql = 'SELECT value FROM ' . ias_table('history') . ' WHERE kind = %s AND used_at >= %s';
    $vals = [$kind, $since];
    if ($listing_id > 0) { $sql .= ' AND listing_id = %d'; $vals[] = $listing_id; }
    $sql .= ' ORDER BY id DESC LIMIT %d';
    $vals[] = max(1, $limit);
    return array_map('strval', (array) $wpdb->get_col($wpdb->prepare($sql, $vals)));
  }
}

/** Forget what a post recorded (it is being regenerated, or was never used). */
if (!function_exists('ias_history_forget_post')) {
  function ias_history_forget_post(int $post_id, array $kinds = []): void {
    if ($post_id <= 0 || !ias_db_ready()) return;
    global $wpdb;
    $t = ias_table('history');
    if ($kinds) {
      $in = implode(',', array_fill(0, count($kinds), '%s'));
      $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE post_id = %d AND kind IN ({$in})", array_merge([$post_id], array_values($kinds))));
    } else {
      $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE post_id = %d", $post_id));
    }
  }
}
