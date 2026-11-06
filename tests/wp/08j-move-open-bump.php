<?php
/**
 * 0.28.0, DJ, Nov 6, 2026: "When i go in to move a post, I only want to see
 * open slots, or an option to 'bump a post' and then it asks where the
 * 'bumped Post' should be moved it."
 *
 * Move… on a post's page lists only the open slots (a posting window with no
 * post in it, room for this post, never Sunday, never past its event), or
 * Bump a post: a slot one movable post has, then where that post goes. The
 * bumped post moves first, this one takes its place; both pinned, nothing else
 * on the calendar moves.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$counties = ['Lancaster', 'Berks', 'Lebanon', 'Chester', 'York', 'Dauphin'];
$ls = [];
for ($i = 0; $i < 8; $i++) {
  $gal = []; for ($j = 1; $j <= 4; $j++) $gal[] = ias_t_photo("B$i $j");
  $ls[] = ias_t_listing(['title' => ($i + 1) . ' Bump Road', 'address' => ($i + 1) . ' Bump Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => $counties[$i % 6],
    'stage' => 'upcoming', 'sale' => date('Y-m-d', strtotime('2026-11-20 +' . $i . ' days')) . ' 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
}
ias_settings_update(['generate_per_run' => 30, 'types' => ['listing' => true]]);
ias_sync(); ias_drafts_run(30); ias_plan_run();
$ids = array_map(function ($l) { return (int) ias_post_by_slot('listing:' . $l)['id']; }, $ls);
$wins = ias_windows();
$where = function (int $id) use ($wins) { $p = ias_post_get($id); $at = (string) $p['scheduled_at']; return [substr($at, 0, 10), ias_slot_index($wins, ias_hm_to_min(substr($at, 11, 5)))]; };
$times = function (array $except) {
  $out = [];
  foreach (ias_posts_query(['limit' => 200]) as $p) if (!in_array((int) $p['id'], $except, true)) $out[(int) $p['id']] = (string) $p['scheduled_at'] . '|' . $p['status'];
  return $out;
};
$act = function (string $action, int $id, array $post) {
  $_POST = ['ias_cal_action' => $action, 'ias_post' => (string) $id] + $post;
  do_action('ias_calendar_action', $action);
  $_POST = [];
  $k = 'ia_flash_' . get_current_user_id(); $h = (string) get_transient($k); delete_transient($k); return $h;
};
wp_set_current_user(1);
t_ok(count($wins) >= 3, 'three posting windows to choose from');
$gap = 60 * (int) ias_setting('min_gap_hours', 3);
$spaced = function (int $id) use ($gap) { // every post that day at least the gap from this one (Codex on PR #15: not only the fixed ones)
  $p = ias_post_get($id); $at = (string) $p['scheduled_at']; $m = ias_hm_to_min(substr($at, 11, 5)); $near = [];
  foreach (ias_posts_query(['from' => substr($at, 0, 10), 'to' => substr($at, 0, 10), 'limit' => 50]) as $o) {
    if ((int) $o['id'] === $id || empty($o['scheduled_at']) || !in_array($o['status'], array_merge(ias_live_statuses(), ['published', 'human']), true)) continue;
    if (abs(ias_hm_to_min(substr((string) $o['scheduled_at'], 11, 5)) - $m) < $gap) $near[] = (int) $o['id'];
  }
  return $near;
};

// ---- The slots Move offers ----
$a = ias_post_get($ids[0]);
$slots = ias_move_slots($a);
$open = array_values(array_filter($slots, function ($s) { return $s['open']; }));
$taken = array_values(array_filter($slots, function ($s) { return $s['post'] !== null; }));
t_ok(count($open) > 0 && count($taken) > 0, 'some slots are open and some have a post that can be bumped');
$sunday = array_filter($slots, function ($s) { return date('N', strtotime($s['day'])) === '7'; });
t_eq([], array_values($sunday), 'never a Sunday');
$hard = ias_post_hard_end($a);
t_ok($hard !== null && max(array_column($slots, 'day')) <= $hard, 'never past its own event (a Listing post: the day before its sale)');
// Never a day whose windows ias_move_problem() would refuse: the horizon's own day is past now + 30 days by then (Codex on PR #15).
$free = ias_move_slots(['id' => 0, 'type' => 'manual', 'slot_key' => 'manual:x', 'flags' => []]);
$lastday = max(array_column($free, 'day'));
t_ok($lastday < ias_now()->modify('+' . max(30, (int) ias_setting('plan_days', 30)) . ' days')->format('Y-m-d'), 'the last day offered is before the horizon\'s day (' . $lastday . ')');
$bad = 0;
foreach ($free as $s) if ($s['open'] && ias_move_problem($s['day'] . ' ' . ias_min_to_hm($wins[$s['si']]['b'])) !== '' && $s['day'] !== ias_now()->format('Y-m-d')) $bad++;
t_eq(0, $bad, '…and every open slot offered is a time a move accepts');
$clash = 0;
foreach ($open as $s) foreach (ias_posts_query(['from' => $s['day'], 'to' => $s['day'], 'limit' => 50]) as $o) {
  if ((int) $o['id'] !== $ids[0] && in_array($o['status'], ias_live_statuses(), true) && ias_slot_index($wins, ias_hm_to_min(substr((string) $o['scheduled_at'], 11, 5))) === $s['si']) $clash++;
}
t_eq(0, $clash, 'an open slot has no other post in it');

// ---- The form: open slots or a bump, no free-typed time ----
$form = ias_move_form($a);
t_ok(strpos($form, 'name="ias_open"') !== false && strpos($form, 'Open slot') !== false, 'Move lists the open slots');
t_ok(strpos($form, 'name="ias_bump"') !== false && strpos($form, 'Move the bumped post to') !== false, '…or Bump a post, asking where it goes');
t_ok(strpos($form, 'type="time"') === false, '…and no free-typed time');
t_ok(strpos($form, 'where this post is now') !== false, 'the bumped post may take this post\'s place (a swap)');

// ---- Move to an open slot ----
$o = $open[count($open) - 1];
$before = $times([$ids[0]]);
$fl = $act('move_open', $ids[0], ['ias_open' => $o['day'] . '|' . $o['si']]);
t_eq([$o['day'], $o['si']], $where($ids[0]), 'moved into the open slot it was given');
t_eq($o['day'] . ' ' . substr((string) ias_post_get($ids[0])['scheduled_at'], 11, 5), (string) ias_post_get($ids[0])['flags']['pinned'], '…pinned there');
t_eq($before, $times([$ids[0]]), '…and nothing else moved');
t_eq([], $spaced($ids[0]), '…at least the gap from every post that day, planned ones too (they do not move out of its way)');
t_ok(strpos($fl, 'Moved to') !== false, '…and the page says so');

// A slot a post has is not open: refused, nothing changes.
$t = $taken[0];
$was = $where($ids[0]);
$fl = $act('move_open', $ids[0], ['ias_open' => $t['day'] . '|' . $t['si']]);
t_ok(strpos($fl, 'no longer open') !== false, 'a slot with a post in it is refused as an open slot');
t_eq($was, $where($ids[0]), '…and the post stays where it was');

// ---- Bump: the other post goes where the person says, this one takes its place ----
$a = ias_post_get($ids[0]);
$mine = $where($ids[0]);
$pick = null;
foreach (ias_move_slots($a) as $s) if ($s['post'] !== null && ias_post_hard_end($s['post']) >= $mine[0]) { $pick = $s; break; }
t_ok($pick !== null, 'a post to bump that may go where this one is now');
$bid = (int) $pick['post']['id'];
$before = $times([$ids[0], $bid]);
$fl = $act('move_bump', $ids[0], ['ias_bump' => $pick['day'] . '|' . $pick['si'] . '|' . $bid, 'ias_bump_to' => $mine[0] . '|' . $mine[1]]);
t_eq([$pick['day'], $pick['si']], $where($ids[0]), 'this post took the bumped post\'s slot');
t_eq($mine, $where($bid), '…and the bumped post went where it was told (here, a swap)');
t_ok(!empty(ias_post_get($bid)['flags']['pinned']) && !empty(ias_post_get($ids[0])['flags']['pinned']), '…both pinned there');
t_eq($before, $times([$ids[0], $bid]), '…nothing else moved');
t_eq([[], []], [$spaced($ids[0]), $spaced($bid)], '…and both keep the gap from every post on their days');
t_ok(strpos($fl, 'Bumped <strong>') !== false, 'the page names the bumped post');
$trail = implode(' ', array_column((array) (ias_post_get($bid)['flags']['trail'] ?? []), 'what'));
t_ok(strpos($trail, 'Bumped by') !== false || strpos(wp_json_encode(ias_post_get($bid)['flags']), 'Bumped by') !== false, '…and the bumped post\'s history says who bumped it');

// Never past the bumped post's event: refused, nothing changes.
$a = ias_post_get($ids[1]);
$pick = null;
foreach (ias_move_slots($a) as $s) if ($s['post'] !== null) { $pick = $s; break; }
$bid = (int) $pick['post']['id'];
$late = date('Y-m-d', strtotime(ias_post_hard_end($pick['post']) . ' +1 day'));
if (date('N', strtotime($late)) === '7') $late = date('Y-m-d', strtotime($late . ' +1 day'));
$before = $times([]);
$fl = $act('move_bump', $ids[1], ['ias_bump' => $pick['day'] . '|' . $pick['si'] . '|' . $bid, 'ias_bump_to' => $late . '|0']);
t_ok(strpos($fl, 'cannot go after') !== false, 'a bumped post is never sent past its own event');
t_eq($before, $times([]), '…and nothing moved');

// Where the bumped post goes must be open: a slot another post has is refused.
$other = null;
foreach (ias_move_slots($a) as $s) if ($s['post'] !== null && (int) $s['post']['id'] !== $bid && $s['day'] <= ias_post_hard_end($pick['post'])) { $other = $s; break; }
if ($other) {
  $fl = $act('move_bump', $ids[1], ['ias_bump' => $pick['day'] . '|' . $pick['si'] . '|' . $bid, 'ias_bump_to' => $other['day'] . '|' . $other['si']]);
  t_ok(strpos($fl, 'no longer open') !== false, 'the bumped post cannot be sent into a slot another post has');
  t_eq($before, $times([]), '…and nothing moved');
}

// Without choosing where it goes: refused.
$fl = $act('move_bump', $ids[1], ['ias_bump' => $pick['day'] . '|' . $pick['si'] . '|' . $bid]);
t_ok(strpos($fl, 'choose where the bumped post goes') !== false, 'a bump needs a place for the bumped post');

// ---- Codex's case on PR #15: a planned neighbour just past the window still keeps its 3 hours ----
ias_settings_update(['slots' => [['08:00', '10:30'], ['11:00', '13:00'], ['19:00', '21:00']]]);
$day = date('Y-m-d', strtotime('2026-11-02 +10 days'));
while (date('N', strtotime($day)) === '7' || ias_posts_query(['from' => $day, 'to' => $day, 'limit' => 5])) $day = date('Y-m-d', strtotime($day . ' +1 day'));
$y = ias_post_get($ids[5]);
$yf = (array) $y['flags']; unset($yf['pinned'], $yf['pinned_by'], $yf['edited_by'], $yf['released_by'], $yf['oneup']);
ias_post_update($ids[5], ['status' => 'planned', 'scheduled_at' => $day . ' 11:05:00', 'flags' => $yf]); // planned, not held in place
$x = ias_post_get($ids[6]);
$offer = null;
foreach (ias_move_slots($x) as $s) if ($s['day'] === $day && $s['si'] === 0) $offer = $s;
t_ok($offer && $offer['open'], 'the morning before a planned 11:05 post is offered: there is room up to 8:05');
$fl = $act('move_open', $ids[6], ['ias_open' => $day . '|0']);
$m = ias_hm_to_min(substr((string) ias_post_get($ids[6])['scheduled_at'], 11, 5));
t_ok(substr((string) ias_post_get($ids[6])['scheduled_at'], 0, 10) === $day && $m <= 665 - $gap, 'moved there at ' . ias_min_to_hm($m) . ', three hours clear of the planned 11:05 post');
t_eq($day . ' 11:05:00', (string) ias_post_get($ids[5])['scheduled_at'], '…which stays where it was');
