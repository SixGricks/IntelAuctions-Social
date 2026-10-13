<?php
/**
 * Shared set-up for the in-WordPress tests (not a test itself: wp-run.php
 * runs tests/wp/*.php, and this file only defines helpers, guarded so two
 * tests can both require it).
 *
 *   ias_t_reset()       empty the plugin's tables, delete the test listings
 *   ias_t_clock($when)  pin the plugin's clock (the `ias_now` filter)
 *   ias_t_terms()       the stage and category terms the site has
 *   ias_t_photo($label) a real JPEG in the Media Library, with its sizes
 *   ias_t_listing([…])  an auction with its ACF-style meta
 *   ias_t_claude()      answer Anthropic API calls like Claude would, offline
 */

if (function_exists('ias_t_reset')) return;

function ias_t_reset(): void {
  global $wpdb;
  $wpdb->query('TRUNCATE ' . ias_table('posts'));
  $wpdb->query('TRUNCATE ' . ias_table('history'));
  $wpdb->query("DELETE FROM {$wpdb->prefix}ia_events WHERE event LIKE 'ias.%'");
  foreach (get_posts(['post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_iast_fixture', 'fields' => 'ids']) as $id) {
    wp_delete_post((int) $id, true);
  }
  foreach (['ias_installed_at', 'ias_live_since', 'ias_last_plan', 'ias_last_plan_summary', 'ias_last_review', 'ias_last_reminders', 'ias_push_mode', 'ias_readback_last', 'ias_oneup_verify', 'ias_push_lock'] as $o) delete_option($o);
  update_option('ias_settings', [], true);
}

function ias_t_clock(string $when): void {
  remove_all_filters('ias_now');
  add_filter('ias_now', function () use ($when) { return new DateTimeImmutable($when, ia_wp_tz()); });
}

function ias_t_term(string $name, string $slug, int $parent = 0): int {
  $t = get_term_by('slug', $slug, 'category');
  if ($t) return (int) $t->term_id;
  $r = wp_insert_term($name, 'category', ['slug' => $slug, 'parent' => $parent]);
  return is_wp_error($r) ? 0 : (int) $r['term_id'];
}

function ias_t_terms(): array {
  $t = [];
  $t['announced'] = ias_t_term('Recently Announced', 'recently-announced');
  $t['upcoming']  = ias_t_term('Upcoming Auctions', 'upcoming-auctions');
  $t['past']      = ias_t_term('Past Auctions', 'past-auctions');
  $cat = ias_t_term('Auction Category', 'auction-category');
  $t['farm']   = ias_t_term('Farm & Home', 'farm-home', $cat);
  $t['invest'] = ias_t_term('Investment and Recreation', 'investment-and-recreation', $cat);
  $t['intel']  = ias_t_term('Intel Auction (Generic)', 'intel-auction', $cat);
  $type = ias_t_term('Auction Type', 'auction-type');
  $t['in_person'] = ias_t_term('In Person Auction', 'in-person-auction', $type);
  $t['phone']     = ias_t_term('Intel Phone Bidding Auction', 'intel-phone-bidding-auction', $type);
  return $t;
}

/** A 1600×1067 JPEG with a colour and a label, added to the Media Library with its sizes. */
function ias_t_photo(string $label, array $rgb = [90, 120, 80]): int {
  $up = wp_upload_dir();
  $file = trailingslashit($up['path']) . 'ias-test-' . sanitize_title($label) . '-' . wp_rand(1000, 9999) . '.jpg';
  $im = imagecreatetruecolor(1600, 1067);
  imagefilledrectangle($im, 0, 0, 1600, 1067, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
  imagefilledrectangle($im, 0, 700, 1600, 1067, imagecolorallocate($im, max(0, $rgb[0] - 40), max(0, $rgb[1] - 40), max(0, $rgb[2] - 40)));
  imagestring($im, 5, 40, 40, $label, imagecolorallocate($im, 255, 255, 255));
  imagejpeg($im, $file, 85);
  imagedestroy($im);
  $id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => $label, 'post_status' => 'inherit'], $file);
  require_once ABSPATH . 'wp-admin/includes/image.php';
  wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
  update_post_meta($id, '_iast_fixture', 1);
  return (int) $id;
}

/**
 * An auction. $a: title, stage, sale (Y-m-d H:i), terms (term ids), bid, size,
 * town, county, address, lead (team id), gallery (ids), featured, oh (list of
 * [Y-m-d H:i, hours]), price, auction_type, sellers, content.
 */
function ias_t_listing(array $a): int {
  $id = wp_insert_post(['post_type' => 'auction', 'post_status' => $a['status'] ?? 'publish', 'post_title' => $a['title'],
    'post_content' => $a['content'] ?? 'A well-kept property with good road frontage.', 'post_excerpt' => $a['excerpt'] ?? '']);
  update_post_meta($id, '_iast_fixture', 1);
  if (!empty($a['terms'])) wp_set_object_terms($id, array_map('intval', $a['terms']), 'category', true);
  if (!empty($a['sale'])) update_post_meta($id, 'auction_date', $a['sale'] . ':00');
  if (isset($a['bid'])) update_post_meta($id, 'Starting_Bid', $a['bid']);
  if (isset($a['size'])) update_post_meta($id, 'property_size', $a['size']);
  if (isset($a['auction_type'])) update_post_meta($id, 'auction_type', $a['auction_type']);
  if (isset($a['sellers'])) update_post_meta($id, 'sellers', $a['sellers']);
  $addr = $a['address'] ?? ($a['title'] . ', ' . ($a['town'] ?? 'Narvon') . ', PA 17555, USA');
  update_post_meta($id, 'address', ['address' => $addr, 'lat' => '40.1', 'lng' => '-76.1']);
  update_post_meta($id, '_ia_town', $a['town'] ?? 'Narvon');
  update_post_meta($id, '_ia_state', 'PA');
  if (!empty($a['county'])) update_post_meta($id, '_ia_county', $a['county']);
  if (!empty($a['lead'])) update_post_meta($id, 'lead', [(string) (int) $a['lead']]);
  if (!empty($a['gallery'])) update_post_meta($id, 'gallery', implode(',', array_map('intval', $a['gallery'])));
  if (!empty($a['featured'])) set_post_thumbnail($id, (int) $a['featured']);
  foreach (array_values($a['oh'] ?? []) as $i => $oh) {
    update_post_meta($id, $i === 0 ? 'open_house' : 'open_house_2', $oh[0] . ':00');
    if (isset($oh[1])) update_post_meta($id, $i === 0 ? 'open_house_duration' : 'open_house_2_duration', (string) $oh[1]);
  }
  if (!empty($a['price'])) update_post_meta($id, '_ia_result_price', $a['price']);
  if (!empty($a['stage'])) ia_stage_set($id, $a['stage']);
  return (int) $id;
}

function ias_t_team(string $name, string $phone): int {
  $id = wp_insert_post(['post_type' => 'team-member', 'post_status' => 'publish', 'post_title' => $name]);
  update_post_meta($id, '_iast_fixture', 1);
  update_post_meta($id, 'phone_number', $phone);
  return (int) $id;
}

/**
 * Answer the Anthropic API offline: photo grading gets a grade per photo, a
 * post gets opener/body/cta written from the FACTS it was sent — stating the
 * bidding type the facts give, the starting bid and sale price as given, and
 * the lead's phone. $GLOBALS['ias_t_claude_bad'] = a property name whose
 * posts come back with a fair-housing phrase in them; ias_t_claude_calls()
 * counts the calls.
 */
function ias_t_claude(): void {
  update_option('ia_anthropic_api_key', 'test-key');
  $GLOBALS['ias_t_claude_n'] = 0;
  add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos((string) $url, 'api.anthropic.com') === false) return $pre;
    $GLOBALS['ias_t_claude_n']++;
    $n = $GLOBALS['ias_t_claude_n'];
    $body = json_decode((string) $args['body'], true);
    $content = $body['messages'][0]['content'];
    if (is_array($content)) { // photo grading
      $count = count(array_filter($content, function ($b) { return ($b['type'] ?? '') === 'image'; }));
      $kinds = ['aerial', 'exterior', 'interior', 'barn', 'land', 'interior', 'exterior', 'aerial'];
      $rows = [];
      for ($i = 1; $i <= $count; $i++) $rows[] = ['n' => $i, 'quality' => 9 - ($i % 4), 'kind' => $kinds[($i - 1) % 8], 'alt' => 'Test photo ' . $i . ' (' . $kinds[($i - 1) % 8] . ')'];
      $text = json_encode($rows);
    } else {
      preg_match('/POST TYPE: ([A-Z ]+)/', $content, $m);
      $type = trim($m[1] ?? 'POST');
      $facts = json_decode(substr($content, strpos($content, "FACTS:\n") + 7), true);
      $list = isset($facts['property']) ? [$facts] : (array) $facts;
      $lines = [];
      foreach ($list as $f) {
        $line = ($f['street'] ?? $f['property'] ?? 'The property') . ' in ' . ($f['town'] ?? 'town');
        if (!empty($f['auction_date'])) $line .= ' sells ' . $f['auction_date'];
        if (stripos($f['bidding'] ?? '', 'Intel Phone Bidding') === 0) $line .= '. This one is Intel Phone Bidding';
        elseif (stripos($f['bidding'] ?? '', 'In-person') === 0) $line .= '. It is an in-person auction';
        if (!empty($f['starting_bid'])) $line .= '. Starting bid ' . $f['starting_bid'];
        if (!empty($f['sale_price'])) $line .= '. It sold for ' . $f['sale_price'];
        if (!empty($GLOBALS['ias_t_claude_bad']) && ($f['property'] ?? '') === $GLOBALS['ias_t_claude_bad']) $line .= '. Perfect for families';
        $lines[] = $line . '.';
      }
      $phone = $list[0]['lead_phone'] ?? ($list[0]['office_phone'] ?? '717-917-5844');
      $text = json_encode([
        'opener' => ucfirst(strtolower($type)) . ' note number ' . $n . ' from the sale barn.',
        'body'   => implode("\n", $lines),
        'cta'    => 'Questions? Call ' . $phone . ' and ask for take ' . $n . '.',
      ]);
    }
    return ['headers' => [], 'body' => json_encode(['content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 200, 'output_tokens' => 80], 'stop_reason' => 'end_turn']),
      'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
  }, 10, 3);
}

function ias_t_claude_calls(): int { return (int) ($GLOBALS['ias_t_claude_n'] ?? 0); }

function ias_t_claude_off(): void {
  delete_option('ia_anthropic_api_key');
  remove_all_filters('pre_http_request');
}

/**
 * A fake OneUp (pre_http_request on oneupapp.io/api/), shaped like the real
 * account's answers: categories, the category's accounts (Google Business
 * Profile and the Facebook page "Intel Auctions (Narvon)", id fb_123, in
 * category 42), schedule (text/image/video, drafts with isDraftPost), delete,
 * and the scheduled / published / drafts / failed lists (50 a page,
 * next_start). The key must be 'test-oneup-key'. State in
 * $GLOBALS['ias_t_oneup']: scheduled, published, drafts, failed (id => row),
 * calls (every request: path, method, params), fail_next (refuse that many
 * schedules), no_id (answer without a post id), tz_shift (hours OneUp's clock
 * is off the site's), gbp (a Google Business Profile post on the list).
 */
function ias_t_oneup(): void {
  // Switches for the ways OneUp can let us down: fail_next (refuse the next N posts), no_id (answer
  // without a post id), tz_shift (hours the account is off the site), lose_answer (schedule the next N
  // posts but answer 502), net_error (the next N posts never arrive; the error echoes the URL, key and
  // all), delete_404 (the next N deletes answer "not found" without deleting), broken (paths that answer
  // 200 with no list in it), draft_real (drafts are scheduled as real posts), fb (the page's name).
  $GLOBALS['ias_t_oneup'] = ['scheduled' => [], 'published' => [], 'drafts' => [], 'failed' => [], 'next' => 900001, 'calls' => [],
    'fail_next' => 0, 'no_id' => false, 'tz_shift' => 0, 'gbp' => true, 'lose_answer' => 0, 'net_error' => 0, 'delete_404' => 0,
    'broken' => [], 'draft_real' => false, 'fb' => 'Intel Auctions (Narvon)'];
  add_filter('ias_oneup_key', function () { return 'test-oneup-key'; });
  add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos((string) $url, 'oneupapp.io/api/') === false) return $pre;
    $S =& $GLOBALS['ias_t_oneup'];
    $parts = wp_parse_url((string) $url);
    parse_str((string) ($parts['query'] ?? ''), $q);
    $path = basename((string) $parts['path']);
    $params = array_merge($q, is_array($args['body'] ?? null) ? $args['body'] : []);
    $S['calls'][] = ['path' => $path, 'method' => (string) ($args['method'] ?? 'GET'), 'params' => $params];
    $json = function (int $code, $data) {
      return ['headers' => [], 'body' => (string) json_encode($data), 'response' => ['code' => $code, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    };
    if (($params['apiKey'] ?? '') !== 'test-oneup-key') return $json(401, ['error' => true, 'message' => 'Invalid API key']);
    if (in_array($path, (array) $S['broken'], true)) return $json(200, ['message' => 'Too many requests, slow down']);
    $fb = (string) $S['fb'];
    $page = function (array $rows, string $time_key) use ($params, $json) {
      $start = (int) ($params['start'] ?? 0);
      $rows = array_values($rows);
      if ($time_key === 'date_time' && !empty($GLOBALS['ias_t_oneup']['gbp'])) {
        $rows[] = ['post_id' => 555, 'content' => 'A Google Business Profile post', 'source_url' => 'NA', $time_key => '2026-11-10 11:05:45', 'category_name' => 'Intel', 'video_url' => 'NA', 'content_image' => 'NA', 'social_network_username' => 'Intel Auctions (5940 Plank Road  Narvon  PA  US )'];
      }
      $slice = array_slice($rows, $start, 50);
      return $json(200, ['posts' => $slice, 'start' => $start, 'count' => count($slice), 'next_start' => $start + 50 < count($rows) ? $start + 50 : null]);
    };
    switch ($path) {
      case 'listcategory':
        return $json(200, ['categories' => [['id' => 42, 'category_name' => 'Intel', 'isPaused' => 0]]]);
      case 'listcategoryaccount':
        return $json(200, ['accounts' => [
          ['category_id' => 42, 'social_network_name' => 'Intel Auctions (5940 Plank Road  Narvon  PA  US )', 'social_network_id' => 'accounts/1/locations/2', 'social_network_type' => 'GBP'],
          ['category_id' => 42, 'social_network_name' => $fb, 'social_network_id' => 'fb_123', 'social_network_type' => 'Facebook'],
        ]]);
      case 'scheduletextpost': case 'scheduleimagepost': case 'schedulevideopost':
        if ($S['net_error'] > 0) { $S['net_error']--; return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds for ' . $url); }
        if ($S['fail_next'] > 0) { $S['fail_next']--; return $json(200, ['error' => true, 'message' => 'The Facebook token has expired']); }
        $id = $S['next']++;
        $row = ['post_id' => $id, 'content' => (string) ($params['content'] ?? ''), 'source_url' => 'NA',
          'date_time' => date('Y-m-d H:i:s', strtotime((string) $params['scheduled_date_time']) + 3600 * (int) $S['tz_shift']),
          'category_name' => 'Intel', 'video_url' => (string) ($params['video_url'] ?? 'NA'), 'content_image' => (string) ($params['image_url'] ?? 'NA'),
          'social_network_username' => ((string) ($params['social_network_id'] ?? '') === 'fb_123') ? $fb : 'someone else', 'first_comment' => (string) ($params['first_comment'] ?? '')];
        if (($params['isDraftPost'] ?? '') === 'true' && !$S['draft_real']) $S['drafts'][$id] = $row; else $S['scheduled'][$id] = $row;
        if ($S['lose_answer'] > 0) { $S['lose_answer']--; return $json(502, ['message' => 'Bad gateway']); }
        return $json(200, $S['no_id'] ? ['error' => false, 'message' => 'Post scheduled successfully'] : ['error' => false, 'message' => 'Post scheduled successfully', 'data' => ['post_id' => $id]]);
      case 'deletepost':
        $id = (int) ($params['post_id'] ?? 0);
        if ($S['delete_404'] > 0) { $S['delete_404']--; return $json(404, ['error' => true, 'message' => 'Post not found']); }
        foreach (['scheduled', 'drafts'] as $list) {
          if (isset($S[$list][$id])) { unset($S[$list][$id]); return $json(200, ['error' => false, 'message' => 'Post deleted']); }
        }
        return $json(404, ['error' => true, 'message' => 'Post not found']);
      case 'getscheduledposts': return $page($S['scheduled'], 'date_time');
      case 'getpublishedposts': return $page($S['published'], 'created_at');
      case 'getdraftposts':     return $page($S['drafts'], 'date_time');
      case 'getfailedposts':    return $page($S['failed'], 'date_time');
    }
    return $json(404, ['error' => true, 'message' => 'Unknown endpoint ' . $path]);
  }, 10, 3);
}

/** The fake OneUp's calls since $from (index), optionally only to $path. */
function ias_t_oneup_calls(int $from = 0, string $path = ''): array {
  return array_values(array_filter(array_slice((array) ($GLOBALS['ias_t_oneup']['calls'] ?? []), $from), function ($c) use ($path) { return $path === '' || $c['path'] === $path; }));
}

/** OneUp publishes a scheduled post at its time (created_at in UTC, as OneUp reports it). */
function ias_t_oneup_publish(int $id): void {
  $S =& $GLOBALS['ias_t_oneup'];
  $row = $S['scheduled'][$id];
  $at = (string) $row['date_time'];
  unset($S['scheduled'][$id], $row['date_time']);
  $row['created_at'] = (new DateTimeImmutable($at, ia_wp_tz()))->modify('+1 minute')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  $S['published'][$id] = $row;
}

/** The page is renamed in OneUp: its account and every row on it carry the new name. */
function ias_t_oneup_rename(string $name): void {
  $S =& $GLOBALS['ias_t_oneup'];
  foreach (['scheduled', 'published', 'drafts', 'failed'] as $list) {
    foreach ($S[$list] as $id => $row) if (($row['social_network_username'] ?? '') === $S['fb']) $S[$list][$id]['social_network_username'] = $name;
  }
  $S['fb'] = $name;
}

/** A person's own post on the page, scheduled in OneUp. */
function ias_t_oneup_human(string $at, string $text): int {
  $S =& $GLOBALS['ias_t_oneup'];
  $id = $S['next']++;
  $S['scheduled'][$id] = ['post_id' => $id, 'content' => $text, 'source_url' => 'NA', 'date_time' => $at . ':00',
    'category_name' => 'Intel', 'video_url' => 'NA', 'content_image' => 'NA', 'social_network_username' => $S['fb']];
  return $id;
}

function ias_t_oneup_off(): void {
  remove_all_filters('pre_http_request');
  remove_all_filters('ias_oneup_key');
}
