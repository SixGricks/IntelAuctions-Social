<?php
/**
 * The posting windows by name, and the minute a post takes when a person puts
 * it in one (DJ, Oct 14, 2026: "I want to be able to drag posts around on the
 * calendar and choose the morning, afternoon, or evening time slot").
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/post-types.php';
require_once IAS_PATH . 'includes/candidates.php';
require_once IAS_PATH . 'includes/scheduler.php';

$w = ias_slot_names(ias_settings_defaults()['slots']);
t_eq(['Morning', 'Afternoon', 'Evening'], array_column($w, 'name'), 'the three windows are the morning, the afternoon and the evening');
t_eq(['6:00 am', '7:30 am'], [$w[0]['from'], $w[0]['to']], '…the morning 6:00–7:30 am');
t_eq(['11:30 am', '1:00 pm'], [$w[1]['from'], $w[1]['to']], '…the afternoon 11:30 am–1:00 pm');
t_eq(['7:00 pm', '9:00 pm'], [$w[2]['from'], $w[2]['to']], '…the evening 7:00–9:00 pm');
t_eq(['Morning', 'Morning 2', 'Evening'], array_column(ias_slot_names([['19:00', '21:00'], ['09:00', '10:00'], ['06:00', '07:00']]), 'name'), 'in time order whatever the order typed, and a second morning is "Morning 2"');
t_eq([], ias_slot_names([['nonsense', '07:00'], ['09:00', '08:00']]), 'a window that is not one is left out');
t_eq(2, ias_slot_index($w, 19 * 60 + 12), '7:12 pm is in the evening');
t_eq(-1, ias_slot_index($w, 15 * 60), '3:00 pm is in no window');

// The minute: the post's own shift in the window, the same every time.
$e = $w[2];
$m = ias_slot_minute($e['a'], $e['b'], 'listing:42', 25, [], 180);
t_ok($m !== null && $m > $e['a'] && $m <= $e['a'] + 25 && $m % 60 !== 0, 'a post put in the evening takes its own shifted minute there (' . intdiv((int) $m, 60) . ':' . sprintf('%02d', (int) $m % 60) . ')');
t_eq($m, ias_slot_minute($e['a'], $e['b'], 'listing:42', 25, [], 180), '…the same minute every time');
t_ok(ias_slot_minute($e['a'], $e['b'], 'listing:43', 25, [], 180) !== null, '…another post its own');
$m2 = ias_slot_minute($e['a'], $e['b'], 'listing:42', 25, [$m], 30);
t_ok($m2 !== null && abs($m2 - $m) >= 30, 'with a post that stays there, the nearest minute far enough from it');
t_eq(null, ias_slot_minute($e['a'], $e['b'], 'listing:42', 25, [20 * 60], 180), 'no room in the window when a post that stays there is too close: null');
$mm = ias_slot_minute($w[0]['a'], $w[0]['b'], 'listing:42', 25, [], 180, 7 * 60 + 10);
t_ok($mm !== null && $mm >= 7 * 60 + 10 && $mm <= $w[0]['b'], 'today: never before the time it may go (' . (int) $mm . ')');
t_eq(null, ias_slot_minute($w[0]['a'], $w[0]['b'], 'listing:42', 25, [], 180, 8 * 60), 'a window already passed today: null');
t_ok(ias_slot_minute($w[1]['a'], $w[1]['b'], 'x', 0, [], 180) === $w[1]['a'], 'with no shift set, the window\'s start');
