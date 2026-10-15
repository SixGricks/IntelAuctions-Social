<?php
/**
 * A post is written as of its own day (0.25.0, DJ, Oct 15, 2026: "If the auction is in 2 days it can say so in
 * the post. If the open house already passed it should not mention them. These details need planned according
 * to the proposed post date.").
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/content.php';
require_once IAS_PATH . 'includes/facts.php';
require_once IAS_PATH . 'includes/post-types.php';

$tz = ia_wp_tz();
$d = function (string $s) use ($tz) { return new DateTimeImmutable($s, $tz); };

// Counting days, by the calendar in the site's time zone.
t_eq(0, ias_days_apart($d('2026-10-20 08:00'), $d('2026-10-20 23:30')), 'the same day is 0 days');
t_eq(1, ias_days_apart($d('2026-10-20 23:30'), $d('2026-10-21 00:15')), 'just past midnight is tomorrow, not 0 days');
t_eq(-2, ias_days_apart($d('2026-10-20 08:00'), $d('2026-10-18 10:00')), 'an earlier day is negative');
t_eq(1, ias_days_apart(new DateTimeImmutable('2026-10-21 02:00', new DateTimeZone('UTC')), $d('2026-10-21 10:00')), 'a UTC time is counted on the site\'s day (it is still the 20th in Pennsylvania)');
t_eq('today', ias_days_phrase($d('2026-10-20 08:00'), $d('2026-10-20 18:00')), 'today');
t_eq('tomorrow', ias_days_phrase($d('2026-10-20 08:00'), $d('2026-10-21 10:00')), 'tomorrow');
t_eq('in 2 days', ias_days_phrase($d('2026-10-20 08:00'), $d('2026-10-22 10:00')), 'DJ\'s "in 2 days"');
t_eq('in 6 days', ias_days_phrase($d('2026-10-20 08:00'), $d('2026-10-26 10:00')), 'up to six days counted');
t_eq('', ias_days_phrase($d('2026-10-20 08:00'), $d('2026-10-27 10:00')), 'a week or more: only the date');
t_eq('', ias_days_phrase($d('2026-10-20 08:00'), $d('2026-10-19 10:00')), 'a past day: nothing');

// The facts as of the post's day.
if (!function_exists('ia_open_house_format')) { function ia_open_house_format($oh, $style = 'range', $day = '') { return trim($oh['start']->format($day !== '' ? $day : '') . ' ' . $oh['start']->format('g a')); } }
$f = ['title' => 'Farm', 'address' => '1 Road', 'street' => '1 Road', 'town' => 'Manheim', 'county' => 'Lancaster', 'state' => 'PA',
  'date' => $d('2026-10-22 10:00'), 'has_time' => true, 'bidding' => 'in_person', 'starting_bid_raw' => '', 'size_raw' => '',
  'open_houses' => [['n' => 1, 'start' => $d('2026-10-18 13:00'), 'hours' => 2], ['n' => 2, 'start' => $d('2026-10-21 16:00'), 'hours' => 2]],
  'sellers' => '', 'lead' => ['name' => 'Cindy', 'phone' => '717-555-0100'], 'office_phone' => '717-555-0000', 'kind' => 'real_estate',
  'division' => 'farm_home', 'parcels' => [], 'excerpt' => '', 'price' => ''];
$all = ias_facts_for_prompt($f);
t_eq(2, count($all['open_houses']), 'with no post day (a correction) every open house is there, as before');
t_ok(!isset($all['auction_is']), 'and no day is counted');
$p = ias_facts_for_prompt($f, $d('2026-10-20 09:00'));
t_eq(1, count($p['open_houses']), 'an open house over by the post\'s day is left out');
t_ok(strpos($p['open_houses'][0], 'October 21') !== false && mb_substr($p['open_houses'][0], -10) === '— tomorrow', 'the one still ahead is counted from the post\'s day: ' . $p['open_houses'][0]);
t_eq('in 2 days', $p['auction_is'], 'the sale is counted from the post\'s day, not today');
$q = ias_facts_for_prompt($f, $d('2026-10-20 09:00'), false);
t_ok(!isset($q['auction_is']) && count($q['open_houses']) === 1 && strpos($q['open_houses'][0], '—') === false, 'a post with no day yet: past open houses out, nothing counted');
t_ok(!isset(ias_facts_for_prompt($f, $d('2026-10-23 09:00'))['open_houses']), 'after every open house: none at all');
t_ok(ias_open_house_ahead($f, $d('2026-10-20 09:00')) && !ias_open_house_ahead($f, $d('2026-10-21 17:00')), 'an open house is ahead of the post\'s day only when it starts after it');

// When the post's day moves under its words.
$at = $d('2026-10-21 08:00');
t_eq([], ias_timing_problems('The auction is in 2 days, Thursday, October 22.', '2026-10-21 07:00', $at, [$f]), 'the same day: nothing to fix');
t_eq(1, count(ias_timing_problems('The auction is in 2 days, Thursday, October 22.', '2026-10-20 09:00', $at, [$f])), 'a new day under "in 2 days": its words count from the old day');
t_eq([], ias_timing_problems('Bidding opens Thursday, October 22. Call Cindy today.', '2026-10-20 09:00', $at, [$f]), '"call today" counts no days, and a named date is right on any day');
foreach (['See you tomorrow.', 'The sale is this Saturday.', 'Just days away now.', 'Only 3 days left to look.', "Today's auction starts at 10.", 'Next week this farm sells.', 'The auction is today at 10 am.'] as $w) {
  t_eq(1, count(ias_timing_problems($w, '2026-10-20 09:00', $at, [])), 'counts days: "' . $w . '"');
}
t_eq([], ias_timing_problems('See you tomorrow.', '', $at, []), 'a draft from before 0.25.0 (no day recorded): its words are not second-guessed');
$oh = ias_timing_problems('Come to the open house on Sunday, October 18, 1–3 pm.', '', $d('2026-10-20 09:00'), [$f]);
t_ok(count($oh) === 1 && strpos($oh[0], 'open house on Sun Oct 18') !== false, 'an older draft naming an open house that is over by its day: ' . ($oh[0] ?? ''));
t_eq(1, count(ias_timing_problems('Open house Oct. 21st, 4–6.', '2026-10-20 09:00', $d('2026-10-22 08:00'), [$f])), 'the short and ordinal forms of the date are found too');
t_eq(1, count(ias_timing_problems('Open house 10/21 from 4 to 6.', '2026-10-20 09:00', $d('2026-10-22 08:00'), [$f])), 'and 10/21');
t_eq([], ias_timing_problems('Open house Wednesday, October 21, 4–6 pm.', '2026-10-20 09:00', $d('2026-10-21 08:00'), [$f]), 'an open house still ahead of the new day is fine');
t_eq([], ias_timing_problems('Open house Sunday, October 18.', '2026-10-20 09:00', $d('2026-10-21 08:00'), [$f]), 'one already over when the words were written is a person\'s choice, not a move\'s fault');
t_eq([], ias_timing_problems('The farm sells October 18 — call Cindy.', '', $d('2026-10-20 09:00'), [$f]), 'the date alone, with no open house in the words, is not an open house');
$same = $f; $same['date'] = $d('2026-10-18 15:00');
t_eq([], ias_timing_problems('Open house the morning of the sale, October 18.', '', $d('2026-10-20 09:00'), [$same]), 'an open house on the sale\'s own day shares its date with the auction: not taken for a stale one');

t_ok(strpos(ias_timing_hold('Wed Oct 21, and its words count the days', true), 'save them') !== false, 'a person\'s words: check and save');
t_ok(strpos(ias_timing_hold('Wed Oct 21, and its words count the days', false, true), 'approve it again') !== false, 'a yes on Claude\'s words: check and approve again');
