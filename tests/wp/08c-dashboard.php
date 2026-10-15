<?php
/**
 * 0.24.0, DJ, Oct 15, 2026: "a few wordpress dashboards on /wp-admin showing …
 * some important social media metrics." The Social — this week box, as
 * rendered: the switch line, this week's counts, the Facebook numbers, the
 * next posts — and that it calls nothing.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-05 09:00'); // a Thursday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['master' => true]);

$render = function (): string { ob_start(); ias_dashboard_widget(); return (string) ob_get_clean(); };

// An empty week, posting on.
$html = $render();
t_ok(strpos($html, 'Posting is on') !== false, 'says posting is on');
t_ok(strpos($html, 'This week (Nov 2 – Nov 8)') !== false, 'the week is Monday to Sunday of today');
t_ok(strpos($html, 'No posts went out in the last 30 days') !== false && strpos($html, 'Nothing scheduled') !== false, 'with no posts: says so, twice');

// Posts in every state, by hand.
$a = ias_t_listing(['title' => '50 Widget Lane', 'address' => '50 Widget Lane, Strasburg, PA 17579, USA', 'town' => 'Strasburg', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000']);
$k = 0;
$mk = function (string $status, string $at, array $flags = []) use ($a, &$k) {
  return ias_post_insert(['slot_key' => 'test:dash:' . (++$k), 'listing_id' => $a, 'type' => 'listing', 'status' => $status, 'scheduled_at' => $at, 'window_ideal' => substr($at, 0, 10), 'content' => ['text' => 'Words about 50 Widget Lane.'], 'flags' => $flags]);
};
$mk('published', '2026-11-03 10:00', ['stats' => ['views' => 800, 'reactions' => 40, 'comments' => 8, 'shares' => 2, 'clicks' => 30]]);
$mk('published', '2026-10-20 10:00', ['stats' => ['views' => 200, 'reactions' => 10, 'comments' => 0, 'shares' => 0, 'clicks' => 10]]);
$mk('published', '2026-11-04 10:00');
$mk('approved', '2026-11-06 10:00');
$mk('waiting', '2026-11-06 15:00');
$mk('held', '2026-11-07 10:00');
$mk('failed', '2026-11-04 15:00');
$mk('planned', '2026-11-05 08:00'); // this morning, gone by: not ahead
$html = $render();
$nums = [];
preg_match_all('/<strong>([\d,]+)<\/strong><span>([^<]+)<\/span>/', $html, $m, PREG_SET_ORDER);
foreach ($m as $x) $nums[$x[2]] = (int) str_replace(',', '', $x[1]);
t_eq(2, $nums['out'] ?? -1, 'out this week: the two published in it (' . json_encode($nums) . ')');
t_eq(1, $nums['scheduled ahead'] ?? -1, 'scheduled ahead: the approved one still to come, not the planned one whose time passed');
t_eq(1, $nums['waiting for approval'] ?? -1, 'waiting for approval');
t_eq(1, $nums['need a decision'] ?? -1, 'need a decision');
t_eq(1, $nums['failed'] ?? -1, 'failed');
t_eq(1000, $nums['views'] ?? -1, 'the last 30 days: views added up over the posts with numbers');
t_eq(50, $nums['reactions'] ?? -1, '…and reactions');
t_ok(strpos($html, '2 of 3 posts have numbers') !== false && strpos($html, '10.0%</strong> engagement') !== false, 'how many have numbers, and the rate');
t_ok(preg_match('/Next up.*Approved.*Waiting for approval.*Needs a decision/s', $html) === 1, 'the next three, soonest first, with their status');
t_ok(strpos($html, 'Listing · 50 Widget Lane') !== false, 'each named by its type and listing');

// Paused, and off.
ias_settings_update(['paused' => true]);
t_ok(strpos($render(), 'Paused') !== false, 'paused: says so');
ias_settings_update(['paused' => false, 'master' => false]);
t_ok(strpos($render(), 'Posting is off') !== false, 'master off: says so');

// It calls nothing.
$calls = 0;
add_filter('pre_http_request', function ($pre) use (&$calls) { $calls++; return $pre; }, 5);
$render();
t_eq(0, $calls, 'the box makes no request of its own (OneUp, Claude, Google)');
