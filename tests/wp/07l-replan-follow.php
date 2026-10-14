<?php
/**
 * 0.19.1, from the Codex review of PR #8: a click that re-plans (Restore,
 * Move or a drag, Let the plan choose, Try again) sends OneUp every other
 * post the plan changed in that same request — moved, dropped, or brought
 * back when room opened — not only the post clicked (ias_plan_run_follow()).
 * Before, the others waited for the hourly run.
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
$mk = function (string $title, string $sale, string $county) use ($T, $lead) {
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing(['title' => $title, 'address' => $title . ', Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => $county,
    'stage' => 'upcoming', 'sale' => $sale, 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
};
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $id = (int) ($post['ias_post'] ?? 0);
  if ($id && in_array($action, ias_push_now_actions(), true)) ias_push_now_flash($id); // what the calendar's handler does next
  ias_push_queue_run(0); // and the end of the request
  $_POST = [];
  delete_transient('ia_flash_' . get_current_user_id());
};
// The plan dropped a post (no room, it said): off OneUp; it comes back by itself when room opens.
$drop = function (int $id) {
  $f = ias_post_get($id)['flags'];
  $f['dropped_by'] = 'scheduler';
  ias_post_update($id, ['status' => 'dropped', 'flags' => $f, 'note' => 'No room in its window (a test).']);
  ias_push_run();
};

ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
$a = $mk('12 Alpha Road', '2026-11-28 10:00', 'Lancaster');
$b = $mk('7 Bravo Lane', '2026-11-27 10:00', 'Berks');
$c = $mk('3 Charlie Court', '2026-11-26 10:00', 'Lebanon');
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$aid = (int) $lp($a)['id']; $bid = (int) $lp($b)['id']; $cid = (int) $lp($c)['id'];
t_ok(ias_post_sent($lp($c))['mode'] === 'live', 'setting out: the posts are on OneUp');

// ---- Restore ----
$drop($cid);
t_ok(ias_post_get($cid)['status'] === 'dropped' && ias_post_sent(ias_post_get($cid))['mode'] === '', 'a post the plan dropped is off OneUp');
$act('skip', ['ias_post' => $aid]);
$n = count($S['calls']);
$act('restore', ['ias_post' => $aid]);
$cp = ias_post_get($cid);
t_ok(in_array($cp['status'], ias_live_statuses(), true), 'Restore re-plans: room is there, so the dropped post comes back (' . $cp['status'] . ')');
t_ok(ias_post_sent($cp)['mode'] === 'live', '…and goes to OneUp in the same click, not on the next hourly run');
t_ok(ias_post_sent(ias_post_get($aid))['mode'] === 'live', '…as does the restored post');

// ---- A drag (0.20.0: it does not re-plan; the plan runs when the person is done) ----
$drop($cid);
$day = substr((string) ias_post_get($bid)['scheduled_at'], 0, 10) === '2026-11-10' ? '2026-11-11' : '2026-11-10';
$act('move_slot', ['ias_post' => $bid, 'ias_day' => $day, 'ias_slot' => '0', 'ias_from' => 'calendar', 'ias_back' => ias_admin_url(IAS_PAGE_CALENDAR)]);
t_eq('dropped', ias_post_get($cid)['status'], 'a drag moves only its post: the dropped one stays dropped while the person works');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + (IAS_PLAN_QUIET + 1) * 60));
ias_job_run();
$cp = ias_post_get($cid);
t_ok(in_array($cp['status'], ias_live_statuses(), true) && ias_post_sent($cp)['mode'] === 'live', '…and comes back, on OneUp, when the plan tidies up');

// ---- Let the plan choose ----
$drop($cid);
$act('unpin', ['ias_post' => $bid]);
$cp = ias_post_get($cid);
t_ok(in_array($cp['status'], ias_live_statuses(), true) && ias_post_sent($cp)['mode'] === 'live', 'Let the plan choose: the same');

// ---- The helper itself: what it sent, and why ----
$drop($cid);
$n = count($S['calls']);
$r = ias_plan_run_follow($aid, 'admin');
t_eq([$cid], array_map(function ($o) { return (int) $o['id']; }, $r['reinstated']), 'ias_plan_run_follow() names the post it brought back');
t_ok(count(ias_t_oneup_calls($n, 'scheduleimagepost')) + count(ias_t_oneup_calls($n, 'scheduletextpost')) >= 1 && ias_post_sent(ias_post_get($cid))['mode'] === 'live', '…and sent it');
$n = count($S['calls']);
$r = ias_plan_run_follow($aid, 'admin');
t_eq([[], [], []], [$r['moved'], $r['dropped'], $r['reinstated']], 'nothing changed: nothing named');
t_eq(0, count(ias_t_oneup_calls($n, 'scheduleimagepost')) + count(ias_t_oneup_calls($n, 'scheduletextpost')) + count(ias_t_oneup_calls($n, 'deletescheduledpost')), '…and nothing sent');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'types' => ['listing' => false]]);
delete_option('ias_oneup_verify');
