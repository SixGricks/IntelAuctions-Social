<?php
/**
 * Phase 5 acceptance, against a fake OneUp shaped like the real account
 * (tests/fixtures: ias_t_oneup). The spec's test: "One week of Listing posts
 * publishes as planned, and a human post in OneUp moves the schedule around
 * it." Plus: the master switch off calls nothing; the dry run only reads;
 * Verify OneUp checks every call with a draft and catches a time-zone
 * mismatch; going live sends only the types switched on and never the key to
 * the log; a changed post is replaced; a person's move or edit in OneUp is
 * adopted and locked, their delete respected, their own posts worked around,
 * and the Google Business Profile account ignored; a cancelled listing's post
 * comes off at once, even paused; Pause stops new posts; a refused post is
 * retried and then stops for a person; a post OneUp could not publish stops
 * for a decision; a fact that changes after a post went out gets a drafted
 * correction; the monthly summary counts posts and the visits they brought.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_t_oneup();
ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123']);
$S =& $GLOBALS['ias_t_oneup'];
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$mk = function (string $title, string $town, string $county, string $sale) use ($T, $lead) {
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing(['title' => $title, 'address' => $title . ', ' . $town . ', PA 17555, USA', 'town' => $town, 'county' => $county,
    'stage' => 'upcoming', 'sale' => $sale, 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
};
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$writes = function (int $from) {
  return array_values(array_filter(ias_t_oneup_calls($from), function ($c) { return in_array($c['path'], ['scheduletextpost', 'scheduleimagepost', 'schedulevideopost', 'deletepost'], true); }));
};
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $_POST = [];
};
$plan = function () { ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run(); };

$a = $mk('12 Alpha Road', 'Narvon', 'Lancaster', '2026-11-28 10:00');

// ---- 1. The master switch off: OneUp is never called ----
ias_job_run();
t_eq([], ias_t_oneup_calls(), 'master switch off: not one call to OneUp, not even a read');
t_ok(!empty($lp($a)['flags']['dry_hash']), 'the dry run logged the Listing post');

// ---- 2. Master on, dry run on: reads only ----
ias_settings_update(['master' => true]);
$n = count($S['calls']);
ias_push_run();
$rb = ias_readback_run();
t_ok($rb['ok'], 'with the master switch on the read-back runs' . ($rb['error'] !== '' ? ' (' . $rb['error'] . ')' : ''));
t_eq([], $writes($n), 'dry run on: nothing is written to OneUp');
t_eq('Intel Auctions (Narvon)', (string) ias_setting('oneup_account_name'), 'the page\'s name in OneUp is looked up once and kept');
t_eq(0, count(ias_posts_query(['status' => 'human'])), 'the Google Business Profile post in the same category is not taken for a Facebook post');

// ---- 3. Verify OneUp ----
$n = count($S['calls']);
$v = ias_oneup_verify('admin');
$bad = array_filter($v['steps'], function ($s) { return $s['ok'] !== true; });
t_ok($v['ok'], 'Verify OneUp passes every step' . ($bad ? ': ' . implode(' | ', array_map(function ($s) { return $s['label'] . ' — ' . $s['detail']; }, $bad)) : ''));
t_eq(0, $v['tz_offset'], '…the time zones match');
t_eq([], $S['drafts'], '…and its test draft is gone');
$drafted = ias_t_oneup_calls($n, 'scheduletextpost');
t_ok($drafted && ($drafted[0]['params']['isDraftPost'] ?? '') === 'true', '…it was saved as a draft, which never publishes');
$S['tz_shift'] = 1;
$v = ias_oneup_verify('admin');
t_ok(!$v['ok'] && $v['tz_offset'] === 60, 'a OneUp account an hour off the site\'s time zone fails Verify, saying so');
$S['tz_shift'] = 0;
ias_oneup_verify('admin');

// ---- 4. Going live ----
ias_settings_update(['dry_run' => false, 'types' => ['listing' => true]]);
ias_push_run();
$p = $lp($a);
t_ok(empty($p['flags']['dry_hash']), 'going live clears the dry run\'s record');
$sent = ias_post_sent($p);
t_ok($sent['mode'] === 'live' && isset($S['scheduled'][(int) $sent['id']]), 'the Listing post is scheduled on OneUp (#' . $sent['id'] . ')');
$row = $S['scheduled'][(int) $sent['id']] ?? [];
t_eq(substr((string) $p['scheduled_at'], 0, 16), substr((string) ($row['date_time'] ?? ''), 0, 16), '…at its planned time');
t_eq((string) $p['content']['text'], (string) ($row['content'] ?? ''), '…with its text');
t_ok(strpos((string) ($row['first_comment'] ?? ''), 'utm_campaign=social_listing') !== false, '…and the tagged link as its first comment');
t_ok(substr_count((string) ($row['content_image'] ?? ''), '~~') >= 1, '…and its photos');
t_eq('pushed', ias_post_shown_status($p), 'the calendar shows it as Pushed');
t_eq($sent['id'], (string) $p['oneup_post_id'], '…with its OneUp id');
t_eq(ias_now()->format('Y-m-d'), (string) get_option('ias_live_since'), 'the day Listing posts went live is noted');
t_ok(ia_social_facebook_managed(), 'the toolkit now hands Facebook over to this plugin');
$others = array_filter(ias_posts_query(['type' => ['open_house', 'auction_week'], 'limit' => 50]), function ($x) { return ias_post_sent($x)['mode'] === 'live'; });
t_eq([], array_values($others), 'post types still off are not sent');
t_ok(strpos((string) wp_json_encode(ias_log_recent('', 500)), 'test-oneup-key') === false, 'the key is never in the log');
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n), 'nothing changed: nothing sent again');

// ---- 5. A changed post is replaced: deleted, then scheduled again ----
$old = $sent['id'];
$act('edit', ['ias_post' => $p['id'], 'ias_text' => "12 Alpha Road in Narvon sells November 28. It is an in-person auction.\n\nCall Jacob at 717-555-0101."]);
ias_push_run();
$p = $lp($a);
$new = ias_post_sent($p)['id'];
t_ok($new !== $old && !isset($S['scheduled'][(int) $old]) && isset($S['scheduled'][(int) $new]), 'an edited post is taken off OneUp and scheduled again (OneUp cannot change a scheduled post\'s time)');
t_ok(strpos((string) ($S['scheduled'][(int) $new]['content'] ?? ''), 'Call Jacob') !== false, '…with the new words');

// ---- 6. A person moves it in OneUp: adopted and locked ----
$moved_to = date('Y-m-d', strtotime((string) $p['scheduled_at'] . ' +1 day')) . ' 12:40:00';
$S['scheduled'][(int) $new]['date_time'] = $moved_to;
ias_readback_run();
$p = $lp($a);
t_eq($moved_to, (string) $p['scheduled_at'], 'a post a person moved in OneUp takes their time');
t_ok((int) $p['locked'] === 1 && strpos((string) $p['note'], 'Moved in OneUp') === 0, '…and is locked');
$n = count($S['calls']);
ias_plan_run();
ias_push_run();
t_eq($moved_to, (string) $lp($a)['scheduled_at'], 'the plan keeps it where the person put it');
t_eq([], $writes($n), '…and never replaces it');
t_ok(!ias_post_editable($lp($a)), 'it is no longer edited here: changes are made in OneUp');

// ---- 7. A person's own posts on the page: the plan works around them ----
$b = $mk('7 Bravo Lane', 'Gap', 'Chester', '2026-11-27 10:00');
$plan();
$bp = $lp($b);
$day = substr((string) $bp['scheduled_at'], 0, 10);
t_ok(ias_post_sent($bp)['mode'] === 'live' && $day !== '', 'a second listing\'s post goes to OneUp too (' . $day . ')');
foreach (['06:10', '11:40', '19:10'] as $i => $hm) {
  $id = $S['next']++;
  $S['scheduled'][$id] = ['post_id' => $id, 'content' => 'Our crew at the sale barn, take ' . $i, 'source_url' => 'NA', 'date_time' => $day . ' ' . $hm . ':00',
    'category_name' => 'Intel', 'video_url' => 'NA', 'content_image' => 'NA', 'social_network_username' => 'Intel Auctions (Narvon)'];
}
ias_readback_run();
t_eq(3, count(ias_posts_query(['status' => 'human'])), 'a person\'s three posts on the page that day become Human posts');
$n = count($S['calls']);
ias_plan_run();
ias_push_run();
$bp = $lp($b);
t_ok(substr((string) $bp['scheduled_at'], 0, 10) !== $day, 'a human post in OneUp moves the schedule around it (' . $day . ' → ' . substr((string) $bp['scheduled_at'], 0, 10) . ')');
t_ok(count(ias_t_oneup_calls($n, 'deletepost')) === 1 && count(ias_t_oneup_calls($n, 'scheduleimagepost')) === 1, '…and the moved post is re-scheduled on OneUp');

// ---- 8. A person deletes our post in OneUp: believed on a second read-back, half an hour later ----
ias_t_clock('2026-11-02 05:50');
unset($S['scheduled'][(int) ias_post_sent($lp($b))['id']]);
ias_readback_run();
$bp = $lp($b);
t_ok($bp['status'] !== 'skipped' && !empty($bp['flags']['missing_since']), 'one read-back that misses a post concludes nothing yet (it notes when it went missing)');
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n), '…and nothing is sent for it meanwhile');
ias_t_clock('2026-11-02 06:25');
ias_readback_run();
$bp = $lp($b);
t_ok($bp['status'] === 'skipped' && ($bp['flags']['skipped_by'] ?? '') === 'person', 'still missing half an hour later: a person deleted it in OneUp, and it stays off the plan');
$n = count($S['calls']);
ias_sync();
ias_plan_run();
ias_push_run();
t_eq('skipped', $lp($b)['status'], '…the next sync and plan leave it off');
t_eq([], ias_t_oneup_calls($n, 'scheduleimagepost'), '…and it is not sent again');
t_eq('', ias_post_sent($lp($b))['mode'], '…and once OneUp\'s list confirms it gone, nothing of it is left to take back');

// ---- 9. Cancelling at once, even while paused ----
$c = $mk('3 Charlie Court', 'Leola', 'Berks', '2026-11-26 10:00');
$plan();
$cid = ias_post_sent($lp($c))['id'];
t_ok($cid !== '' && isset($S['scheduled'][(int) $cid]), 'a third listing\'s post is on OneUp');
ias_settings_update(['paused' => true]);
wp_update_post(['ID' => $c, 'post_status' => 'draft']);
do_action('shutdown');
t_ok(!isset($S['scheduled'][(int) $cid]), 'unpublishing a listing takes its post off OneUp at once — even while paused');
t_eq('cancelled', $lp($c)['status'], '…and cancels it');

// ---- 10. Pause stops new posts ----
$d = $mk('9 Delta Drive', 'Ephrata', 'Lebanon', '2026-11-25 10:00');
$n = count($S['calls']);
$plan();
t_eq([], ias_t_oneup_calls($n, 'scheduleimagepost'), 'paused: no new post goes to OneUp');
t_ok(ia_social_facebook_managed(), '…and Facebook stays with this plugin');
ias_settings_update(['paused' => false]);
ias_push_run();
t_ok(ias_post_sent($lp($d))['mode'] === 'live', 'resumed: it goes');

// ---- 11. A refused post is tried again, then stops for a person ----
$e = $mk('5 Echo Lane', 'Lititz', 'York', '2026-11-24 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run();
$S['fail_next'] = 3;
ias_push_run();
ias_push_run();
t_eq(2, (int) ($lp($e)['flags']['push_fails'] ?? 0), 'a refused post is tried again on each run');
ias_push_run();
$ep = $lp($e);
t_eq('failed', $ep['status'], 'refused three times: Failed, waiting for a person');
t_ok(strpos((string) $ep['note'], 'token has expired') !== false, '…saying what OneUp said');
$act('skip', ['ias_post' => $ep['id']]);
t_eq('skipped', $lp($e)['status'], 'a failed post can be skipped');

// ---- 11b. A replace refused inside the 48 hours is retried, not left off OneUp ----
$fp = $lp($d);
ias_t_clock(date('Y-m-d H:i', strtotime((string) $fp['scheduled_at']) - 30 * HOUR_IN_SECONDS));
$act('edit', ['ias_post' => $fp['id'], 'ias_text' => "9 Delta Drive in Ephrata sells November 25. It is an in-person auction.\n\nCall Jacob at 717-555-0101."]);
$S['fail_next'] = 1;
ias_push_run();
t_eq('', ias_post_sent($lp($d))['id'], 'a replace whose new copy OneUp refuses leaves no old copy behind');
ias_push_run();
t_ok(ias_post_sent($lp($d))['id'] !== '', '…and is sent again on the next run, though it is inside the 48 hours');
ias_t_clock('2026-11-02 05:50');

// ---- 12. An answer without a post id: found on the list by its text and time ----
$S['no_id'] = true;
$f = $mk('8 Foxtrot Road', 'Strasburg', 'Dauphin', '2026-11-23 10:00');
$plan();
$fid = ias_post_sent($lp($f))['id'];
t_ok($fid !== '' && isset($S['scheduled'][(int) $fid]), 'OneUp answered without an id: the post is found on its list');
$S['no_id'] = false;

// ---- 13. Published, confirmed by the read-back; a correction when a fact changes ----
$ap = $lp($a);
$aid = (int) ias_post_sent($ap)['id'];
ias_t_clock(date('Y-m-d H:i', strtotime((string) $ap['scheduled_at']) + 2 * HOUR_IN_SECONDS));
ias_t_oneup_publish($aid);
ias_job_run();
$ap = $lp($a);
t_eq('published', $ap['status'], 'its time came: published');
t_ok(empty($ap['flags']['unconfirmed']) && (string) $ap['published_at'] !== '', '…confirmed by the read-back (' . $ap['published_at'] . ')');
$text = (string) $ap['content']['text'];
update_post_meta($a, 'Starting_Bid', '$275,000');
ias_job_run();
$ap = $lp($a);
$fix = (string) ($ap['flags']['correction']['text'] ?? '');
t_ok(strpos($fix, 'The starting bid is now $275,000 (not $300,000)') !== false, 'a fact changed after it went out: a correction is drafted as a comment (' . $fix . ')');
t_eq($text, (string) $ap['content']['text'], '…and the post that went out is never rewritten');
$_GET = ['page' => IAS_PAGE_CALENDAR];
ob_start(); ias_calendar_render(); $html = ob_get_clean();
t_ok(strpos($html, 'Corrections to post as a comment') !== false && strpos($html, '$275,000') !== false, 'the calendar lists the correction to post');
$act('correction_done', ['ias_post' => $ap['id'], 'ias_done' => 'posted']);
t_eq([], ias_corrections_waiting(), 'Comment posted clears it');

// ---- 14. A post OneUp could not publish ----
$dp = $lp($d);
$did = (int) ias_post_sent($dp)['id'];
$row = $S['scheduled'][$did];
unset($S['scheduled'][$did]);
$row['failure_reason'] = 'Facebook rejected the image';
$S['failed'][$did] = $row;
ias_readback_run();
$dp = $lp($d);
t_eq('failed', $dp['status'], 'a post OneUp could not publish stops for a decision');
t_ok(strpos((string) $dp['note'], 'Facebook rejected the image') !== false, '…saying why');

// ---- 15. The monthly summary ----
add_filter('ias_summary_visits', function () use ($ap) { return ['by_campaign' => ['social_listing' => 15], 'by_post' => [(int) $ap['id'] => 12]]; });
$sum = ias_summary_month('2026-11');
t_ok($sum['types']['listing']['out'] >= 1, 'the month counts the Listing posts that went out (' . $sum['types']['listing']['out'] . ')');
t_eq(15, $sum['types']['listing']['visits'], '…and the visits Listing posts brought (Google Analytics, through the toolkit)');
t_eq(12, $sum['divisions']['farm_home']['visits'], '…by division too');
t_ok($sum['types']['listing']['failed'] >= 1 && $sum['types']['listing']['skipped'] >= 1, '…and the failed and skipped ones');
remove_all_filters('ias_summary_visits');
t_ok(strpos(ias_summary_html('2026-11'), 'Connect Site Kit') !== false, 'without Site Kit the summary shows the counts and says how to get visits');

// ---- 16. The screens ----
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
foreach ([$ap, $dp, $lp($f)] as $one) {
  $_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $one['id']];
  ob_start(); ias_calendar_render(); $html = ob_get_clean();
}
t_ok(strpos($html, 'On OneUp') !== false, 'a post on OneUp says so, with its id');
$_GET = ['page' => IAS_PAGE_SETTINGS];
ob_start(); ias_settings_render(); $html = ob_get_clean();
t_ok(strpos($html, 'Verify OneUp') !== false && strpos($html, 'passed') !== false && strpos($html, 'Last read') !== false, 'Settings shows the last Verify OneUp and the last read-back');
$_GET = ['page' => IAS_PAGE_LOG];
ob_start(); ias_log_render(); $html = ob_get_clean();
t_ok(strpos($html, 'Monthly summary') !== false, 'the Log screen opens with the monthly summary');
$_GET = [];
restore_error_handler();
t_eq([], $errors, 'no PHP notices on the screens');

// ---- 17. Back to the dry run, then the master switch off ----
ias_settings_update(['dry_run' => true]);
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n), 'back to the dry run: nothing more is written to OneUp');
t_eq('dry', (string) get_option('ias_push_mode'), '…and the switch back is noted');
ias_settings_update(['master' => false]);
$n = count($S['calls']);
ias_job_run();
t_eq([], ias_t_oneup_calls($n), 'master switch off again: OneUp is not called at all');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['types' => ['listing' => false]]);
