<?php
/**
 * Intel Auctions Social — Auctions → Social Calendar → Log.
 *
 * Every generate, approve, push, edit read-back, drop and cancel, newest
 * first, read from the toolkit's event log (`ias.*` events). `?event=pushed`
 * narrows to one kind. (Entries from the dry run before 0.12.0 still show,
 * named as such; there is no dry run now.)
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('ias_log_render')) {
  function ias_log_render(): void {
    if (!current_user_can('manage_options')) return;
    ias_admin_styles();
    $kinds = ias_log_events();
    $event = isset($_GET['event']) ? sanitize_key(wp_unslash($_GET['event'])) : '';
    if (!isset($kinds[$event])) $event = '';

    ia_ui_header('Social Log', 'What the plugin did, newest first — kept in the toolkit\'s event log, which holds the most recent 5,000 entries across both plugins.');
    ias_header_end();
    echo ias_switches_strip();
    /** The monthly summary (summary.php). */
    do_action('ias_log_top');

    echo '<form method="get" style="margin:8px 0 12px">';
    echo '<input type="hidden" name="post_type" value="' . esc_attr(ia_schema_post_type()) . '"><input type="hidden" name="page" value="' . esc_attr(IAS_PAGE_LOG) . '">';
    echo '<label>Show <select name="event" style="max-width:calc(100vw - 140px)"><option value="">everything</option>';
    foreach ($kinds as $key => $label) echo '<option value="' . esc_attr($key) . '" ' . selected($event, $key, false) . '>' . esc_html($label) . '</option>';
    echo '</select></label> <button class="button">Filter</button> '
      // DJ, Oct 15, 2026: "I also want to be able to export the log page." Everything kept, as shown by the filter.
      . '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ias_log_export' . ($event !== '' ? '&event=' . rawurlencode($event) : '')), 'ias_log_export')) . '">Download CSV</a></form>';

    $rows = ias_log_recent($event, 200);
    if (!$rows) {
      ia_ui_empty('📜', 'Nothing logged yet', $event !== '' ? 'No <code>' . esc_html($event) . '</code> entries yet.' : 'Entries appear as the plugin plans, drafts and pushes posts.');
      echo '</div>';
      return;
    }

    echo '<div class="ias-summary-scroll"><table class="widefat striped"><thead><tr><th style="width:150px">When</th><th style="width:170px">What</th><th>Listing</th><th>Details</th><th style="width:90px">Who</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $meta = (array) $row['meta'];
      $listing = (int) $row['object_id'];
      $post = isset($meta['post']) ? (int) $meta['post'] : 0;
      echo '<tr>';
      echo '<td>' . esc_html(date_i18n('M j, g:i:s a', strtotime((string) $row['created_at']))) . '</td>';
      $short = (string) $row['short'];
      echo '<td><span title="' . esc_attr($short) . '">' . esc_html($kinds[$short] ?? ($short === 'dryrun' ? 'Dry run (before 0.12.0)' : $short)) . '</span></td>';
      $type = (string) ($meta['type'] ?? '');
      echo '<td>' . ($listing > 0 ? '<a href="' . esc_url((string) get_edit_post_link($listing, '')) . '">' . esc_html(html_entity_decode(get_the_title($listing), ENT_QUOTES, 'UTF-8') ?: '#' . $listing) . '</a>' : '—')
        . ($post > 0 ? '<br><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => $post])) . '">' . esc_html($type !== '' ? ias_post_type($type)['short'] . ' post' : 'the post') . '</a>' : '') . '</td>';
      echo '<td>' . ias_log_meta_html($row) . '</td>';
      echo '<td>' . esc_html(ias_actor_name((string) $row['actor'])) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div></div>';
  }
}

/** One entry's meta: the summary line, and the full payload when it has one. */
if (!function_exists('ias_log_meta_html')) {
  function ias_log_meta_html(array $row): string {
    $meta = (array) $row['meta'];
    $out = '';
    if (!empty($meta['summary'])) $out .= esc_html((string) $meta['summary']);
    if (!empty($meta['channel']) && function_exists('ias_channel')) $out = '<strong>' . esc_html((string) (ias_channel((string) $meta['channel'])['label'] ?? $meta['channel'])) . ':</strong> ' . $out;
    $rest = $meta;
    unset($rest['summary'], $rest['post'], $rest['payload'], $rest['type'], $rest['channel']); // the post type is in the Listing column
    if ($rest) {
      $out .= '<details><summary>More</summary><span class="description">' . esc_html(mb_substr(wp_json_encode($rest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 600)) . '</span></details>';
    }
    if (isset($meta['payload'])) {
      $out .= '<details><summary>Payload</summary><div class="ias-json">'
        . esc_html((string) wp_json_encode($meta['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</div></details>';
    }
    return $out !== '' ? $out : '—';
  }
}

/**
 * The log as a spreadsheet (0.23.0): every entry the toolkit's event log still
 * keeps (its newest 5,000, across both plugins), newest first, narrowed like the
 * page by ?event=. A row: when, what, the listing, the post, the summary, the
 * rest of its details and its payload as JSON, and who.
 */
if (!function_exists('ias_log_export_rows')) {
  function ias_log_export_rows(string $event = '', int $limit = 5000): array {
    if (!function_exists('ia_table')) return ias_log_recent($event, 500); // an older toolkit: its own reader, 500 at most
    global $wpdb;
    $t = ia_table('events');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE event LIKE %s ORDER BY id DESC LIMIT %d", $wpdb->esc_like('ias.' . $event) . '%', max(1, min(5000, $limit))), ARRAY_A);
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
      $meta = isset($row['meta']) ? json_decode((string) $row['meta'], true) : null;
      $row['meta'] = is_array($meta) ? $meta : [];
      $row['short'] = substr((string) $row['event'], 4);
      $out[] = $row;
    }
    return $out;
  }
}

/** One log entry as spreadsheet cells, in the order of ias_log_csv_head(). */
if (!function_exists('ias_log_csv_row')) {
  function ias_log_csv_row(array $row, array $kinds): array {
    $meta = (array) $row['meta'];
    $listing = (int) $row['object_id'];
    $short = (string) $row['short'];
    $rest = $meta;
    unset($rest['summary'], $rest['post'], $rest['payload'], $rest['type'], $rest['channel']);
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    return [
      (string) $row['created_at'],
      (string) ($kinds[$short] ?? $short),
      $listing > 0 ? (string) $listing : '',
      $listing > 0 ? html_entity_decode(get_the_title($listing), ENT_QUOTES, 'UTF-8') : '',
      isset($meta['post']) ? (string) (int) $meta['post'] : '',
      !empty($meta['type']) ? (string) (ias_post_type((string) $meta['type'])['short'] ?? $meta['type']) : '',
      (!empty($meta['channel']) && function_exists('ias_channel') ? (string) (ias_channel((string) $meta['channel'])['label'] ?? $meta['channel']) . ': ' : '') . (string) ($meta['summary'] ?? ''),
      $rest ? (string) wp_json_encode($rest, $flags) : '',
      isset($meta['payload']) ? (string) wp_json_encode($meta['payload'], $flags) : '',
      ias_actor_name((string) $row['actor']),
    ];
  }
}

if (!function_exists('ias_log_csv_head')) {
  function ias_log_csv_head(): array {
    return ['When (site time)', 'What', 'Listing ID', 'Listing', 'Post ID', 'Post type', 'Details', 'More', 'Payload', 'Who'];
  }
}

/** A spreadsheet cell that can never run as a formula (=, +, -, @ first: a leading apostrophe). Pure. */
if (!function_exists('ias_csv_safe')) {
  function ias_csv_safe(string $v): string {
    return $v !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v;
  }
}

add_action('admin_post_ias_log_export', function () {
  if (!current_user_can('manage_options') || !check_admin_referer('ias_log_export')) wp_die('Not allowed.', '', ['response' => 403]);
  $kinds = ias_log_events();
  $event = isset($_GET['event']) ? sanitize_key(wp_unslash($_GET['event'])) : '';
  if (!isset($kinds[$event])) $event = '';
  nocache_headers();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="social-log-' . gmdate('Y-m-d') . ($event !== '' ? '-' . $event : '') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF"); // so Excel reads it as UTF-8
  fputcsv($out, ias_log_csv_head());
  foreach (ias_log_export_rows($event) as $row) fputcsv($out, array_map('ias_csv_safe', ias_log_csv_row($row, $kinds)));
  fclose($out);
  exit;
});
