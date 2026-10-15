<?php
/**
 * What a person decides holds, and the plan settles (the 0.4.2 review):
 * a draft that stopped for a person goes nowhere until someone decides; an
 * edit clears "the listing changed", and an edited post is never rewritten
 * behind its editor's back; approval links for out-of-date facts are refused;
 * an approved post that loses a listing loses its approval; a Lane B post never
 * sent is never sent inside the veto window, and one that stops being ready is
 * taken back; what is on OneUp stays put and counts as gone out once
 * OneUp publishes it (there is no dry run: every check here sends, to the
 * test fake of OneUp); Restore beats the system's reason; Open House posts wait for
 * their Listing post; Results never expire; refused links from strangers are
 * not logged; without GD a picture still goes.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30, 'approvers' => 'dj@intelauctions.com', 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123']);
// Posting on, to the test fake of OneUp: a switch is on or off, and on means it is sent.
ias_t_oneup();
$GLOBALS['ias_t_oneup']['gbp'] = false;
ias_settings_update(['master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true, 'open_house' => true, 'auction_week' => true, 'results' => true, 'roundup' => true]]);

$GLOBALS['ias_t_mail'] = [];
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['ias_t_mail'][] = $atts; return true; }, 10, 2);
$mail = function () { $m = $GLOBALS['ias_t_mail']; $GLOBALS['ias_t_mail'] = []; return $m; };
$token = function (array $mails) {
  foreach ($mails as $m) {
    if (preg_match('/action=ias_approve(?:&#038;|&amp;|&)p=(\d+)(?:&#038;|&amp;|&)t=([a-f0-9]+)/', (string) $m['message'], $x)) return $x[2];
  }
  return '';
};
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $_POST = [];
};
// How many times this post was scheduled on OneUp ($action 'delete': taken back off it), from the log.
$sent = function (int $post_id, string $action = '') {
  return count(array_filter(ias_log_recent('pushed', 1000), function ($l) use ($post_id, $action) {
    if ((int) ($l['meta']['post'] ?? 0) !== $post_id || !empty($l['meta']['channel'])) return false;
    $off = strpos((string) ($l['meta']['summary'] ?? ''), 'Taken off') === 0;
    return $action === 'delete' ? $off : !$off;
  }));
};
$gallery = function (string $name, int $n = 3) {
  $ids = [];
  for ($i = 1; $i <= $n; $i++) $ids[] = ias_t_photo($name . ' ' . $i, [60 + $i * 20, 110, 90]);
  return $ids;
};

// ---- 1. A draft that stopped for a person stays stopped ----
$GLOBALS['ias_t_claude_bad'] = '12 Held Lane';
$held = ias_t_listing(['title' => '12 Held Lane', 'address' => '12 Held Lane, Strasburg, PA 17579, USA', 'town' => 'Strasburg', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'gallery' => $gallery('Held')]);
ias_job_run();
$hp = ias_post_by_slot('listing:' . $held);
$hid = (int) $hp['id'];
t_eq('held', $hp['status'], 'a draft with a fair-housing phrase stops for a person');
t_eq('it needs a decision', ias_push_ready(array_merge($hp, ['status' => 'planned'])), 'its reasons alone keep it from being pushed, whatever its status says');

$f = $hp['flags']; $f['dropped_by'] = 'scheduler';
ias_post_update($hid, ['status' => 'dropped', 'flags' => $f, 'scheduled_at' => null]);
ias_plan_run();
t_eq('held', ias_post_get($hid)['status'], 'dropped by the plan, then back when room opens: still Needs a decision, not Planned');

$f = ias_post_get($hid)['flags']; $f['skipped_by'] = 'system';
ias_post_update($hid, ['status' => 'skipped', 'flags' => $f, 'note' => 'Skipped by the system for a test']);
ias_sync();
t_eq('held', ias_post_get($hid)['status'], 'skipped by the system, then called for again: still Needs a decision');

$act('release', ['ias_post' => $hid]);
t_eq('planned', ias_post_get($hid)['status'], 'Keep as written puts it on the plan');
$act('edit', ['ias_post' => $hid, 'ias_text' => "12 Held Lane in Strasburg sells November 28 — perfect for families. It is an in-person auction.\n\nCall the office."]);
$e = ias_post_get($hid);
t_ok($e['status'] === 'held' && empty($e['flags']['released_by']), 'an edit that stops again needs a new decision (the old Keep as written was for other words)');
$act('skip', ['ias_post' => $hid]);
$act('restore', ['ias_post' => $hid]);
t_eq('held', ias_post_get($hid)['status'], 'Skip, then Restore: back as Needs a decision');
unset($GLOBALS['ias_t_claude_bad']);
$act('edit', ['ias_post' => $hid, 'ias_text' => "12 Held Lane in Strasburg sells November 28. It is an in-person auction.\n\nCall the office."]);
t_eq('planned', ias_post_get($hid)['status'], 'a clean edit puts it on the plan');

// ---- 2. An edit clears "the listing changed"; an edited post is never rewritten behind its editor's back ----
update_post_meta($held, 'Starting_Bid', '$275,000');
ias_sync();
$e = ias_post_get($hid);
t_eq('held', $e['status'], 'the listing changed under an edited post: it stops for its editor');
t_ok(!empty($e['flags']['stale']) && in_array(ias_stale_hold(true, false, ['starting bid']), (array) $e['flags']['holds'], true) && $e['flags']['stale_what'] === ['starting bid'], '…saying what changed after the edit: "The listing\'s starting bid changed after a person edited this post"');
t_ok(!in_array($hid, array_map(function ($p) { return (int) $p['id']; }, ias_drafts_due()), true), '…and Claude does not rewrite it');
$act('edit', ['ias_post' => $hid, 'ias_text' => "12 Held Lane in Strasburg sells November 28. It is an in-person auction. Starting bid \$275,000.\n\nCall the office."]);
ias_sync();
ias_plan_run();
$e = ias_post_get($hid);
t_ok($e['status'] === 'planned' && empty($e['flags']['stale']), 'saved against today\'s listing, it stays clean through the next sync (' . $e['status'] . ')');
t_eq('', ias_push_ready($e), '…and is ready to go');

// ---- 3. A Lane B post never sent is never sent inside the veto window ----
t_eq('', ias_post_sent($e)['mode'], 'never sent so far');
ias_post_update($hid, ['scheduled_at' => ias_now()->modify('+38 hours')->format('Y-m-d H:i:00')]);
$n = $sent($hid);
ias_push_run();
t_eq($n, $sent($hid), 'a Lane B post never sent and now 38 hours out (say it was released late) is not sent');
ias_plan_run();
t_ok((string) ias_post_get($hid)['scheduled_at'] >= ias_now()->modify('+48 hours')->format('Y-m-d H:i:s'), '…the plan gives it a time outside the veto window');
ias_push_run();
t_eq($n + 1, $sent($hid), '…and then it goes');

// ---- 4. Approval links for out-of-date facts; an edited approved post stops for its editor ----
$sold = ias_t_listing(['title' => '7 Sold Road', 'address' => '7 Sold Road, Lititz, PA 17543, USA', 'town' => 'Lititz', 'county' => 'Lancaster',
  'sale' => '2026-10-31 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'price' => '400000', 'featured' => ias_t_photo('Sold front')]);
ia_stage_set($sold, 'past');
$mail();
ias_sync();
ias_drafts_run(30);
$rid = (int) ias_post_by_slot('results:2026-10-31:lititz')['id'];
t_eq('waiting', ias_post_get($rid)['status'], 'a Results post waits for approval');
$tok = $token($mail());
update_post_meta($sold, '_ia_result_price', '410000'); // corrected before anyone answered
ias_sync();
$r = ias_post_get($rid);
t_ok(!empty($r['flags']['stale']) && !ias_approval_ready(array_merge($r, ['status' => 'planned'])), 'a waiting post whose facts changed cannot be sent for approval');
$page = ias_approval_response($rid, $tok, true);
t_eq('changed', $page['reason'], 'its emailed link no longer approves the old price');
t_eq('waiting', ias_post_get($rid)['status'], '…nothing was approved');
t_ok(!ias_approve($rid, 'dj@intelauctions.com', 'WordPress')['ok'], 'nor can it be approved on the calendar before it is rewritten');
$wpdb->update(ias_table('posts'), ['generated_at' => '2026-11-02 03:00:00'], ['id' => $rid]);
ias_drafts_run(30);
$r = ias_post_get($rid);
t_ok($r['status'] === 'waiting' && strpos((string) $r['content']['text'], '$410,000') !== false, 'rewritten with the corrected price and sent again');
t_ok($token($mail()) !== '', '…in a new email');

$act('edit', ['ias_post' => $rid, 'ias_text' => "7 Sold Road in Lititz sold for \$410,000.\n\nThank you to everyone who came out.", 'ias_and_approve' => '1']);
$r = ias_post_get($rid);
t_eq('approved', $r['status'], 'Save and approve');
t_eq(1, $sent($rid), 'the approved post is sent');
update_post_meta($sold, '_ia_result_price', '415000'); // corrected after the yes
ias_sync();
$r = ias_post_get($rid);
t_eq('held', $r['status'], 'a fact corrected after an edited post was approved: the approval goes and it stops for its editor');
t_ok(strpos((string) $r['content']['text'], '$410,000') !== false && !empty($r['flags']['edited_by']), '…its words are not rewritten behind the editor\'s back');
ias_push_run();
t_eq(1, $sent($rid, 'delete'), 'a sent post that stops being ready is taken back off OneUp');
$act('edit', ['ias_post' => $rid, 'ias_text' => "7 Sold Road in Lititz sold for \$415,000.\n\nThank you to everyone who came out."]);
t_eq('waiting', ias_post_get($rid)['status'], 'fixed by its editor, it goes for a fresh yes');

// ---- 5. An approved shared post that loses a listing loses its approval ----
$twin_a = ias_t_listing(['title' => '1 Twin Lane', 'address' => '1 Twin Lane, Sunbury, PA 17801, USA', 'town' => 'Sunbury', 'county' => 'Northumberland',
  'sale' => '2026-10-31 13:00', 'terms' => [$T['invest'], $T['in_person']], 'auction_type' => 'Live', 'price' => '200000', 'featured' => ias_t_photo('Twin A')]);
$twin_b = ias_t_listing(['title' => '3 Twin Lane', 'address' => '3 Twin Lane, Sunbury, PA 17801, USA', 'town' => 'Sunbury', 'county' => 'Northumberland',
  'sale' => '2026-10-31 14:00', 'terms' => [$T['invest'], $T['in_person']], 'auction_type' => 'Live', 'price' => '210000', 'featured' => ias_t_photo('Twin B')]);
ia_stage_set($twin_a, 'past');
ia_stage_set($twin_b, 'past');
ias_sync();
ias_drafts_run(30);
$tw = ias_post_by_slot('results:2026-10-31:sunbury');
t_eq([$twin_a, $twin_b], array_map('intval', $tw['listing_ids']), 'two sales at one place share a Results post');
ias_approve((int) $tw['id'], 'dj@intelauctions.com', 'test');
wp_update_post(['ID' => $twin_b, 'post_status' => 'draft']);
do_action('shutdown');
$tw = ias_post_get((int) $tw['id']);
t_eq('planned', $tw['status'], 'one of them unpublished after the yes: the approval is withdrawn');
t_eq([$twin_a], array_map('intval', $tw['listing_ids']), '…the post now names only the sale still in it');
t_ok(!empty($tw['flags']['stale']) && ias_push_ready($tw) !== '', '…and it is rewritten before anyone is asked again, never pushed in between');

// ---- 6. Open House posts wait for their Listing post ----
$bare = ias_t_listing(['title' => '8 Bare Lane', 'address' => '8 Bare Lane, Elliottsburg, PA 17024, USA', 'town' => 'Elliottsburg', 'county' => 'Perry',
  'stage' => 'upcoming', 'sale' => '2026-11-28 11:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$150,000', 'oh' => [['2026-11-12 13:00', 2]]]);
ias_sync();
ias_plan_run();
$ohp = ias_post_by_slot('open_house:' . $bare . ':20261112');
t_ok(empty($ohp['scheduled_at']), 'an Open House post waits while its Listing post waits for photos');
t_ok(strpos((string) $ohp['note'], 'Waits for its Listing post') !== false, '…saying why');
update_post_meta($bare, 'gallery', implode(',', $gallery('Bare', 4)));
ias_sync();
ias_plan_run();
$lp = ias_post_by_slot('listing:' . $bare);
$ohp = ias_post_by_slot('open_house:' . $bare . ':20261112');
t_ok(!empty($lp['scheduled_at']) && !empty($ohp['scheduled_at']) && $lp['scheduled_at'] < $ohp['scheduled_at'], 'photos in: the Listing post goes first, the Open House post after it');
t_eq('', (string) $ohp['note'], '…and the waiting note goes');

// ---- 7. Restore beats the system's reason ----
$hooked = ias_t_listing(['title' => '5 Webhook Way', 'address' => '5 Webhook Way, Denver, PA 17517, USA', 'town' => 'Denver', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-30 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$350,000', 'gallery' => $gallery('Hook')]);
update_post_meta($hooked, '_ia_social_pushed', '2026-10-30 10:00:00');
ias_sync();
$wh = ias_post_by_slot('listing:' . $hooked);
t_eq('skipped', $wh['status'], 'a listing the toolkit\'s webhook already posted in full gets no Listing post');
$act('restore', ['ias_post' => (int) $wh['id']]);
ias_sync();
$wh = ias_post_get((int) $wh['id']);
t_eq('planned', $wh['status'], 'Restore over the system\'s reason holds through the next sync');
t_ok((string) $wh['window_end'] >= ias_now()->format('Y-m-d'), '…with a window from today, not its one lost day (' . $wh['window_start'] . ' → ' . $wh['window_end'] . ')');

// ---- 8. Refused links from strangers leave no log line ----
$before = count(ias_log_recent('approved', 1000));
ias_approval_response($rid, str_repeat('ab', 24), true);
ias_approval_response(999999, str_repeat('ab', 24), true);
t_eq($before, count(ias_log_recent('approved', 1000)), 'unknown and altered links are refused without filling the shared log');

// ---- 9. Without GD a picture still goes ----
add_filter('ias_gd_ready', '__return_false');
$r = ias_post_get($rid);
$media = ias_media_for_post($r, ias_post_facts($r));
t_ok(count($media['images']) === 1 && $media['images'][0]['kind'] !== 'graphic' && (int) $media['images'][0]['id'] > 0, 'no GD: a priced Results post gets the plain photo instead of the graphic');
$media = ias_media_for_post(['id' => 0, 'type' => 'roundup', 'slot_key' => 'roundup:test', 'flags' => []], [ias_listing_facts($sold), ias_listing_facts($held)]);
t_ok(count($media['images']) === 2 && !in_array('graphic', array_column($media['images'], 'kind'), true), 'no GD: the roundup gets each listing\'s own picture instead of the collage');
remove_all_filters('ias_gd_ready');

// ---- 10. The plan settles: what is on OneUp stays put, then counts as gone out ----
$settle = ias_t_listing(['title' => '30 Settle Road', 'address' => '30 Settle Road, Newmanstown, PA 17073, USA', 'town' => 'Newmanstown', 'county' => 'Lebanon',
  'stage' => 'upcoming', 'sale' => '2026-11-27 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$500,000', 'gallery' => $gallery('Settle', 5)]);
ias_sync();
ias_drafts_run(30);
ias_plan_run();
ias_push_run();
$sp = ias_post_by_slot('listing:' . $settle);
$sid = (int) $sp['id'];
$at = (string) $sp['scheduled_at'];
t_ok($at !== '' && ias_post_sent($sp)['mode'] === 'live', 'planned and sent to OneUp (' . $at . ')');
$moves = 0;
for ($d = ias_now()->modify('+1 day')->setTime(5, 30); $d->format('Y-m-d') <= substr($at, 0, 10); $d = $d->modify('+1 day')) {
  ias_t_clock($d->format('Y-m-d H:i'));
  ias_job_run();
  if ((string) ias_post_get($sid)['scheduled_at'] !== $at) $moves++;
}
t_eq(0, $moves, 'morning after morning it keeps its time — the plan no longer pushes it back every day');
t_eq(1, $sent($sid), '…and it was sent once, not replaced a day');
ias_t_oneup_publish((int) ias_post_sent(ias_post_get($sid))['id']); // OneUp publishes it at its time
ias_t_clock(date('Y-m-d H:i', strtotime($at) + HOUR_IN_SECONDS));
ias_job_run();
$sp = ias_post_get($sid);
t_ok($sp['status'] === 'published' && empty($sp['flags']['dry_run']), 'once its time passes it counts as published');
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $sid];
ob_start(); ias_calendar_render(); $html = ob_get_clean();
$_GET = [];
t_ok(strpos($html, 'Went out') !== false && stripos($html, 'dry run') === false, 'the post says it went out — and nothing on it speaks of a dry run');

// ---- 11. Results never expire ----
ias_t_clock('2026-12-02 05:30'); // 32 days after the sale, still no yes
ias_sync();
t_eq('waiting', ias_post_get($rid)['status'], 'a Results post still waiting a month after the sale is not cancelled — it never expires');

// ---- 12. A [VERIFY] marker never goes out (the UI review, Oct 13: Keep as written cleared the hold and the marker went with it) ----
ias_t_clock('2026-11-02 05:30');
$vp = ias_post_get($hid);
$content = (array) $vp['content'];
$content['text'] = "12 Held Lane in Strasburg sells Saturday, November 28 at [VERIFY: auction time, facts say 12:00 am]. It is an in-person auction.\n\nCall the office.";
$flags = $vp['flags'];
$flags['holds'] = ['Claude marked something to check: [VERIFY: auction time, facts say 12:00 am]'];
unset($flags['released_by']);
ias_post_update($hid, ['status' => 'held', 'content' => $content, 'flags' => $flags]);
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $hid];
ob_start(); ias_calendar_render(); $html = ob_get_clean();
$_GET = [];
t_ok(strpos($html, 'Keep as written') === false, 'a draft held for a [VERIFY] marker is not offered Keep as written');
$act('release', ['ias_post' => $hid]);
t_eq('held', ias_post_get($hid)['status'], '…and pressing it anyway is refused: it stays held');
$flags['released_by'] = 'someone';
ias_post_update($hid, ['status' => 'planned', 'flags' => $flags]);
t_eq('the text still has a [VERIFY] marker', ias_push_ready(ias_post_get($hid)), 'even released by other means, a post with a marker is never ready to go out');
$content['text'] = 'A post with a fine text.';
$content['first_comment'] = 'Photos and terms: [VERIFY: the listing link]';
ias_post_update($hid, ['content' => $content]);
t_eq('the text still has a [VERIFY] marker', ias_push_ready(ias_post_get($hid)), '…nor with one in its first comment');
$act('edit', ['ias_post' => $hid, 'ias_text' => "12 Held Lane in Strasburg sells Saturday, November 28 at 10:00 am. It is an in-person auction.\n\nCall the office.", 'ias_comment' => 'Photos and terms: https://example.com/']);
t_ok(!ias_has_verify((array) ias_post_get($hid)['content']), 'the fact typed in place of the marker: the marker is gone');

remove_all_filters('pre_wp_mail');
ias_t_claude_off();
ias_t_oneup_off();
