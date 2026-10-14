<?php
/**
 * 0.13.0, DJ, Oct 14, 2026: "I want to be able to drag posts around on the
 * calendar and choose the morning, afternoon, or evening time slot."
 *
 * The calendar's chips that can move are draggable, and the days that can
 * take one say so; a drop asks for the morning, afternoon or evening and the
 * choice is a move: the post's own minute in that window, held there like a
 * Move, clear of the posts that stay where they are; the plan then makes
 * room at once, and OneUp follows for the post and for the ones moved out of
 * its way. Never on a Sunday, never a window already passed, never on top of
 * a post that stays. The post's own Move offers the same three choices (and
 * an exact time), for a phone.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_t_oneup();
$S =& $GLOBALS['ias_t_oneup'];
$S['gbp'] = false;
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$mk = function (string $title, string $sale, string $county = 'Lancaster') use ($T, $lead) {
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing(['title' => $title, 'address' => $title . ', Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => $county,
    'stage' => 'upcoming', 'sale' => $sale, 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
};
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$back = '';
$act = function (string $action, array $post) use (&$back) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $back = (string) ($_POST['ias_back'] ?? '');
  $id = (int) ($post['ias_post'] ?? 0);
  if ($id && in_array($action, ias_push_now_actions(), true)) ias_push_now_flash($id); // what the calendar's handler does next
  $_POST = [];
};
$flash = function () { $k = 'ia_flash_' . get_current_user_id(); $h = (string) get_transient($k); delete_transient($k); return $h; };
$calendar = function () { $_GET = ['page' => IAS_PAGE_CALENDAR]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = []; return $h; };
$view = function (int $id) { $_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $id]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = []; return $h; };
$min = function (string $at) { return ias_hm_to_min(substr($at, 11, 5)); };
$W = ias_windows();

ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
$a = $mk('12 Alpha Road', '2026-11-28 10:00');
$b = $mk('7 Bravo Lane', '2026-11-27 10:00', 'Berks');
$c = $mk('3 Charlie Court', '2026-11-26 10:00', 'Lebanon');
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
t_ok(ias_post_sent($lp($a))['mode'] === 'live', 'setting out: the Listing posts are on OneUp');

// ---- 1. The calendar: what can be dragged, and where to ----
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$html = $calendar();
restore_error_handler();
t_eq([], $errors, 'the calendar renders without a notice');
$aid = (int) $lp($a)['id'];
t_ok(preg_match('/<a class="ias-chip[^"]* drag[^"]*"[^>]*draggable="true" data-post="' . $aid . '" data-slot="\d"/', $html) === 1, 'a post that can move is draggable, with its post and its window');
t_ok(strpos($html, 'data-day="2026-11-08" data-drop="0"') !== false, 'a Sunday takes no drop');
t_ok(strpos($html, 'data-day="2026-11-02" data-drop="1"') !== false && strpos($html, 'data-day="2026-11-10" data-drop="1"') !== false, 'today and the days ahead do');
t_ok(strpos($html, '<form method="post" id="ias-drop-form" hidden>') !== false && strpos($html, 'value="move_slot"') !== false && strpos($html, 'name="_wpnonce"') !== false && strpos($html, 'name="ias_from" value="calendar"') !== false, 'the drop submits a nonce-checked move, from the calendar');
t_ok(strpos($html, 'window.iasCal = ') !== false && strpos($html, '"name":"Morning"') !== false && strpos($html, '"name":"Afternoon"') !== false && strpos($html, '"name":"Evening"') !== false, '…offering the morning, afternoon and evening');
t_ok(strpos($html, 'drag it to another day') !== false, 'the calendar says posts can be dragged');

// ---- 2. Dropped on another day, in the evening ----
$day = '2026-11-12'; // a Thursday
$act('move_slot', ['ias_post' => $aid, 'ias_day' => $day, 'ias_slot' => '2', 'ias_from' => 'calendar', 'ias_back' => ias_admin_url(IAS_PAGE_CALENDAR)]);
$p = ias_post_get($aid);
$at = (string) $p['scheduled_at'];
$want = ias_slot_minute($W[2]['a'], $W[2]['b'], (string) $p['slot_key'], (int) ias_setting('jitter_minutes'), array_column(ias_move_fixed($day, $aid), 0), 180);
t_eq($day, substr($at, 0, 10), 'dropped on Thu Nov 12: it is on that day');
t_eq($want, $min($at), '…in the evening, at its own minute there (' . substr($at, 11, 5) . ')');
t_ok(($p['flags']['pinned'] ?? '') === substr($at, 0, 16) && ($p['flags']['pinned_by'] ?? '') !== '', '…held there for the plan to work around, like a Move');
$sent = ias_post_sent($p);
t_eq(substr($at, 0, 16), substr((string) ($S['scheduled'][(int) $sent['id']]['date_time'] ?? ''), 0, 16), '…and on OneUp at that time, at once');
$fl = $flash();
t_ok(strpos($fl, 'Moved to <strong>Thu Nov 12, evening</strong>') !== false, 'the page says where it went');
t_eq(ias_admin_url(IAS_PAGE_CALENDAR), $back, 'a drag on the calendar comes back to the calendar');
$moves = array_values(array_filter((array) (ias_post_get($aid)['flags']['trail'] ?? []), function ($t) { return strpos((string) $t['what'], 'Moved') === 0; }));
t_ok($moves && strpos((string) end($moves)['what'], 'to Thu Nov 12') !== false && strpos((string) end($moves)['what'], '(evening) by ') !== false, 'its history says it was moved to the evening, by whom');
ias_plan_run();
t_eq($at, (string) ias_post_get($aid)['scheduled_at'], 'the next plan leaves it there');

// ---- 3. Dropped on its own day: another time of day ----
$act('move_slot', ['ias_post' => $aid, 'ias_day' => $day, 'ias_slot' => '0']);
$at = (string) ias_post_get($aid)['scheduled_at'];
t_ok(substr($at, 0, 10) === $day && $min($at) >= $W[0]['a'] && $min($at) <= $W[0]['b'], 'dropped on its own day, in the morning: the same day, a morning time (' . substr($at, 11, 5) . ')');
$flash();

// ---- 4. Never a Sunday, a window passed, or a time of day that is not one ----
$act('move_slot', ['ias_post' => $aid, 'ias_day' => '2026-11-08', 'ias_slot' => '2']);
t_ok(strpos($flash(), 'never on Sunday') !== false && (string) ias_post_get($aid)['scheduled_at'] === $at, 'a Sunday is refused');
ias_t_clock('2026-11-02 09:00');
$act('move_slot', ['ias_post' => $aid, 'ias_day' => '2026-11-02', 'ias_slot' => '0']);
t_ok(strpos($flash(), 'the morning has passed for today') !== false && (string) ias_post_get($aid)['scheduled_at'] === $at, 'this morning, once it has passed, is refused');
$act('move_slot', ['ias_post' => $aid, 'ias_day' => '2026-11-02', 'ias_slot' => '2']);
$t = (string) ias_post_get($aid)['scheduled_at'];
t_ok(substr($t, 0, 10) === '2026-11-02' && $min($t) >= $W[2]['a'], 'this evening is fine — inside the veto window, a person\'s move is the yes (' . substr($t, 11, 5) . ')');
t_ok(ias_post_sent(ias_post_get($aid))['mode'] === 'live', '…and it is on OneUp for tonight');
$flash();
$act('move_slot', ['ias_post' => $aid, 'ias_day' => $day, 'ias_slot' => 'evening']);
t_ok(strpos($flash(), 'choose the morning, afternoon or evening') !== false, 'a time of day that is not one is refused');
$act('move_slot', ['ias_post' => $aid, 'ias_day' => $day, 'ias_slot' => '7']);
t_ok(strpos($flash(), 'no such time of day') !== false, '…and one past the windows');
ias_t_clock('2026-11-02 05:30');

// ---- 5. Not on top of a post that stays there ----
$bid = (int) $lp($b)['id'];
$bwas = (string) ias_post_get($bid)['scheduled_at'];
$act('move_slot', ['ias_post' => $bid, 'ias_day' => '2026-11-02', 'ias_slot' => '2']); // this evening, where 12 Alpha Road was put
$fl = $flash();
t_ok(strpos($fl, 'no room in the evening on Mon Nov 2') !== false && strpos($fl, '12 Alpha Road is held there') !== false, 'into a window a moved post holds: refused, naming it');
t_eq($bwas, (string) ias_post_get($bid)['scheduled_at'], '…and nothing moved');

// ---- 6. The plan makes room, and OneUp follows ----
$cid = (int) $lp($c)['id'];
$cp = ias_post_get($cid);
$cday = substr((string) $cp['scheduled_at'], 0, 10);
$csi = ias_slot_index($W, $min((string) $cp['scheduled_at']));
t_ok(empty($cp['flags']['pinned']) && $csi >= 0 && $cday >= '2026-11-04', 'a post the plan placed (' . $cp['scheduled_at'] . ')');
$act('move_slot', ['ias_post' => $bid, 'ias_day' => $cday, 'ias_slot' => (string) $csi]);
$fl = $flash();
$bp = ias_post_get($bid);
$cp = ias_post_get($cid);
t_ok(substr((string) $bp['scheduled_at'], 0, 10) === $cday && ias_slot_index($W, $min((string) $bp['scheduled_at'])) === $csi, 'dropped into the window it had: the dragged post takes it');
t_ok(abs(strtotime((string) $bp['scheduled_at']) - strtotime((string) $cp['scheduled_at'])) >= 3 * HOUR_IN_SECONDS, 'the post that was there moved out of its way (' . $cp['scheduled_at'] . ')');
t_ok(strpos($fl, 'To make room the plan moved Listing · 3 Charlie Court') !== false, '…and the page says so');
$csent = ias_post_sent($cp);
t_eq(substr((string) $cp['scheduled_at'], 0, 16), substr((string) ($S['scheduled'][(int) $csent['id']]['date_time'] ?? ''), 0, 16), '…and its copy on OneUp followed at once');

// ---- 7. The post's own Move: the same three choices, or an exact time ----
$h = $view($cid);
t_ok(substr_count($h, 'name="ias_slot"') === 4 && strpos($h, '<strong>Morning</strong>') !== false && strpos($h, '<strong>Evening</strong>') !== false && strpos($h, 'name="ias_time"') !== false, 'a post\'s Move offers the morning, afternoon and evening, or an exact time');
$act('move', ['ias_post' => $cid, 'ias_day' => '2026-11-17', 'ias_slot' => '1']);
$t = (string) ias_post_get($cid)['scheduled_at'];
t_ok(substr($t, 0, 10) === '2026-11-17' && ias_slot_index($W, $min($t)) === 1, 'Move with the afternoon: an afternoon time that day (' . substr($t, 11, 5) . ')');
$flash();
$act('move', ['ias_post' => $cid, 'ias_day' => '2026-11-18', 'ias_slot' => 'exact', 'ias_time' => '15:20']);
t_eq('2026-11-18 15:20:00', (string) ias_post_get($cid)['scheduled_at'], 'Move with an exact time: that time, as before');
$flash();

// ---- 8. What cannot be dragged ----
$f = ias_post_get($cid)['flags'];
ias_post_update($cid, ['locked' => 1]);
t_ok(preg_match('/draggable="true" data-post="' . $cid . '"/', $calendar()) === 0, 'a post someone changed in OneUp is not draggable');
ias_post_update($cid, ['locked' => 0]);
$act('move_slot', ['ias_post' => $cid, 'ias_day' => '2026-11-19', 'ias_slot' => '2']);
$flash();
ias_post_update($cid, ['status' => 'published']);
t_ok(preg_match('/draggable="true" data-post="' . $cid . '"/', $calendar()) === 0, 'nor one that went out');
$act('move_slot', ['ias_post' => $cid, 'ias_day' => '2026-11-20', 'ias_slot' => '2']);
t_ok(strpos($flash(), 'cannot be moved') !== false, '…and moving it anyway is refused');

// ---- 9. A day filled by hand: what the plan can no longer fit is dropped, and the page says so ----
$o = ias_t_listing(['title' => '4 Oscar Way', 'address' => '4 Oscar Way, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'York',
  'stage' => 'upcoming', 'sale' => '2026-11-28 13:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead,
  'gallery' => [ias_t_photo('Oscar 1'), ias_t_photo('Oscar 2'), ias_t_photo('Oscar 3')], 'oh' => [['2026-11-21 13:00', 2]]]);
ias_sync(); ias_drafts_run(30);
$oh = ias_post_by_slot('open_house:' . $o . ':20261121');
$wpdb->update(ias_table('posts'), ['window_start' => '2026-11-16', 'window_ideal' => '2026-11-16', 'window_end' => '2026-11-16'], ['id' => $oh['id']]);
ias_plan_run();
t_eq('2026-11-16', substr((string) ias_post_get((int) $oh['id'])['scheduled_at'], 0, 10), 'an Open House post that can only go on Mon Nov 16');
ias_post_update($cid, ['status' => 'planned']);
$all = '';
foreach ([[$aid, 0], [$bid, 1], [$cid, 2]] as [$pid, $si]) { $act('move_slot', ['ias_post' => $pid, 'ias_day' => '2026-11-16', 'ias_slot' => (string) $si]); $all .= $flash(); }
t_eq('dropped', ias_post_get((int) $oh['id'])['status'], 'with the morning, afternoon and evening of its one day taken by hand, the plan drops it');
t_ok(strpos($all, 'The plan dropped Open house · 4 Oscar Way') !== false, '…and the page says so, with how to put it back');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'types' => ['listing' => false]]);
delete_option('ias_oneup_verify');
