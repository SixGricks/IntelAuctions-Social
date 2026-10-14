<?php
/**
 * 0.20.0, DJ, Oct 14, 2026: "When moving calendar items they move VERY
 * slowly … Can it just move them and wait to run the agent until later. This
 * will allow a human to make a bunch of changes without AI changing things at
 * the same time."
 *
 * A move (Move, a drag) changes only the post moved, and the page answers
 * without a single call to OneUp: the post goes to OneUp after the page has
 * answered (ias_push_later(), at the end of the request). The plan waits
 * (`ias_plan_hold`) until nothing has moved for IAS_PLAN_QUIET minutes — then
 * the hourly run tidies around the moves — or until Plan now. Anything still
 * queued goes with the open calendar's next check or the hourly run.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_t_oneup();
$S =& $GLOBALS['ias_t_oneup'];
$S['gbp'] = false;
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$counties = ['Lancaster', 'Berks', 'Lebanon', 'Chester', 'York', 'Dauphin'];
$ls = [];
for ($i = 0; $i < 8; $i++) {
  $gal = []; for ($j = 1; $j <= 4; $j++) $gal[] = ias_t_photo("Q$i $j");
  $ls[] = ias_t_listing(['title' => ($i + 1) . ' Quick Road', 'address' => ($i + 1) . ' Quick Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => $counties[$i % 6],
    'stage' => 'upcoming', 'sale' => date('Y-m-d', strtotime('2026-11-20 +' . $i . ' days')) . ' 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
}
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$drag = function (int $id, string $day, int $slot) { // the calendar's handler, up to the page answering
  $_POST = ['ias_cal_action' => 'move_slot', 'ias_post' => (string) $id, 'ias_day' => $day, 'ias_slot' => (string) $slot, 'ias_from' => 'calendar', 'ias_back' => ias_admin_url(IAS_PAGE_CALENDAR)];
  do_action('ias_calendar_action', 'move_slot');
  if (in_array('move_slot', ias_push_now_actions(), true)) ias_push_now_flash($id);
  $_POST = [];
  $k = 'ia_flash_' . get_current_user_id(); $h = (string) get_transient($k); delete_transient($k); return $h;
};
$times = function (array $except) {
  $out = [];
  foreach (ias_posts_query(['limit' => 200]) as $p) if (!in_array((int) $p['id'], $except, true)) $out[(int) $p['id']] = (string) $p['scheduled_at'] . '|' . $p['status'];
  return $out;
};
$weekday = function (string $day) { while (date('N', strtotime($day)) === '7') $day = date('Y-m-d', strtotime($day . ' +1 day')); return $day; };

ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$ids = array_map(function ($l) use ($lp) { return (int) $lp($l)['id']; }, $ls);
t_ok(count(array_filter($ids, function ($id) { return ias_post_sent(ias_post_get($id))['mode'] === 'live'; })) === 8, 'setting out: eight Listing posts on OneUp');

// ---- One move: the page answers without OneUp ----
$p = ias_post_get($ids[2]);
$day = $weekday(date('Y-m-d', strtotime((string) $p['scheduled_at'] . ' +3 days')));
$others = $times([$ids[2]]);
$n = count($S['calls']);
$fl = $drag($ids[2], $day, 1);
t_eq(0, count($S['calls']) - $n, 'a drag: the page answers without a single call to OneUp');
t_eq($day, substr((string) ias_post_get($ids[2])['scheduled_at'], 0, 10), '…the post is on its new day');
t_eq($others, $times([$ids[2]]), '…and nothing else on the calendar moved');
t_ok(strpos($fl, 'OneUp has it in a moment') !== false && strpos($fl, 'Nothing else moves while you work') !== false, 'the page says OneUp follows in a moment and the plan waits');
$q = get_option('ias_push_queue', []);
t_ok(isset($q[$ids[2]]), '…the post is queued for OneUp');
ias_push_queue_run(0); // the end of the request, once the page has answered
$p = ias_post_get($ids[2]);
t_eq(substr((string) $p['scheduled_at'], 0, 16), substr((string) ($S['scheduled'][(int) ias_post_sent($p)['id']]['date_time'] ?? ''), 0, 16), 'after the page has answered, OneUp has it at its new time');
t_eq([], get_option('ias_push_queue', []), '…and the queue is empty');

// ---- A bunch of moves: nothing else changes, and the plan waits ----
$moved = [$ids[2]];
foreach ([[$ids[4], 2], [$ids[5], 0]] as [$id, $slot]) {
  $d = $weekday(date('Y-m-d', strtotime((string) ias_post_get($id)['scheduled_at'] . ' +2 days')));
  $before = $times(array_merge($moved, [$id]));
  $drag($id, $d, $slot);
  ias_push_queue_run(0);
  $moved[] = $id;
  t_eq($before, $times($moved), 'another move: still nothing else moves');
}
$hold = ias_plan_hold_get();
t_eq(3, (int) ($hold['n'] ?? 0), 'the plan is waiting, counting three moves');
$_GET = ['page' => IAS_PAGE_CALENDAR]; ob_start(); ias_calendar_render(); $cal = (string) ob_get_clean(); $_GET = [];
t_ok(strpos($cal, 'The plan is waiting while you work') !== false && strpos($cal, '3 posts moved since') !== false, 'the calendar says the plan is waiting, and for how many moves');

// The hourly run soon after: the plan still waits.
$snap = $times([]);
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 5 * 60));
ias_job_run();
t_ok((bool) ias_plan_hold_get() && $snap === $times([]), 'an hourly run five minutes after the last move: the plan still waits, nothing moves');
// Long enough after: it tidies up.
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + IAS_PLAN_QUIET * 60));
ias_job_run();
t_ok(!ias_plan_hold_get(), IAS_PLAN_QUIET . ' minutes after the last move, the hourly run plans around the moves and the wait ends');
foreach ($moved as $id) t_ok(!empty(ias_post_get($id)['flags']['pinned']), '…the moved post stays where the person put it (' . ias_post_get($id)['scheduled_at'] . ')');

// Plan now ends the wait at once.
$went = false; // the first window free of the posts moved by hand
for ($k = 1; $k <= 8 && !$went; $k++) for ($slot = 0; $slot <= 2 && !$went; $slot++) $went = strpos($drag($ids[6], $weekday(date('Y-m-d', strtotime((string) ias_post_get($ids[6])['scheduled_at'] . ' +' . $k . ' day'))), $slot), 'Moved to') !== false;
ias_push_queue_run(0);
t_ok((bool) ias_plan_hold_get(), 'a new move: the plan waits again');
$_POST = ['ias_cal_action' => 'plan_now']; do_action('ias_calendar_action', 'plan_now'); $_POST = [];
delete_transient('ia_flash_' . get_current_user_id());
t_ok(!ias_plan_hold_get(), 'Plan now: the plan runs, and the wait ends');

// ---- Still queued: the open calendar's next check sends it ----
$id = $ids[7];
$d = $weekday(date('Y-m-d', strtotime((string) ias_post_get($id)['scheduled_at'] . ' +2 days')));
$drag($id, $d, 0); // the end of the request never ran (say, a host that cannot answer early and a busy run)
$p = ias_post_get($id);
t_ok(substr((string) ($S['scheduled'][(int) ias_post_sent($p)['id']]['date_time'] ?? ''), 0, 10) !== $d || ias_post_sent($p)['mode'] !== 'live', 'not sent yet: OneUp still has the old time');
ias_oneup_pulse(true);
$p = ias_post_get($id);
t_eq(substr((string) $p['scheduled_at'], 0, 16), substr((string) ($S['scheduled'][(int) ias_post_sent($p)['id']]['date_time'] ?? ''), 0, 16), 'the open calendar\'s next check sends it');

// A run already sending: the queue keeps the post for later.
$d2 = $weekday(date('Y-m-d', strtotime($d . ' +1 day')));
$drag($id, $d2, 1);
ias_push_lock();
t_eq(0, ias_push_queue_run(0), 'another run is sending: nothing is sent over it');
t_ok(isset(get_option('ias_push_queue', [])[$id]), '…and the post stays queued');
ias_push_unlock();
t_eq(1, ias_push_queue_run(0), '…sent once the other run is done');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'types' => ['listing' => false]]);
delete_option('ias_oneup_verify');
delete_option('ias_plan_hold');
delete_option('ias_push_queue');
