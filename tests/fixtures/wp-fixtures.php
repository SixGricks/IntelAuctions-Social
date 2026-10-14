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
  ias_db_migrate(); // a schema bump (0.7.0's chat and voice tables) lands before anything reads them, as admin_init would
  $wpdb->query('TRUNCATE ' . ias_table('posts'));
  $wpdb->query('TRUNCATE ' . ias_table('history'));
  $wpdb->query('TRUNCATE ' . ias_table('chat'));
  $wpdb->query('TRUNCATE ' . ias_table('voice'));
  $wpdb->query("DELETE FROM {$wpdb->prefix}ia_events WHERE event LIKE 'ias.%'");
  foreach (get_posts(['post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_iast_fixture', 'fields' => 'ids']) as $id) {
    wp_delete_post((int) $id, true);
  }
  foreach (['ias_installed_at', 'ias_live_since', 'ias_last_plan', 'ias_last_plan_summary', 'ias_last_review', 'ias_last_reminders', 'ias_push_mode', 'ias_readback_last', 'ias_oneup_verify', 'ias_gbp_verify', 'ias_gbp_mode', 'ias_oneup_directory', 'ias_pulse', 'ias_oneup_id_form', 'ias_oneup_verify_leftovers', 'ias_push_lock', 'ias_oneup_api_key', 'ias_voice_notes', 'ias_voice_suggest', 'ias_voice_learned', 'ias_voice_pruned'] as $o) delete_option($o);
  $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ias\\_chat\\_lock\\_%'");
  update_option('ias_settings', [], true);
  // The dry run's one-time retirement (0.12.0) is its own test (07e): elsewhere it has already happened,
  // so a fresh test database never has it switch off what a test just switched on.
  update_option('ias_dry_run_retired', ['at' => 'tests', 'off' => []], false);
  // The fake Claude's calls are metered like real ones: without this, a month of test runs spends the
  // toolkit's real monthly cap and every later draft is refused as over budget.
  delete_option('ia_ai_tok_in_' . current_time('Y-m'));
  delete_option('ia_ai_tok_out_' . current_time('Y-m'));
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
  // A pattern of its own, seeded by the label, so no two fixture photos look like the same view
  // to the near-duplicate check (real photos differ like this; flat colour blocks would not).
  mt_srand(crc32($label));
  for ($i = 0; $i < 14; $i++) {
    $x = mt_rand(0, 1500); $y = mt_rand(0, 960); $v = mt_rand(0, 255);
    imagefilledrectangle($im, $x, $y, $x + mt_rand(120, 520), $y + mt_rand(90, 400), imagecolorallocate($im, $v, (int) ($v * 0.8), (int) ($v * 0.6)));
  }
  mt_srand();
  imagestring($im, 5, 40, 40, $label, imagecolorallocate($im, 255, 255, 255));
  imagejpeg($im, $file, 85);
  imagedestroy($im);
  $id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => $label, 'post_status' => 'inherit'], $file);
  require_once ABSPATH . 'wp-admin/includes/image.php';
  wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
  update_post_meta($id, '_iast_fixture', 1);
  return (int) $id;
}

/** A second shot of the same view as $att: the same picture, a touch brighter and shifted a few pixels. */
function ias_t_photo_twin(int $att, string $label): int {
  $src = imagecreatefromjpeg((string) get_attached_file($att));
  $im = imagecreatetruecolor(1600, 1067);
  imagecopy($im, $src, 0, 0, 12, 8, 1588, 1059);
  imagefilter($im, IMG_FILTER_BRIGHTNESS, 12);
  $up = wp_upload_dir();
  $file = trailingslashit($up['path']) . 'ias-test-' . sanitize_title($label) . '-' . wp_rand(1000, 9999) . '.jpg';
  imagejpeg($im, $file, 85);
  imagedestroy($im); imagedestroy($src);
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
    $GLOBALS['ias_t_claude_last'] = ['system' => (string) ($body['system'] ?? ''), 'user' => is_string($content) ? $content : ''];
    if (is_string($content) && strpos($content, ' SAYS: ') !== false) { // Talk it over
      preg_match('/THE POST TEXT NOW[^\n]*\n<<<\n(.*?)\n>>>/s', $content, $m);
      $now = (string) ($m[1] ?? '');
      preg_match('/THE FIRST COMMENT NOW:\n<<<\n(.*?)\n>>>/s', $content, $cm);
      $comment_now = isset($cm[1]) ? (string) $cm[1] : null;
      preg_match('/PICTURES ON THE POST NOW[^\n]*\n(.*?)\n\n/s', $content, $pm);
      preg_match_all('/^\d+\. ([ag]\d+) /m', (string) ($pm[1] ?? ''), $pk);
      $pics_now = $pk[1];
      preg_match('/THE PROPERTY\'S OTHER PHOTOS[^\n]*\n(.*?)\n\n/s', $content, $om);
      preg_match_all('/^(a\d+) /m', (string) ($om[1] ?? ''), $ok);
      $GLOBALS['ias_t_claude_chat'] = ['text' => $now, 'comment' => $comment_now, 'pictures' => $pics_now, 'others' => $ok[1]];
      $said = trim(substr($content, strrpos($content, ' SAYS: ') + 7));
      $lines = explode("\n", $now);
      $tags = preg_match('/^#/', (string) end($lines)) ? "\n\n" . end($lines) : '';
      $answer = ['reply' => 'I left it as it is.', 'text' => null, 'rule' => null];
      if (stripos($said, 'shorter') !== false) $answer = ['reply' => 'Cut it down to the sale and the call.', 'text' => 'Shorter, take ' . $n . '. It is an in-person auction. Call the office.' . $tags, 'rule' => null];
      if (stripos($said, 'families') !== false) $answer = ['reply' => 'Added it, as you asked.', 'text' => $now . "\n\nPerfect for families.", 'rule' => null];
      if (stripos($said, 'never ') === 0) $answer = ['reply' => 'Taken out, and I will remember.', 'text' => 'Plain words, take ' . $n . '. It is an in-person auction.' . $tags, 'rule' => ucfirst(rtrim($said, '.')) . '.'];
      if (stripos($said, 'not json') !== false) $answer = null;
      if (stripos($said, 'comment') !== false && $comment_now !== null) { // the first comment: the lead-in changed, the links kept
        preg_match_all('~https?://\S+~', $comment_now, $urls);
        $answer = ['reply' => 'The comment now says View full listing.', 'text' => null, 'comment' => 'View full listing: ' . implode("\n", $urls[0]), 'rule' => stripos($said, 'never') !== false ? 'In comments, never say "How it sold".' : null];
      }
      if (stripos($said, 'drop the link') !== false) $answer = ['reply' => 'Done.', 'text' => null, 'comment' => 'View full listing on our website.', 'rule' => null];
      if (stripos($said, 'aerial first') !== false && $pics_now) { // pictures: the last one leads, one of the property's others comes in, alt text for the new lead
        $order = array_reverse($pics_now);
        if (!empty($ok[1])) $order[] = $ok[1][0];
        $answer = ['reply' => 'The aerial leads now, and I added one more.', 'text' => null, 'pictures' => array_merge($order, ['a99999999', $order[0]]), 'alt' => [$order[0] => 'Aerial view of the farm and its barns.', 'a99999999' => 'Not a picture of this property.'], 'rule' => null];
      }
      if (stripos($said, 'alt only') !== false && $pics_now) $answer = ['reply' => 'Better alt text on the lead.', 'text' => null, 'pictures' => null, 'alt' => [$pics_now[0] => 'The farmhouse from the lane, with the bank barn behind it.'], 'rule' => null];
      $text = $answer === null ? 'Plain words, no JSON at all.' : json_encode($answer);
    } elseif (is_string($content) && strpos($content, 'THE HOUSE RULES NOW:') !== false) { // Brand voice: Suggest updates
      $text = json_encode(['rules' => ['Never use the word nestled.', 'Lead with the place.', 'Call it perfect for families.'], 'why' => 'They keep cutting the gloss.']);
    } elseif (is_array($content)) { // photo grading
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
      // The shape asked for (0.11.0): bullets get "• " lines, the emoji-led shape one emoji a line.
      if (strpos($content, 'SHAPE: One short lead-in sentence') !== false) $lines = array_map(function ($l) { return '• ' . $l; }, $lines);
      if (strpos($content, 'SHAPE: Emoji-led') !== false) $lines = array_merge(array_map(function ($l) { return '📍 ' . $l; }, $lines), ['📅 Sale day is on the listing', '🏡 Come see it', '🌾 Good ground', '🚜 Equipment too']);
      $GLOBALS['ias_t_claude_shapes'][] = preg_match('/SHAPE: (\S+)/', $content, $sm) ? $sm[1] : '';
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
 * fb_stats (the page's posts as OneUp's analytics give them, ias_t_fb_stat()),
 * stats_code (403: a plan without analytics), comments (reply-to-post calls),
 * calls (every request: path, method, params), fail_next (refuse that many
 * schedules), no_id (answer without a post id), tz_shift (hours OneUp's clock
 * is off the site's), gbp (a Google Business Profile post on the list),
 * docs (answer exactly as docs.oneupapp.io shows, Oct 14, 2026: lists under
 * `data` with no next_start, scheduling answers "1 new Posts Scheduled." with
 * no post id, the published list carrying the network's own id "FB_…"),
 * ignore_start (every page is the first), zero_new (the next N scheduling
 * calls answer "0 new Posts Scheduled." and create nothing).
 */
function ias_t_oneup(): void {
  // Switches for the ways OneUp can let us down: fail_next (refuse the next N posts), no_id (answer
  // without a post id), tz_shift (hours the account is off the site), lose_answer (schedule the next N
  // posts but answer 502), net_error (the next N posts never arrive; the error echoes the URL, key and
  // all), delete_404 (the next N deletes answer "not found" without deleting), broken (paths that answer
  // 200 with no list in it), draft_real (drafts are scheduled as real posts), fb (the page's name), id_form
  // ('any': the account as a JSON list or social_network_id[…]; 'brackets': only the latter). A plain id is refused.
  $GLOBALS['ias_t_oneup'] = ['scheduled' => [], 'published' => [], 'drafts' => [], 'failed' => [], 'next' => 900001, 'calls' => [],
    'fail_next' => 0, 'no_id' => false, 'tz_shift' => 0, 'gbp' => true, 'lose_answer' => 0, 'net_error' => 0, 'delete_404' => 0,
    'broken' => [], 'draft_real' => false, 'fb' => 'Intel Auctions (Narvon)', 'id_form' => 'any', 'docs' => false, 'ignore_start' => false, 'zero_new' => 0,
    'fb_stats' => [], 'stats_code' => 200, 'comments' => [], 'comment_code' => 200, 'comment_lose' => 0];
  add_filter('ias_oneup_key', function () { return 'test-oneup-key'; });
  add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos((string) $url, 'oneupapp.io/api/') === false) return $pre;
    $S =& $GLOBALS['ias_t_oneup'];
    $parts = wp_parse_url((string) $url);
    parse_str((string) ($parts['query'] ?? ''), $q);
    $path = basename((string) $parts['path']);
    $body = [];
    if (is_array($args['body'] ?? null)) parse_str(http_build_query($args['body']), $body); // read as a server reads the form WordPress sends (social_network_id[0] becomes a list)
    elseif (is_string($args['body'] ?? null) && $args['body'] !== '') { $j = json_decode($args['body'], true); if (is_array($j)) $body = $j; } // a JSON body (the comment calls)
    $params = array_merge($q, $body);
    // The account as it came over the wire (`raw_sn`); the calls list keeps the one id, for the tests to read.
    $raw_sn = $params['social_network_id'] ?? null;
    if (is_string($raw_sn)) { $d = json_decode($raw_sn, true); if (is_array($d) && $d) $params['social_network_id'] = (string) array_values($d)[0]; }
    elseif (is_array($raw_sn) && $raw_sn) $params['social_network_id'] = (string) array_values($raw_sn)[0];
    $S['calls'][] = ['path' => $path, 'method' => (string) ($args['method'] ?? 'GET'), 'params' => $params, 'raw_sn' => $raw_sn];
    $json = function (int $code, $data) {
      return ['headers' => [], 'body' => (string) json_encode($data), 'response' => ['code' => $code, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    };
    if (($params['apiKey'] ?? '') !== 'test-oneup-key') return $json(401, ['error' => true, 'message' => 'Invalid API key']);
    if (in_array($path, (array) $S['broken'], true)) return $json(200, ['message' => 'Too many requests, slow down']);
    $fb = (string) $S['fb'];
    $page = function (array $rows, string $time_key) use ($params, $json) {
      $start = !empty($GLOBALS['ias_t_oneup']['ignore_start']) ? 0 : (int) ($params['start'] ?? 0);
      $rows = array_values($rows);
      if ($time_key === 'date_time' && !empty($GLOBALS['ias_t_oneup']['gbp'])) {
        $rows[] = ['post_id' => 555, 'content' => 'A Google Business Profile post', 'source_url' => 'NA', $time_key => '2026-11-10 11:05:45', 'category_name' => 'Intel', 'video_url' => 'NA', 'content_image' => 'NA', 'social_network_username' => 'Intel Auctions (5940 Plank Road  Narvon  PA  US )'];
      }
      $slice = array_slice($rows, $start, 50);
      if (!empty($GLOBALS['ias_t_oneup']['docs'])) return $json(200, ['message' => 'OK', 'error' => false, 'data' => $slice]);
      return $json(200, ['posts' => $slice, 'start' => $start, 'count' => count($slice), 'next_start' => $start + 50 < count($rows) ? $start + 50 : null]);
    };
    $host = (string) ($parts['host'] ?? '');
    if ($host === 'analyze.oneupapp.io' && $path === 'posts') {
      if ((int) $S['stats_code'] === 403) return $json(403, ['message' => 'Analytics is not available on the Basic plan. Please upgrade your plan to access this feature.']);
      return $json(200, ['success' => true, 'data' => ['stats' => [], 'posts' => array_values($S['fb_stats']), 'hashtag_performance' => []]]);
    }
    if ($host === 'feed.oneupapp.io' && $path === 'reply-to-post') {
      if ((int) $S['comment_code'] === 403) return $json(403, ['message' => 'Comment management is not available on the Basic plan.']);
      $cid = 'c' . (count($S['comments']) + 1);
      $S['comments'][] = ['post_id' => (string) ($params['post_id'] ?? ''), 'comment' => (string) ($params['comment'] ?? ''), 'social_account_id' => (string) ($params['social_account_id'] ?? ''), 'platform' => (string) ($params['platform'] ?? ''), 'comment_id' => $cid];
      if ($S['comment_lose'] > 0) { $S['comment_lose']--; return $json(502, ['message' => 'Bad gateway']); }
      return $json(200, ['message' => 'OK', 'error' => false, 'data' => ['comment_id' => $cid, 'post_id' => (string) ($params['post_id'] ?? ''), 'platform' => 'facebook']]);
    }
    switch ($path) {
      case 'listcategory':
        return $json(200, ['categories' => [['id' => 42, 'category_name' => 'Intel', 'isPaused' => 0]]]);
      case 'listcategoryaccount':
        return $json(200, ['accounts' => [
          ['category_id' => 42, 'social_network_name' => 'Intel Auctions (5940 Plank Road  Narvon  PA  US )', 'social_network_id' => 'accounts/1/locations/2', 'social_network_type' => 'GBP'],
          ['category_id' => 42, 'social_network_name' => $fb, 'social_network_id' => 'fb_123', 'social_network_type' => 'Facebook'],
        ]]);
      case 'scheduletextpost': case 'scheduleimagepost': case 'schedulevideopost':
        // Like the real account (Oct 13, 2026): the account must be a list. A JSON list is read as one, unless
        // the switch says this OneUp takes only social_network_id[…] (then the JSON text is refused the same way).
        $sn = $raw_sn;
        if (is_string($sn) && $S['id_form'] !== 'brackets') { $d = json_decode($sn, true); if (is_array($d)) $sn = $d; }
        if (!is_array($sn) || !$sn) return $json(200, ['error' => true, 'message' => 'Social Network ID must be an array.']);
        if ($S['net_error'] > 0) { $S['net_error']--; return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds for ' . $url); }
        if ($S['fail_next'] > 0) { $S['fail_next']--; return $json(200, ['error' => true, 'message' => 'The Facebook token has expired']); }
        if ($S['zero_new'] > 0) { $S['zero_new']--; return $json(200, ['message' => '0 new Posts Scheduled.', 'error' => false, 'data' => []]); }
        $id = $S['next']++;
        $row = ['post_id' => $id, 'content' => (string) ($params['content'] ?? ''), 'source_url' => 'NA',
          'date_time' => date('Y-m-d H:i:s', strtotime((string) $params['scheduled_date_time']) + 3600 * (int) $S['tz_shift']),
          'category_name' => 'Intel', 'video_url' => (string) ($params['video_url'] ?? 'NA'), 'content_image' => (string) ($params['image_url'] ?? 'NA'),
          'social_network_username' => ['fb_123' => $fb, 'accounts/1/locations/2' => 'Intel Auctions (5940 Plank Road  Narvon  PA  US )'][(string) ($params['social_network_id'] ?? '')] ?? 'someone else', 'first_comment' => (string) ($params['first_comment'] ?? ''),
          'google_post_type' => (string) ($params['google_post_type'] ?? ''), 'cta_button_url' => (string) ($params['cta_button_url'] ?? '')];
        if (($params['isDraftPost'] ?? '') === 'true' && !$S['draft_real']) $S['drafts'][$id] = $row; else $S['scheduled'][$id] = $row;
        if ($S['lose_answer'] > 0) { $S['lose_answer']--; return $json(502, ['message' => 'Bad gateway']); }
        if ($S['docs']) return $json(200, ['message' => '1 new Posts Scheduled.', 'error' => false, 'data' => []]);
        return $json(200, $S['no_id'] ? ['error' => false, 'message' => 'Post scheduled successfully'] : ['error' => false, 'message' => 'Post scheduled successfully', 'data' => ['post_id' => $id]]);
      case 'deletepost': // the address the plugin once guessed: OneUp answers it with its 404 web page
        return ['headers' => [], 'body' => '<!doctype html><title>Page Not Found</title><h1>Page Not Found</h1><p>Sorry, the page you are looking for could not be found.</p>', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => [], 'filename' => null];
      case 'deletescheduledpost':
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
  if (!empty($S['docs'])) $row['post_id'] = ($row['social_network_username'] === $S['fb'] ? 'FB_1336677789520495_' : 'GBP_') . $id; // the network's own id, as the docs show
  $S['published'][$id] = $row;
}

/**
 * OneUp's analytics have numbers for a published post (by its OneUp id): its
 * row as GET analyze.oneupapp.io/api/facebook/posts gives it — Facebook's id
 * "<page>_<oneup id>", the words, the time, the stats. $over changes fields.
 */
function ias_t_fb_stat(int $id, array $stats, array $over = []): void {
  $S =& $GLOBALS['ias_t_oneup'];
  $row = $S['published'][$id];
  $S['fb_stats'][$id] = $over + ['id' => '1336677789520495_' . $id, 'page_id' => '1336677789520495', 'format' => 'image', 'message' => (string) $row['content'],
    'permalink_url' => 'https://facebook.com/1336677789520495/posts/' . $id, 'full_picture' => 'https://scontent.example/x.jpg',
    'stats' => $stats + ['shares' => 0, 'comments' => 0, 'clicks' => 0, 'reactions' => 0, 'media_views' => 0],
    'created_time' => (new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP')];
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
