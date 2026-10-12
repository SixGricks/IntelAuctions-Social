<?php
/**
 * Phase 2 acceptance: "Every current listing shows a full, readable set of
 * drafts on the calendar." Real listings, real photos with their WordPress
 * sizes, a lead from the team record, and Claude answered offline
 * (ias_t_claude) — then every post type is drafted, checked and drawn.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // a Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30]);

$jacob = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$photos = [];
foreach (['aerial', 'house', 'kitchen', 'bank barn', 'fields', 'living room', 'porch', 'drone 2'] as $i => $label) {
  $photos[] = ias_t_photo('Farm ' . $label, [60 + $i * 20, 110, 70 + $i * 10]);
}

// A farm promoted today: sale in 3 weeks, an open house in 12 days.
$farm = ias_t_listing(['title' => '5940 Plank Road Farm', 'address' => '5940 Plank Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-21 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000',
  'size' => '52 +/- acre farm', 'lead' => $jacob, 'gallery' => $photos, 'featured' => $photos[0], 'oh' => [['2026-11-14 10:00', 2]], 'sellers' => 'The Esh family']);

// Three same-day phone-bid sales in Northumberland County; the second's draft slips in a fair-housing phrase.
$twins = [];
foreach (['101 Market St', '103 Market St', '105 Market St'] as $i => $street) {
  $twins[] = ias_t_listing(['title' => $street, 'address' => $street . ', Sunbury, PA 17801, USA', 'town' => 'Sunbury', 'county' => 'Northumberland',
    'stage' => 'upcoming', 'sale' => '2026-11-18 ' . (10 + $i) . ':00', 'terms' => [$T['intel'], $T['phone']], 'auction_type' => 'Phone Bid', 'bid' => '$60,000',
    'gallery' => [ias_t_photo('Twin ' . $i . ' front'), ias_t_photo('Twin ' . $i . ' back')]]);
}
$GLOBALS['ias_t_claude_bad'] = '103 Market St';

// Sold last Saturday with a price; a contents sale the same day.
$sold = ias_t_listing(['title' => '22 Mill Road', 'address' => '22 Mill Road, Paradise, PA 17562, USA', 'town' => 'Paradise', 'county' => 'Lancaster',
  'sale' => '2026-10-31 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$200,000', 'price' => '425000',
  'featured' => ias_t_photo('Mill Road front', [120, 90, 60])]);
ia_stage_set($sold, 'past');
$contents = ias_t_listing(['title' => 'Mill Road Contents', 'town' => 'Paradise', 'county' => 'Lancaster', 'sale' => '2026-10-31 13:00',
  'terms' => [$T['intel'], $T['in_person']], 'auction_type' => 'Live', 'featured' => ias_t_photo('Contents', [70, 70, 90])]);
ia_stage_set($contents, 'past');

// Announced last Friday and this Monday: this Friday's roundup. One has a brochure cover.
add_filter('ias_now', function () { return new DateTimeImmutable('2026-10-30 09:00', ia_wp_tz()); }, 20);
$new1 = ias_t_listing(['title' => '7 Orchard Lane', 'address' => '7 Orchard Lane, Manheim, PA 17545, USA', 'town' => 'Manheim', 'county' => 'Lancaster', 'stage' => 'announced', 'sale' => '2026-12-12 10:00', 'terms' => [$T['farm']]]);
remove_all_filters('ias_now');
ias_t_clock('2026-11-02 05:30');
$new2 = ias_t_listing(['title' => '400 Ridge Road', 'address' => '400 Ridge Road, Lock Haven, PA 17745, USA', 'town' => 'Lock Haven', 'county' => 'Clinton', 'stage' => 'announced', 'terms' => [$T['invest']]]);
update_post_meta($new1, '_ias_brochure_cover', ias_t_photo('Orchard brochure cover', [35, 58, 102]));

t_eq('2026-10-30', get_post_meta($new1, '_ias_announced_at', true), 'announcing a listing notes the day (the roundup reads it)');
t_eq('2026-11-02', get_post_meta($farm, '_ias_promoted_at', true), 'promoting a listing notes the day (the Listing window starts there)');

// ---- The run ----
$r = ias_job_run();
t_ok(empty($r['errors']), 'the run had no errors: ' . wp_json_encode($r['errors'] ?? []));

$by_slot = [];
foreach (ias_posts_query(['limit' => 200]) as $p) $by_slot[$p['slot_key']] = $p;
$want = ['listing:' . $farm, 'open_house:' . $farm . ':20261114', 'auction_week:2026-11-21', 'auction_week:2026-11-18', 'results:2026-10-31', 'roundup:2026-11-06'];
foreach ($want as $slot) t_ok(isset($by_slot[$slot]), "post {$slot} is planned");
foreach ($twins as $id) t_ok(isset($by_slot['listing:' . $id]), 'each twin has its own Listing post');
t_ok(!isset($by_slot['listing:' . $new1]), 'no individual post for a Recently Announced listing');

// Every planned post has a draft (Phase 2's acceptance).
$missing = [];
foreach ($by_slot as $slot => $p) if (in_array($p['status'], ['planned', 'held'], true) && empty($p['content']['text']) && $p['type'] !== 'roundup') $missing[] = $slot;
t_eq([], $missing, 'every planned post has a draft (the roundup waits for its Friday)');

$lp = $by_slot['listing:' . $farm];
$text = (string) $lp['content']['text'];
t_ok(stripos($text, 'in-person auction') !== false && stripos($text, 'phone bid') === false, 'the farm\'s Listing post says in-person, and only that');
t_ok(strpos($text, '#IntelAuctions') !== false && count($lp['content']['hashtags']) === 3, 'three hashtags, #IntelAuctions first');
t_ok(strpos($text, 'http') === false, 'no link in the post text');
t_ok(strpos((string) $lp['content']['first_comment'], 'utm_campaign=social_listing') !== false && strpos((string) $lp['content']['first_comment'], 'utm_content=p' . $lp['id']) !== false, 'the link in the first comment is tagged with the post type and post');
t_eq('planned', $lp['status'], 'a clean draft stays Planned');
$imgs = (array) $lp['content']['media']['images'];
t_ok(count($imgs) >= 4 && count($imgs) <= 10, 'the Listing post carries up to 10 photos, leaving some for later posts (' . count($imgs) . ' of 8)');
t_ok(!empty($imgs[0]['crops']['landscape']) && !empty($imgs[0]['crops']['square']) && !empty($imgs[0]['crops']['vertical']), 'every chosen photo is saved in three crops');
$crop = wp_upload_dir()['basedir'] . '/intel-social/crops/' . (int) $imgs[0]['id'] . '-vertical.jpg';
$sz = (array) @getimagesize($crop);
t_ok(!empty($sz[0]) && abs($sz[0] / $sz[1] - 0.8) < 0.01 && $sz[1] <= 1350, 'the vertical crop is 4:5 (1080×1350, never upscaled past the photo)');
t_ok(strpos((string) $imgs[0]['alt'], 'Test photo') === 0, 'photos carry the alt text from grading');
t_ok(is_array(get_post_meta($photos[0], '_ias_photo', true)), 'grades are stored on the attachment');

$oh = $by_slot['open_house:' . $farm . ':20261114'];
$oh_imgs = array_column((array) $oh['content']['media']['images'], 'id');
t_ok(!array_intersect($oh_imgs, (array) $lp['images_used']), 'the Open House post uses none of the Listing post\'s photos');
t_ok(count($oh_imgs) >= 3 && count($oh_imgs) <= 5, 'the Open House post has 3–5 photos');
$aw = $by_slot['auction_week:2026-11-21'];
t_ok(!in_array($aw['content']['media']['images'][0]['id'], array_merge($lp['images_used'], $oh['images_used']), true), 'Auction Week leads with a photo this property has not used');

$tw = $by_slot['auction_week:2026-11-18'];
t_eq($twins, $tw['listing_ids'], 'the three same-day sales share one Auction Week post');
t_ok(substr_count((string) $tw['content']['text'], 'Intel Phone Bidding') === 3, 'the shared post states phone bidding for each sale');
$bad = $by_slot['listing:' . $twins[1]];
t_eq('held', $bad['status'], 'a draft with "perfect for families" is held for a decision');
t_ok((bool) array_filter((array) $bad['flags']['holds'], function ($h) { return strpos($h, 'Fair housing') === 0; }), '…and says why');

$res = $by_slot['results:2026-10-31'];
t_eq([$sold, $contents], $res['listing_ids'], 'the sale and the contents sale share a Results post');
t_eq('A', $res['lane'], 'Results is Lane A');
$g = $res['content']['media']['images'][0] ?? [];
t_eq('graphic', $g['kind'] ?? '', 'Results carries the price graphic');
$gfile = wp_upload_dir()['basedir'] . '/intel-social/graphics/' . basename((string) ($g['url'] ?? 'x'));
t_eq([1080, 1080], array_slice((array) @getimagesize($gfile), 0, 2), 'the graphic is 1080×1080');
t_ok(strpos((string) $g['alt'], '$425,000') !== false, 'its alt text gives the price');
t_ok(strpos((string) $res['content']['text'], '$425,000') !== false, 'the post gives the sale price');

$ru = $by_slot['roundup:2026-11-06'];
t_eq([$new1, $new2], $ru['listing_ids'], 'the Friday roundup names both of this week\'s announcements');
t_ok(empty($ru['content']['text']), 'the roundup is not written before its Friday');

// ---- Friday: the roundup is written, with its collage ----
ias_t_clock('2026-11-06 05:10');
ias_drafts_run(10);
$ru = ias_post_by_slot('roundup:2026-11-06');
t_ok(!empty($ru['content']['text']), 'on Friday the roundup is written');
$cg = $ru['content']['media']['images'][0] ?? [];
t_ok(($cg['kind'] ?? '') === 'graphic' && is_readable(wp_upload_dir()['basedir'] . '/intel-social/graphics/' . basename((string) $cg['url'])), 'with the collage of brochure covers');
t_ok(strpos((string) $ru['content']['first_comment'], 'recently-announced') !== false || strpos((string) $ru['content']['first_comment'], 'cat=') !== false, 'the roundup links to the Recently Announced page');
ias_t_clock('2026-11-02 05:30');

// ---- No repeats ----
$openers = []; $ctas = [];
foreach (ias_posts_query(['limit' => 200]) as $p) {
  if (empty($p['content']['text'])) continue;
  $openers[] = ias_history_norm((string) $p['content']['opener']);
  $ctas[] = ias_history_norm((string) $p['content']['cta']);
  $sets[] = implode(' ', array_map('strtolower', (array) $p['content']['hashtags']));
}
t_eq(count($openers), count(array_unique($openers)), 'no two posts open the same way');
t_eq(count($ctas), count(array_unique($ctas)), 'no two posts share a call to action');
t_eq(count($sets), count(array_unique($sets)), 'no two posts share a hashtag set');

// ---- Paying twice is guarded ----
$calls = ias_t_claude_calls();
ias_drafts_run(30);
t_eq($calls, ias_t_claude_calls(), 'a second run pays for nothing when nothing changed');
update_post_meta($farm, 'Starting_Bid', '$325,000');
ias_sync();
$lp2 = ias_post_by_slot('listing:' . $farm);
t_ok(!empty($lp2['flags']['stale']), 'changing the starting bid marks the draft stale');
global $wpdb;
$wpdb->update(ias_table('posts'), ['generated_at' => '2026-01-01 00:00:00'], ['id' => $lp2['id']]); // as if written hours ago
ias_drafts_run(30);
$lp3 = ias_post_by_slot('listing:' . $farm);
t_ok(empty($lp3['flags']['stale']) && strpos((string) $lp3['content']['text'], '$325,000') !== false, 'a stale draft is rewritten with the new figure');

// ---- What a person can do ----
$_POST = ['ias_cal_action' => 'edit', 'ias_post' => $lp3['id'], 'ias_text' => "New words. This is an in-person auction.\n\nPerfect for families too."];
do_action('ias_calendar_action', 'edit');
$e = ias_post_get((int) $lp3['id']);
t_eq('held', $e['status'], 'an edit that adds a fair-housing phrase is held');
$_POST = ['ias_cal_action' => 'edit', 'ias_post' => $lp3['id'], 'ias_text' => "New words. This is an in-person auction."];
do_action('ias_calendar_action', 'edit');
$e = ias_post_get((int) $lp3['id']);
t_ok($e['status'] === 'planned' && $e['flags']['edited_by'] === 'admin', 'a clean edit goes back to Planned, noting who edited it');
update_post_meta($farm, 'Starting_Bid', '$330,000');
ias_sync();
$wpdb->update(ias_table('posts'), ['generated_at' => '2026-01-01 00:00:00'], ['id' => $lp3['id']]);
$before = ias_t_claude_calls();
ias_drafts_run(30);
t_eq($before, ias_t_claude_calls(), 'a draft a person edited is never rewritten behind their back');
t_ok(!empty(ias_post_get((int) $lp3['id'])['flags']['stale']), '…it is flagged for them instead');

$_POST = ['ias_cal_action' => 'skip', 'ias_post' => $oh['id'], 'ias_why' => 'test'];
do_action('ias_calendar_action', 'skip');
t_eq('skipped', ias_post_get((int) $oh['id'])['status'], 'Skip skips');
ias_sync();
t_eq('skipped', ias_post_get((int) $oh['id'])['status'], 'the sync never brings back a post a person skipped');
$_POST = ['ias_cal_action' => 'restore', 'ias_post' => $oh['id']];
do_action('ias_calendar_action', 'restore');
t_eq('planned', ias_post_get((int) $oh['id'])['status'], 'Restore brings it back');

$_POST = ['ias_cal_action' => 'release', 'ias_post' => $bad['id']];
do_action('ias_calendar_action', 'release');
t_eq('planned', ias_post_get((int) $bad['id'])['status'], 'Keep as written releases a held draft');

// ---- Unpublishing cancels, republishing brings back ----
wp_update_post(['ID' => $twins[2], 'post_status' => 'draft']);
ias_sync();
t_eq('cancelled', ias_post_by_slot('listing:' . $twins[2])['status'], 'unpublishing a listing cancels its posts');
t_eq([$twins[0], $twins[1]], ias_post_by_slot('auction_week:2026-11-18')['listing_ids'], '…and takes it out of the shared post');
wp_update_post(['ID' => $twins[2], 'post_status' => 'publish']);
ias_sync();
t_eq('planned', ias_post_by_slot('listing:' . $twins[2])['status'], 'republishing brings its posts back');

// ---- The screens ----
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$_GET = ['page' => IAS_PAGE_CALENDAR];
ob_start(); ias_calendar_render(); $html = ob_get_clean();
t_ok(substr_count($html, 'class="ias-chip') >= 6, 'the calendar shows the posts');
t_ok(strpos($html, 'Needs a decision') !== false, 'and the list of posts needing a decision');
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $res['id']];
ob_start(); ias_calendar_render(); $html = ob_get_clean();
t_ok(strpos($html, '$425,000') !== false && strpos($html, 'First comment') !== false, 'a post\'s view shows its text and first comment');
$_GET = [];
ob_start(); ias_listing_panel_render(get_post($farm)); $html = ob_get_clean();
t_ok(strpos($html, 'Listing') !== false && strpos($html, 'Open house') !== false, 'the listing panel lists the property\'s posts');
restore_error_handler();
t_eq([], $errors, 'no PHP notices on the screens');

$_POST = [];
ias_t_claude_off();
