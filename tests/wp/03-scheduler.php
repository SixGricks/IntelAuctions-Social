<?php
/**
 * Phase 3 inside WordPress: the morning run places every post at a time that
 * keeps the rules, the dry run logs exactly what would go to OneUp (once per
 * change), a listing that changes is re-planned, and unpublishing a listing
 * cancels its posts before the request ends. (The busy-November acceptance
 * test is tests/unit/november.php.)
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday, after the 5:00 planning hour
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123']);

$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$gal = [];
foreach (range(1, 8) as $i) $gal[] = ias_t_photo('Plank ' . $i, [50 + $i * 15, 100, 80]);
$farm = ias_t_listing(['title' => '5940 Plank Road', 'address' => '5940 Plank Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-21 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$650,000',
  'lead' => $lead, 'gallery' => $gal, 'featured' => $gal[0], 'oh' => [['2026-11-14 13:00', 2]]]);
$ridge = ias_t_listing(['title' => '400 Ridge Road', 'address' => '400 Ridge Road, Lock Haven, PA 17745, USA', 'town' => 'Lock Haven', 'county' => 'Clinton',
  'stage' => 'upcoming', 'sale' => '2026-11-13 11:00', 'terms' => [$T['invest'], $T['phone']], 'auction_type' => 'Phone Bid', 'bid' => '$220,000',
  'gallery' => [ias_t_photo('Ridge 1'), ias_t_photo('Ridge 2'), ias_t_photo('Ridge 3'), ias_t_photo('Ridge 4')], 'oh' => [['2026-11-07 10:00', 3]]]);
$mill = ias_t_listing(['title' => '18 Mill Road', 'address' => '18 Mill Road, Paradise, PA 17562, USA', 'town' => 'Paradise', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-11 17:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$425,000',
  'gallery' => [ias_t_photo('Mill 1'), ias_t_photo('Mill 2'), ias_t_photo('Mill 3')]]);

$r = ias_job_run();
t_ok(isset($r['plan']), 'the first run after 5:00 plans');
t_eq(ias_now()->format('Y-m-d'), get_option('ias_last_plan'), 'and notes the day');

$posts = ias_posts_query(['status' => ['planned', 'held', 'approved', 'waiting'], 'limit' => 200]);
$floor = ias_now()->modify('+48 hours')->format('Y-m-d H:i:s');
$by_day = [];
$unscheduled = [];
foreach ($posts as $p) {
  if (empty($p['scheduled_at'])) { $unscheduled[] = $p['slot_key']; continue; }
  if ($p['lane'] === 'B') t_ok($p['scheduled_at'] >= $floor, "{$p['slot_key']} is outside the 48-hour veto window ({$p['scheduled_at']})");
  $by_day[substr($p['scheduled_at'], 0, 10)][] = $p;
}
t_eq([], $unscheduled, 'every post in the plan has a time');
foreach ($by_day as $d => $list) {
  t_ok(count($list) <= 3, "{$d}: at most three posts");
  t_ok(ias_day_dow($d) !== 7, "{$d}: not a Sunday");
  $mins = array_map(function ($p) { return ias_hm_to_min(substr($p['scheduled_at'], 11, 5)); }, $list);
  sort($mins);
  for ($i = 1; $i < count($mins); $i++) t_ok($mins[$i] - $mins[$i - 1] >= 180, "{$d}: posts at least 3 hours apart");
  foreach ($mins as $m) t_ok($m % 60 !== 0, "{$d}: nothing on the hour");
  $counties = array_filter(array_map(function ($p) { return $p['county']; }, $list));
  t_eq(count($counties), count(array_unique($counties)), "{$d}: no two posts about the same county");
}
$lp = ias_post_by_slot('listing:' . $farm);
$oh = ias_post_by_slot('open_house:' . $farm . ':20261114');
t_ok($lp['scheduled_at'] < $oh['scheduled_at'], 'the farm\'s Open House post comes after its Listing post');

// The dry run: every ready post in the next 21 days logged once, exactly as it would be sent.
$logs = ias_log_recent('dryrun', 200);
t_ok(count($logs) >= 3, 'the dry run logged the payloads (' . count($logs) . ')');
$farm_log = null;
foreach ($logs as $l) if ((int) ($l['meta']['post'] ?? 0) === (int) $lp['id']) $farm_log = $l;
t_ok($farm_log !== null, 'the farm\'s Listing post is in the dry-run log');
$pl = (array) ($farm_log['meta']['payload'] ?? []);
t_eq('https://www.oneupapp.io/api/scheduleimagepost', $pl['url'] ?? '', 'an image post to OneUp\'s scheduleimagepost');
t_eq(substr((string) $lp['scheduled_at'], 0, 16), $pl['params']['scheduled_date_time'] ?? '', 'at the planned time');
t_eq(['42', 'fb_123'], [$pl['params']['category_id'] ?? '', $pl['params']['social_network_id'] ?? ''], 'to the category and Facebook account from Settings');
t_eq((string) $lp['content']['text'], $pl['params']['content'] ?? '', 'with the post\'s text');
t_ok(strpos((string) ($pl['params']['image_url'] ?? ''), '~~') !== false, 'its photos joined with ~~');
t_ok(strpos((string) ($pl['params']['first_comment'] ?? ''), 'utm_campaign=social_listing') !== false, 'and the tagged link as the first comment');
t_ok(!isset($pl['params']['apiKey']) && strpos(wp_json_encode($pl), 'apiKey') === false, 'the key is never in the log');
t_ok(strpos((string) $farm_log['meta']['summary'], 'the master switch is off') !== false, 'the log says why it was not sent');
$res_logged = array_filter($logs, function ($l) { return ($l['meta']['type'] ?? '') === 'results'; });
t_eq(0, count($res_logged), 'a Results post waiting for approval is never pushed');

$n = count(ias_log_recent('dryrun', 200));
ias_push_run();
t_eq($n, count(ias_log_recent('dryrun', 200)), 'nothing changed: nothing logged again');
$r = ias_job_run();
t_ok(!isset($r['plan']), 'the plan runs once a morning');

// The open house moves: the old post goes (a delete is logged), the new one is planned.
update_post_meta($farm, 'open_house', '2026-11-15 13:00:00');
$_POST = ['ias_cal_action' => 'plan_now'];
do_action('ias_calendar_action', 'plan_now');
$_POST = [];
$old = ias_post_get((int) $oh['id']);
t_eq('cancelled', $old['status'], 'the old open house\'s post is cancelled');
$deleted = array_filter(ias_log_recent('dryrun', 50), function ($l) use ($oh) { return (int) ($l['meta']['post'] ?? 0) === (int) $oh['id'] && ($l['meta']['payload']['action'] ?? '') === 'delete'; });
t_eq(1, count($deleted), 'and the dry run logs taking it back off OneUp');
$new = ias_post_by_slot('open_house:' . $farm . ':20261115');
t_ok($new && !empty($new['scheduled_at']), 'the new open house gets a post with a time');

// Unpublishing cancels at once, before the request ends.
$ridge_lp = ias_post_by_slot('listing:' . $ridge);
wp_update_post(['ID' => $ridge, 'post_status' => 'draft']);
do_action('shutdown');
t_eq('cancelled', ias_post_get((int) $ridge_lp['id'])['status'], 'unpublishing a listing cancels its posts at once');
t_ok(strpos((string) ias_post_get((int) $ridge_lp['id'])['note'], 'unpublished') !== false, '…saying why');
wp_update_post(['ID' => $ridge, 'post_status' => 'publish']);
do_action('shutdown');
t_eq('planned', ias_post_get((int) $ridge_lp['id'])['status'], 'republishing brings them back at once');

// Cancel posts on the listing panel.
update_post_meta($mill, '_ias_posts_cancelled', '2026-11-02 admin');
ias_sync();
$mp = ias_post_by_slot('listing:' . $mill);
t_eq('cancelled', $mp['status'], 'Cancel posts cancels the listing\'s posts');
t_ok(strpos((string) $mp['note'], 'Cancel posts') !== false, '…and says so');
delete_post_meta($mill, '_ias_posts_cancelled');
ias_sync();
t_eq('planned', ias_post_by_slot('listing:' . $mill)['status'], 'Resume posts brings them back');
ob_start(); ias_listing_panel_render(get_post($mill)); $html = ob_get_clean();
t_ok(strpos($html, 'Cancel posts') !== false, 'the listing panel offers Cancel posts');

// The screens.
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$_GET = ['page' => IAS_PAGE_CALENDAR];
ob_start(); ias_calendar_render(); $html = ob_get_clean();
t_ok(strpos($html, 'Plan now') !== false && strpos($html, 'Last planned') !== false, 'the calendar has Plan now and the last plan');
t_ok(strpos($html, 'Dropped in the last 30 days') !== false, '…and the dropped list');
// The open house moved, so the farm's Listing draft is stale: it is rewritten before it can be pushed.
t_eq('the listing changed and the draft has not been rewritten yet', ias_push_ready(ias_post_get((int) $lp['id'])), 'a stale draft is never pushed');
$wpdb->update(ias_table('posts'), ['generated_at' => '2026-11-02 03:00:00'], ['id' => $lp['id']]);
ias_drafts_run(30);
ias_plan_run();
t_eq('', ias_push_ready(ias_post_get((int) $lp['id'])), '…and is ready again once rewritten');
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $lp['id']];
ob_start(); ias_calendar_render(); $html = ob_get_clean();
t_ok(strpos($html, 'What would go to OneUp') !== false && strpos($html, 'scheduled_date_time') !== false, 'a post shows what would go to OneUp');
$_GET = ['page' => IAS_PAGE_SETTINGS];
ob_start(); ias_settings_render(); $html = ob_get_clean();
t_ok(strpos($html, 'Check connection') !== false, 'Settings offers the OneUp connection check');
$_GET = [];
restore_error_handler();
t_eq([], $errors, 'no PHP notices on the screens');

ias_t_claude_off();

// The toolkit contract: Facebook stays the webhook's until Listing posts can really reach OneUp.
ias_settings_update(['master' => true, 'dry_run' => false, 'types' => ['listing' => true]]);
t_ok(!ia_social_facebook_managed(), 'every switch on but no OneUp key: Facebook stays the toolkit webhook\'s');
t_eq(['gbp', 'facebook'], ia_social_channels(), '…so the webhook still carries Facebook');
add_filter('ias_oneup_key', function () { return 'test-oneup-key'; });
t_ok(!ia_social_facebook_managed(), 'the key and the ids but Verify OneUp not passed: Facebook stays the webhook\'s');
update_option('ias_oneup_verify', ['ok' => true, 'tz_offset' => 0, 'category' => '42', 'account' => 'fb_123'], false);
t_ok(ia_social_facebook_managed(), 'with the key, the ids and Verify OneUp passed, the toolkit hands Facebook over');
t_eq(['gbp'], ia_social_channels(), '…and the webhook carries Google Business Profile only');
ias_settings_update(['paused' => true]);
t_ok(ia_social_facebook_managed(), 'paused: Facebook stays with this plugin (a pause must not let the webhook post what the plugin posts on resume)');
ias_settings_update(['paused' => false]);
ias_settings_update(['types' => ['listing' => false]]);
t_ok(!ia_social_facebook_managed(), 'Listing posts switched off: Facebook goes back to the webhook');
ias_settings_update(['types' => ['listing' => true]]);
add_filter('ias_live_pushing_built', '__return_false');
t_ok(!ia_social_facebook_managed(), 'a site can keep Facebook with the webhook through ias_live_pushing_built');
remove_all_filters('ias_live_pushing_built');
remove_all_filters('ias_oneup_key');
delete_option('ias_oneup_verify');
ias_settings_update(['master' => false, 'dry_run' => true, 'types' => ['listing' => false]]);
