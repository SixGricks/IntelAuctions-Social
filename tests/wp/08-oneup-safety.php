<?php
/**
 * What can go wrong between the plugin and OneUp, each against the fake
 * OneUp (tests/fixtures: ias_t_oneup): the master switch off calls nothing,
 * even from Settings; nothing is written until Verify OneUp has passed for
 * the page set now, and Verify catches a draft scheduled as a real post (or
 * one whose answer was lost); one push run at a time; a list that comes back
 * without a list, or without any of our posts, never reads as "a person
 * deleted them"; a renamed page is followed; a post whose answer was lost is
 * found and adopted, never sent twice (and never taken for a person's); the
 * key never reaches the log, even in an error that echoes the address; a post
 * without an id is replaced or taken back only once its id is known; a delete
 * answered "not found" is believed only when the list agrees; Pause takes a
 * changed post's old copy back; a post a person moved in OneUp is held, not
 * rewritten, when its listing changes; an Auction Week post on OneUp keeps its
 * listings when another sale joins its day; a post OneUp never confirms is
 * flagged once; and back in the dry run, leftovers on OneUp are listed.
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
$copies = function (string $text) use (&$S) {
  return array_values(array_filter($S['scheduled'], function ($r) use ($text) { return ias_norm_text((string) $r['content']) === ias_norm_text($text); }));
};
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $_POST = [];
};
$plan = function () { ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run(); };
$logged = function () { return (string) wp_json_encode(ias_log_recent('', 500)); };
$screen = function (string $page, array $get = []) {
  $_GET = ['page' => $page] + $get;
  ob_start();
  if ($page === IAS_PAGE_SETTINGS) ias_settings_render(); else ias_calendar_render();
  $_GET = [];
  return (string) ob_get_clean();
};

// ---- 1. The master switch off: Verify OneUp and Check connection call nothing ----
$v = ias_oneup_verify('admin');
t_ok(!$v['ok'] && strpos((string) $v['steps'][0]['detail'], 'master switch is off') !== false, 'Verify OneUp refuses while the master switch is off, saying why');
t_eq([], ias_t_oneup_calls(), '…without one call to OneUp');
$html = $screen(IAS_PAGE_SETTINGS);
t_ok(preg_match('/disabled title="Turn the master switch on first"[^>]*>Check connection/', $html) === 1, 'Settings disables Check connection while the master switch is off');
t_ok(preg_match('/disabled title="[^"]*master switch is off[^"]*"[^>]*>Verify OneUp/', $html) === 1, '…and Verify OneUp');

// ---- 2. Everything on but Verify OneUp: still nothing is written ----
ias_settings_update(['master' => true, 'dry_run' => false, 'types' => ['listing' => true]]);
$a = $mk('12 Alpha Road', 'Narvon', 'Lancaster', '2026-11-28 10:00');
$n = count($S['calls']);
$plan();
t_eq([], $writes($n), 'master on, dry run off, Listing on, but Verify OneUp not passed: nothing is written to OneUp');
t_eq('dry', ias_push_mode(), '…the plugin stays in the dry run');
t_ok(in_array('Verify OneUp has not passed for this page', ias_oneup_blockers(true), true), '…and says why');
t_ok(!ia_social_facebook_managed(), '…and Facebook stays with the toolkit\'s webhook');

// ---- 3. Verify OneUp catches what a draft can show ----
$S['draft_real'] = true;
$v = ias_oneup_verify('admin');
$last = end($v['steps']);
t_ok(!$v['ok'] && strpos((string) $last['detail'], 'scheduled it as a real post') !== false, 'OneUp scheduling the test draft as a real post fails Verify, saying so');
t_eq([], $S['scheduled'], '…and the test post is taken off at once');
$S['draft_real'] = false;
$S['lose_answer'] = 1;
$v = ias_oneup_verify('admin');
t_ok(!$v['ok'] && $S['drafts'] === [], 'a test draft whose answer was lost is found among the drafts and deleted');
$v = ias_oneup_verify('admin');
t_ok($v['ok'] && ias_oneup_verified(), 'Verify OneUp passes');
t_ok(strpos($screen(IAS_PAGE_SETTINGS), 'passed for this page') !== false, '…and Settings says it passed for this page');
ias_push_run();
$sent = ias_post_sent($lp($a));
t_ok($sent['mode'] === 'live' && isset($S['scheduled'][(int) $sent['id']]), 'once it has, the Listing post goes');

// ---- 4. Another page: verify again ----
ias_settings_update(['oneup_account_id' => 'fb_999']);
t_ok(!ias_oneup_verified() && ias_push_mode() === 'dry', 'another Facebook account set: nothing is written until Verify OneUp passes for it');
t_ok(strpos($screen(IAS_PAGE_SETTINGS), 'passed for this page') === false, '…and Settings no longer says it passed');
ias_settings_update(['oneup_account_id' => 'fb_123']);
t_ok(ias_oneup_verified(), 'back to the verified page: verified again');

// ---- The key, saved in Settings (DJ, Oct 13) ----
remove_all_filters('ias_oneup_key');
update_option('ias_oneup_api_key', 'test-oneup-key', false);
t_ok(ias_oneup_key() === 'test-oneup-key' && ias_oneup_key_source() === 'option', 'a key saved under Settings → OneUp is used (no wp-config constant needed)');
t_ok(ias_oneup_verified(), '…and it is the key Verify OneUp passed with');
$html = $screen(IAS_PAGE_SETTINGS);
t_ok(strpos($html, 'name="ias_oneup_key_new"') !== false && strpos($html, 'test-oneup-key') === false, 'Settings has the key field and never prints the saved key');
update_option('ias_oneup_api_key', 'a-different-key', false);
t_ok(!ias_oneup_verified() && ias_push_mode() === 'dry', 'another key pasted in: nothing is written until Verify OneUp passes with it');
update_option('ias_oneup_api_key', 'test-oneup-key', false);
t_ok(ias_oneup_verified(), 'the verified key back: verified again');

// ---- 5. One push run at a time ----
update_option('ias_push_lock', (string) time(), false);
$n = count($S['calls']);
$r = ias_push_run();
t_eq([], ias_t_oneup_calls($n), 'another run holds the push lock: this one does not call OneUp');
t_ok(in_array('another run was pushing', $r['skipped'], true), '…and says so');
update_option('ias_push_lock', (string) (time() - 3600), false);
$r = ias_push_run();
t_ok(!in_array('another run was pushing', $r['skipped'], true), 'a lock a crashed run left an hour ago is taken over');
wp_cache_delete('ias_push_lock', 'options');
t_eq(false, get_option('ias_push_lock'), '…and let go after');

// ---- 6. A list answer with no list in it is a failed read, not "nothing there" ----
$S['broken'] = ['getscheduledposts'];
foreach (['2026-11-02 06:30', '2026-11-02 07:30'] as $t) { ias_t_clock($t); $rb = ias_readback_run(); }
t_ok(!$rb['ok'] && strpos($rb['error'], 'no list of posts') !== false, 'a 200 answer with no list in it ("too many requests") is a failed read');
$ap = $lp($a);
t_ok(empty($ap['flags']['missing_since']) && $ap['status'] !== 'skipped', '…so nothing is taken for deleted, an hour later either');
$S['broken'] = [];

// ---- 7. None of our posts on the list: nothing is concluded ----
$b = $mk('7 Bravo Lane', 'Gap', 'Chester', '2026-11-27 10:00');
$plan();
t_ok(ias_post_sent($lp($b))['mode'] === 'live', 'a second listing\'s post goes to OneUp');
$keep = $S['scheduled'];
$S['scheduled'] = [];
foreach (['2026-11-02 08:00', '2026-11-02 08:45'] as $t) { ias_t_clock($t); ias_readback_run(); }
t_ok($lp($a)['status'] !== 'skipped' && $lp($b)['status'] !== 'skipped', 'none of the plugin\'s posts on OneUp\'s list, twice: nothing is taken for deleted');
t_ok(strpos($logged(), 'nothing is concluded from that') !== false, '…and the log says to check the page');
$S['scheduled'] = $keep;
ias_t_clock('2026-11-02 08:50');
ias_readback_run();
t_ok(empty($lp($a)['flags']['missing_since']) && empty($lp($b)['flags']['missing_since']), 'back on the list: nothing was lost');

// ---- 8. The page renamed in OneUp ----
ias_t_oneup_rename('Intel Auctions LLC');
$h = ias_t_oneup_human('2026-11-12 13:10', 'Our crew at the Gap sale barn');
ias_readback_run();
t_eq('Intel Auctions LLC', (string) ias_setting('oneup_account_name'), 'the page renamed in OneUp: its new name is learned from the plugin\'s own posts');
t_ok(ias_post_sent($lp($a))['mode'] === 'live' && $lp($a)['status'] !== 'skipped', '…which are still matched by their ids');
ias_readback_run();
t_ok(ias_post_by_slot('human:' . $h) !== null, '…and a person\'s post under the new name is worked around');

// ---- 9. Scheduled, but OneUp's answer was lost: found and adopted, never sent twice ----
ias_t_clock('2026-11-02 09:00');
$c = $mk('3 Charlie Court', 'Leola', 'Berks', '2026-11-26 10:00');
$S['lose_answer'] = 1;
$plan();
$cp = $lp($c);
t_ok(!empty($cp['flags']['sending']) && ias_post_sent($cp)['mode'] === '', 'OneUp took the post but answered 502: the attempt is remembered, not counted as sent or refused');
$text = (string) $cp['flags']['sending']['text'];
t_eq(1, count($copies($text)), '…(it is on OneUp)');
ias_readback_run();
$cp = $lp($c);
t_ok(ias_post_sent($cp)['id'] !== '' && empty($cp['flags']['sending']), 'the read-back finds it by its text and time and adopts it');
t_eq(0, count(ias_posts_query(['status' => 'human', 'from' => '2026-11-01', 'to' => '2026-12-31'])) - 1, '…never taking it for a person\'s post (only the person\'s own is Human)');
$n = count($S['calls']);
ias_push_run();
t_eq(1, count($copies($text)), '…and it is not sent again');
t_eq([], ias_t_oneup_calls($n, 'scheduleimagepost'), '…not even tried');

// ---- 10. No answer at all: the key stays out of the log; sent again once it is clear it never arrived ----
$d = $mk('9 Delta Drive', 'Ephrata', 'Lebanon', '2026-11-25 10:00');
$S['net_error'] = 1;
$plan();
$dp = $lp($d);
t_ok(!empty($dp['flags']['sending']) && ias_post_sent($dp)['mode'] === '', 'the request never arrived: the attempt is remembered');
$log = $logged() . wp_json_encode($dp['flags']);
t_ok(strpos($log, 'cURL error 28') !== false && strpos($log, 'apiKey=[key]') !== false, '…the error, which echoed the address, is logged');
t_ok(strpos($log, 'test-oneup-key') === false, '…without the key');
$n = count($S['calls']);
ias_push_run();
$dp = $lp($d);
t_eq(1, count($copies((string) $dp['content']['text'])), 'it was not on OneUp, so the next run sends it — once');
t_ok(ias_post_sent($dp)['id'] !== '' && empty($dp['flags']['sending']), '…and records it');

// ---- 11. No post id and no list: nothing is replaced or taken off until the id is known ----
$e = $mk('5 Echo Lane', 'Lititz', 'York', '2026-11-24 10:00');
$S['no_id'] = true;
$S['broken'] = ['getscheduledposts'];
$plan();
$ep = $lp($e);
$es = ias_post_sent($ep);
t_ok($es['mode'] === 'live' && $es['id'] === '', 'OneUp answered without an id and its list could not be read: sent, id unknown');
$old_text = (string) $ep['content']['text'];
$act('edit', ['ias_post' => $ep['id'], 'ias_text' => "5 Echo Lane in Lititz sells November 24. It is an in-person auction.\n\nCall Jacob at 717-555-0101."]);
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n), 'changed while its id is unknown: nothing is deleted or sent (only an id takes the right post off)');
t_eq(1, count($copies($old_text)), '…so the copy on OneUp is not doubled');
$S['broken'] = [];
$S['no_id'] = false;
ias_readback_run();
$ep = $lp($e);
$eid = ias_post_sent($ep)['id'];
t_ok($eid !== '' && isset($S['scheduled'][(int) $eid]), 'the list readable again: the read-back finds it by its text and time and keeps its id');
ias_push_run();
$ep = $lp($e);
t_ok(!isset($S['scheduled'][(int) $eid]) && count($copies($old_text)) === 0 && count($copies((string) $ep['content']['text'])) === 1, '…and then it is replaced: the old copy off by its id, the new words on');

// ---- 12. "Not found" to a delete is believed only when the list agrees ----
$cid = ias_post_sent($lp($c))['id'];
$S['delete_404'] = 1;
wp_update_post(['ID' => $c, 'post_status' => 'draft']);
do_action('shutdown');
$cp = $lp($c);
t_eq('cancelled', $cp['status'], 'a listing unpublished: its post is cancelled');
t_ok(isset($S['scheduled'][(int) $cid]) && ias_post_sent($cp)['id'] === $cid, 'OneUp answered "not found" to the delete but still lists the post: it is not taken for gone');
ias_push_run();
t_ok(!isset($S['scheduled'][(int) $cid]) && ias_post_sent($lp($c))['mode'] === '', '…and the next run takes it off');

// ---- 13. Paused, and a post on OneUp changes: its old copy comes off ----
$ap = $lp($a);
$aid = ias_post_sent($ap)['id'];
ias_settings_update(['paused' => true]);
$act('edit', ['ias_post' => $ap['id'], 'ias_text' => "12 Alpha Road in Narvon sells November 28. It is an in-person auction.\n\nCall Jacob at 717-555-0101 with questions."]);
$n = count($S['calls']);
ias_push_run();
$ap = $lp($a);
t_ok(!isset($S['scheduled'][(int) $aid]) && ias_post_sent($ap)['mode'] === '', 'paused, and a post on OneUp changed: its old copy comes off rather than going out as it was');
t_eq([], ias_t_oneup_calls($n, 'scheduleimagepost'), '…and the new one waits for the pause to end');
ias_settings_update(['paused' => false]);
ias_push_run();
$ap = $lp($a);
t_ok(ias_post_sent($ap)['mode'] === 'live' && strpos((string) ($S['scheduled'][(int) ias_post_sent($ap)['id']]['content'] ?? ''), 'with questions') !== false, 'resumed: the new words go');

// ---- 14. Moved in OneUp, then its listing changes: held for a person, not rewritten ----
$bp = $lp($b);
$bid = (int) ias_post_sent($bp)['id'];
$S['scheduled'][$bid]['date_time'] = date('Y-m-d H:i:s', strtotime((string) $bp['scheduled_at']) + 2 * HOUR_IN_SECONDS);
ias_readback_run();
t_eq(1, (int) $lp($b)['locked'], 'a post a person moved in OneUp is locked');
update_post_meta($b, 'Starting_Bid', '$250,000');
$before = $lp($b);
$n = count($S['calls']);
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$bp = $lp($b);
t_eq('held', $bp['status'], 'its listing changed: it stops for a person');
t_ok(strpos((string) $bp['note'], 'fix it there') !== false, '…who is told to fix it in OneUp (or skip it here)');
t_ok($bp['generated_at'] === $before['generated_at'] && $bp['content']['text'] === $before['content']['text'], '…and Claude is not asked to rewrite it');
t_eq([], $writes($n), '…nor is it replaced or taken off OneUp');

// ---- 15. A sale joins an Auction Week post that is on OneUp: it gets its own ----
ias_settings_update(['types' => ['listing' => true, 'auction_week' => true]]);
$f = $mk('21 Foxtrot Road', 'Strasburg', 'Lancaster', '2026-11-21 10:00');
$g = $mk('23 Golf Road', 'Strasburg', 'Lancaster', '2026-11-21 14:00');
$plan();
$aw = array_values(array_filter(ias_posts_query(['type' => 'auction_week', 'limit' => 50]), function ($p) use ($f) { return in_array($f, array_map('intval', $p['listing_ids']), true); }));
t_ok(count($aw) === 1 && ias_post_sent($aw[0])['mode'] === 'live', 'two sales on one day and place share an Auction Week post, on OneUp');
$awid = ias_post_sent($aw[0])['id'];
$h2 = $mk('25 Hotel Road', 'Strasburg', 'Lancaster', '2026-11-21 16:00');
$n = count($S['calls']);
$plan();
$row = ias_post_get((int) $aw[0]['id']);
sort($row['listing_ids']);
t_eq([$f, $g], array_map('intval', $row['listing_ids']), 'a third sale joins that day: the post on OneUp keeps its two');
t_ok(ias_post_sent($row)['id'] === $awid && isset($S['scheduled'][(int) $awid]), '…and is not redrafted or replaced');
t_ok((bool) ias_post_by_slot((string) $aw[0]['slot_key'] . ':' . $h2), '…the newcomer gets an Auction Week post of its own');

// ---- 16. Back in the dry run: what is still on OneUp but no longer wanted is listed ----
ias_settings_update(['dry_run' => true]);
$act('skip', ['ias_post' => $lp($d)['id']]);
t_ok($lp($d)['status'] === 'skipped' && ias_post_sent($lp($d))['mode'] === 'live', 'skipped in the dry run: the plugin does not take it off OneUp itself');
t_ok(strpos($screen(IAS_PAGE_CALENDAR), 'still scheduled on OneUp') !== false, '…and the calendar says it is still there, to delete by hand or go live again');

ias_settings_update(['dry_run' => false]);

// ---- 17. Gone out by our clock, never confirmed by OneUp: flagged once, a day later ----
$ep = $lp($e);
$eid = (int) ias_post_sent($ep)['id'];
unset($S['scheduled'][$eid]); // OneUp lost it: neither scheduled nor published
ias_t_clock(date('Y-m-d H:i', strtotime((string) $ep['scheduled_at']) + HOUR_IN_SECONDS));
ias_push_run();
t_ok($lp($e)['status'] === 'published' && !empty($lp($e)['flags']['unconfirmed']), 'its time came: counted as gone out, unconfirmed');
ias_t_clock(date('Y-m-d H:i', strtotime((string) $ep['scheduled_at']) + 26 * HOUR_IN_SECONDS));
$noted = function () use ($ep) {
  return count(array_filter(ias_log_recent('readback', 500), function ($r) use ($ep) { return (int) ($r['meta']['post'] ?? 0) === (int) $ep['id'] && strpos((string) ($r['meta']['summary'] ?? ''), 'Not confirmed') === 0; }));
};
ias_readback_run();
t_ok(strpos((string) $lp($e)['note'], 'OneUp has not confirmed') !== false, 'a day later and still not confirmed: a person is told to check the page');
ias_readback_run();
t_eq(1, $noted(), '…once');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'dry_run' => true, 'paused' => false, 'types' => ['listing' => false, 'auction_week' => false]]);
delete_option('ias_oneup_verify');
