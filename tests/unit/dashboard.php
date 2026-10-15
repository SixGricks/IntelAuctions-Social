<?php
/**
 * The Dashboard box (admin-dashboard.php, 0.24.0): the week it reads, and the
 * numbers it says, from the posts alone.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/oneup.php';
require_once IAS_PATH . 'includes/stats.php';
require_once IAS_PATH . 'includes/admin-dashboard.php';

$tz = new DateTimeZone('America/New_York');
t_eq(['2026-10-12', '2026-10-18'], ias_dashboard_week(new DateTimeImmutable('2026-10-15 09:00', $tz)), 'a Thursday: Monday to Sunday of its week');
t_eq(['2026-10-12', '2026-10-18'], ias_dashboard_week(new DateTimeImmutable('2026-10-12 00:00', $tz)), 'Monday itself: the same week');
t_eq(['2026-10-12', '2026-10-18'], ias_dashboard_week(new DateTimeImmutable('2026-10-18 23:30', $tz)), 'a Sunday belongs to the week before it, never to a week that starts tomorrow');

$now = new DateTimeImmutable('2026-10-15 09:00', $tz);
$n = 0;
$p = function (string $status, string $at, array $flags = [], string $type = 'listing') use (&$n) {
  return ['id' => ++$n, 'status' => $status, 'scheduled_at' => $at, 'flags' => $flags, 'type' => $type];
};
$week = [
  $p('published', '2026-10-13 10:00'), $p('published', '2026-10-14 15:00'),
  $p('planned', '2026-10-16 10:00'), $p('approved', '2026-10-17 10:00'), $p('pushed', '2026-10-15 19:00'),
  $p('planned', '2026-10-15 08:00'), // its time passed without going out: not "ahead"
  $p('waiting', '2026-10-16 15:00'), $p('held', '2026-10-17 15:00'), $p('failed', '2026-10-14 10:00'),
  $p('skipped', '2026-10-16 10:00'), $p('cancelled', '2026-10-17 10:00'),
];
$recent = [
  $p('published', '2026-10-01 10:00', ['stats' => ['views' => 1000, 'reactions' => 50, 'comments' => 10, 'shares' => 5, 'clicks' => 35]]),
  $p('published', '2026-10-05 10:00', ['stats' => ['views' => 500, 'reactions' => 20, 'comments' => 0, 'shares' => 0, 'clicks' => 5]]),
  $p('published', '2026-10-13 10:00'), // no numbers yet
  $p('skipped', '2026-10-08 10:00'),   // never out
];
$ahead = [
  $p('planned', '2026-10-15 08:00'), // already passed
  $p('pushed', '2026-10-15 19:00'), $p('waiting', '2026-10-16 15:00'), $p('planned', '2026-10-16 10:00'), $p('approved', '2026-10-17 10:00'),
  $p('skipped', '2026-10-15 12:00'),
];
$d = ias_dashboard_numbers($week, $recent, $ahead, $now);
t_eq(['out' => 2, 'ahead' => 3, 'waiting' => 1, 'held' => 1, 'failed' => 1, 'off' => 2], $d['week'], 'this week: out, ahead (planned, approved or pushed and still to come), waiting, held, failed, off');
t_eq(['counted' => 2, 'reactions' => 70, 'comments' => 10, 'shares' => 5, 'clicks' => 40, 'views' => 1500, 'published' => 3, 'rate' => 8.3], $d['fb'], 'the last 30 days on Facebook: the numbers added up over the posts that have them, and the rate over the total');
t_eq(['2026-10-15 19:00', '2026-10-16 15:00', '2026-10-16 10:00'], array_map(function ($p) { return $p['scheduled_at']; }, $d['next']), 'the next three posts still to come, in the order given (soonest first from the query), a skipped one never');

$d = ias_dashboard_numbers([], [], [], $now);
t_eq(0, $d['week']['out'] + $d['week']['ahead'], 'nothing this week: zeros');
t_eq(null, $d['fb']['rate'], 'no views: no rate');
t_eq([], $d['next'], 'nothing ahead');
