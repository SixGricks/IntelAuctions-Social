<?php
/**
 * The scheduler's view of the posts table (ias_engine_posts): what is on
 * OneUp counts as sent (and the old dry run's record no longer does), and a
 * property's Open House and Auction Week posts wait while its Listing post
 * waits for photos.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/post-types.php';
require_once IAS_PATH . 'includes/candidates.php';
require_once IAS_PATH . 'includes/scheduler.php';

$tz = new DateTimeZone('America/New_York');
$now = new DateTimeImmutable('2026-11-02 05:30', $tz); // Monday
$cfg = ias_engine_cfg(ias_settings_defaults(), $now);
$row = function (array $o) {
  return $o + [
    'id' => 1, 'type' => 'listing', 'status' => 'planned', 'listing_ids' => [1], 'county' => 'Lancaster', 'division' => 'farm_home',
    'score' => 70.0, 'guaranteed' => 1, 'lane' => 'B', 'window_start' => '2026-11-02', 'window_ideal' => '2026-11-02', 'window_end' => '2026-11-09',
    'scheduled_at' => null, 'flags' => [], 'locked' => 0,
  ];
};
$by_key = function (array $posts) { $o = []; foreach ($posts as $p) $o[$p['key']] = $p; return $o; };

// On OneUp, inside the veto window: it stays where it is — the plan settles.
$e = $by_key(ias_engine_posts([$row(['slot_key' => 'listing:1', 'scheduled_at' => '2026-11-03 19:10:00', 'flags' => ['oneup' => ['id' => '5', 'hash' => 'abc']]])], $now, 2880));
t_eq('2026-11-03 19:10', $e['listing:1']['fixed_at'], 'a post on OneUp, inside the 48 hours, is fixed in place');
t_ok($e['listing:1']['pushed'], '…and counts as on the OneUp calendar');
// The old dry run's record (before 0.12.0) is not "sent": nothing went anywhere.
$e = $by_key(ias_engine_posts([$row(['slot_key' => 'listing:1', 'scheduled_at' => '2026-11-03 19:10:00', 'flags' => ['dry_hash' => 'abc']])], $now, 2880));
t_ok($e['listing:1']['fixed_at'] === null && !$e['listing:1']['pushed'], 'an old dry-run record does not count as sent (there is no dry run now)');
$e = $by_key(ias_engine_posts([$row(['slot_key' => 'listing:1', 'scheduled_at' => '2026-11-03 19:10:00'])], $now, 2880));
t_eq(null, $e['listing:1']['fixed_at'], 'one never sent is not: the plan gives it a time outside the veto window');
$plan = ias_schedule(array_values($e), $cfg);
t_ok(($plan['at']['listing:1'] ?? '') >= '2026-11-04 05:30', '…which it does (' . ($plan['at']['listing:1'] ?? 'none') . ')');
$e = $by_key(ias_engine_posts([$row(['slot_key' => 'listing:1', 'scheduled_at' => '2026-11-05 19:10:00', 'flags' => ['oneup' => ['id' => '5', 'hash' => 'abc']]])], $now, 2880));
t_eq('2026-11-05 19:10', $e['listing:1']['current_at'], 'a sent post outside the 48 hours keeps its time unless something forces a move');
t_ok($e['listing:1']['pushed'] && $e['listing:1']['fixed_at'] === null, '…and is not churned to fill a thin day');

// A listing waiting for photos: its Open House post waits too — the Listing post goes first.
$rows = [
  $row(['slot_key' => 'listing:1', 'flags' => ['needs' => ['photos']], 'window_start' => null, 'window_ideal' => null, 'window_end' => null]),
  $row(['id' => 2, 'slot_key' => 'open_house:1:20261112', 'type' => 'open_house', 'guaranteed' => 0, 'score' => 90.0, 'window_start' => '2026-11-05', 'window_ideal' => '2026-11-07', 'window_end' => '2026-11-09']),
];
$e = $by_key(ias_engine_posts($rows, $now, 2880));
t_ok(!empty($e['open_house:1:20261112']['blocked']), 'an Open House post is blocked while its Listing post waits for photos');
$plan = ias_schedule(array_values($e), $cfg);
t_ok(isset($plan['blocked']['open_house:1:20261112']) && !isset($plan['at']['open_house:1:20261112']), '…so the plan does not place it (no Open House post before the Listing post)');
$rows[0]['status'] = 'skipped';
$e = $by_key(ias_engine_posts($rows, $now, 2880));
t_ok(empty($e['open_house:1:20261112']['blocked']), 'once the Listing post is skipped (photos never came), the Open House post goes on its own');
$rows[0] = $row(['slot_key' => 'listing:1']);
$plan = ias_schedule(array_values($by_key(ias_engine_posts($rows, $now, 2880))), $cfg);
t_ok(($plan['at']['listing:1'] ?? 'z') < ($plan['at']['open_house:1:20261112'] ?? ''), 'with photos, the Listing post comes first and the Open House post after it');
