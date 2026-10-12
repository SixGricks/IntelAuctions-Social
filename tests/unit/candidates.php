<?php
/** Which posts exist and their windows (includes/candidates.php). */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/post-types.php';
require_once IAS_PATH . 'includes/candidates.php';

$tz = new DateTimeZone('America/New_York');
$dt = function (string $s) use ($tz) { return new DateTimeImmutable($s, $tz); };
$listing = function (array $over) use ($dt) {
  $sale = $over['sale'] ?? '';
  unset($over['sale']);
  return array_merge([
    'id' => 1, 'title' => 'Test', 'status' => 'publish', 'password' => false, 'stage' => 'upcoming',
    'date' => $sale !== '' ? $dt($sale . ' 10:00') : null, 'sale_day' => $sale,
    'starting_bid' => null, 'county' => 'Lancaster', 'division' => 'farm_home', 'kind' => 'real_estate',
    'price' => '', 'open_houses' => [], 'has_photos' => true, 'photos_at' => '', 'promoted_at' => '2026-11-02',
    'announced_at' => '', 'published_at' => '2026-10-20', 'webhook_pushed' => '',
  ], $over);
};
$opts = ['weights' => ias_settings_defaults()['weights'], 'bid_tiers' => ias_settings_defaults()['bid_tiers'], 'installed_at' => '2026-10-12', 'live_since' => ''];
$today = $dt('2026-11-02 05:00'); // a Monday

t_eq(1.2, ias_bid_multiplier(600000.0, $opts['bid_tiers']), '$600k is ×1.2');
t_eq(1.1, ias_bid_multiplier(250000.0, $opts['bid_tiers']), '$250k is ×1.1');
t_eq(1.0, ias_bid_multiplier(100000.0, $opts['bid_tiers']), '$100k is ×1.0');
t_eq(0.9, ias_bid_multiplier(99999.0, $opts['bid_tiers']), 'under $100k is ×0.9');
t_eq(0.9, ias_bid_multiplier(null, $opts['bid_tiers']), 'no starting bid is ×0.9');

// A listing promoted today, sale Nov 21, open house Sat Nov 14.
$oh = ['n' => 1, 'start' => $dt('2026-11-14 10:00'), 'end' => $dt('2026-11-14 12:00'), 'hours' => 2.0];
$c = ias_build_candidates([$listing(['id' => 7, 'sale' => '2026-11-21', 'starting_bid' => 300000.0, 'open_houses' => [$oh]])], $today, $opts);
$l = $c['listing:7'];
t_eq(['2026-11-02', '2026-11-02', '2026-11-09'], [$l['window_start'], $l['window_ideal'], $l['window_end']], 'listing: promotion day → same day → +7');
t_eq(77.0, $l['score'], 'listing priority 70 × 1.1');
t_ok($l['guaranteed'] && $l['lane'] === 'B', 'listing is guaranteed, lane B');
$o = $c['open_house:7:20261114'];
t_eq(['2026-11-07', '2026-11-09', '2026-11-11'], [$o['window_start'], $o['window_ideal'], $o['window_end']], 'open house: 7 → 5 → 3 days before');
t_ok(!$o['guaranteed'], 'open house can be dropped');
$a = $c['auction_week:2026-11-21:lancaster'];
t_eq(['2026-11-11', '2026-11-14', '2026-11-18'], [$a['window_start'], $a['window_ideal'], $a['window_end']], 'auction week: 10 → 7 → 3 days before');

// Sunday: an ideal that falls on Sunday moves to Monday.
$c = ias_build_candidates([$listing(['id' => 8, 'sale' => '2026-11-22'])], $today, $opts); // sale Sunday → ideal A−7 = Sun Nov 15
t_eq('2026-11-16', $c['auction_week:2026-11-22:lancaster']['window_ideal'], 'a Sunday ideal moves to Monday');

// Same-day sales share one Auction Week post.
$c = ias_build_candidates([
  $listing(['id' => 11, 'sale' => '2026-11-18', 'county' => 'Northumberland', 'starting_bid' => 50000.0]),
  $listing(['id' => 12, 'sale' => '2026-11-18', 'county' => 'Northumberland', 'starting_bid' => 600000.0]),
  $listing(['id' => 13, 'sale' => '2026-11-18', 'county' => 'Northumberland']),
], $today, $opts);
$aw = array_values(array_filter($c, function ($r) { return $r['type'] === 'auction_week'; }));
t_eq(1, count($aw), 'three same-day sales: one Auction Week post');
t_eq([11, 12, 13], $aw[0]['listing_ids'], '…covering all three');
t_eq(96.0, $aw[0]['score'], '…at the best multiplier (80 × 1.2)');
$c = ias_build_candidates([
  $listing(['id' => 14, 'sale' => '2026-11-18', 'town' => 'Sunbury', 'county' => 'Northumberland']),
  $listing(['id' => 15, 'sale' => '2026-11-18', 'town' => 'Sunbury', 'county' => 'Northumberland']),
  $listing(['id' => 16, 'sale' => '2026-11-18', 'town' => 'Narvon', 'county' => 'Lancaster']),
], $today, $opts);
t_eq([14, 15], $c['auction_week:2026-11-18:sunbury']['listing_ids'], 'same-day sales merge only at the same place: the Sunbury pair share a post');
t_eq([16], $c['auction_week:2026-11-18:narvon']['listing_ids'], '…and a Narvon sale that day keeps its own');

// Results: a Saturday sale's results start Monday; liquidations need no price.
$res_today = $dt('2026-11-23 05:00'); // Monday
$c = ias_build_candidates([
  $listing(['id' => 21, 'stage' => 'past', 'sale' => '2026-11-21', 'price' => '$425,000']),
  $listing(['id' => 22, 'stage' => 'past', 'sale' => '2026-11-21', 'kind' => 'liquidation']),
  $listing(['id' => 23, 'stage' => 'past', 'sale' => '2026-11-19']), // no price yet
], $res_today, $opts);
$r = $c['results:2026-11-21:lancaster'];
t_eq('2026-11-23', $r['window_start'], 'a Saturday sale\'s Results start Monday');
t_eq(null, $r['window_end'], 'Results stay open until approved');
t_eq([21, 22], $r['listing_ids'], 'same-day results share a post, liquidation included');
t_ok(!isset($c['results:2026-11-19:lancaster']), 'no Results post before there is a price');
t_ok($r['lane'] === 'A' && $r['guaranteed'], 'Results: lane A, guaranteed');
$c = ias_build_candidates([$listing(['id' => 24, 'stage' => 'past', 'sale' => '2025-06-01', 'price' => '$1'])], $res_today, $opts);
t_ok(!$c, 'installing the plugin does not unearth old results');

// Late listing: no photos when auction week starts → Listing skipped, Auction Week guaranteed with the cover.
$c = ias_build_candidates([$listing(['id' => 31, 'sale' => '2026-11-10', 'has_photos' => false, 'photos_at' => ''])], $today, $opts);
t_eq('skipped', $c['listing:31']['status'], 'late listing: the Listing post is skipped');
t_ok($c['auction_week:2026-11-10:lancaster']['guaranteed'], '…and its Auction Week post is guaranteed');
t_eq([31], $c['auction_week:2026-11-10:lancaster']['meta']['uses_cover'], '…using the brochure cover');
$c = ias_build_candidates([$listing(['id' => 32, 'sale' => '2026-12-20', 'has_photos' => false])], $today, $opts);
t_eq(['photos'], $c['listing:32']['needs'], 'no photos yet, sale far off: the Listing post waits for photos');
t_eq(null, $c['listing:32']['window_ideal'], '…with no day yet');

// Photos arriving later move the Listing post's window.
$c = ias_build_candidates([$listing(['id' => 33, 'sale' => '2026-12-20', 'promoted_at' => '2026-10-28', 'photos_at' => '2026-11-02'])], $today, $opts);
t_eq('2026-11-02', $c['listing:33']['window_start'], 'the Listing window starts when the photos arrived');

// Already posted in full by the toolkit's webhook: no second Listing post.
$c = ias_build_candidates([$listing(['id' => 34, 'sale' => '2026-12-20', 'webhook_pushed' => '2026-10-30'])], $today, $opts);
t_eq('skipped', $c['listing:34']['status'], 'a listing the webhook already posted is skipped');

// Not published, or password-protected: nothing.
$c = ias_build_candidates([$listing(['id' => 35, 'sale' => '2026-12-20', 'status' => 'draft']), $listing(['id' => 36, 'sale' => '2026-12-20', 'password' => true])], $today, $opts);
t_eq([], $c, 'drafts and password-protected listings get no posts');

// New This Week: Friday Nov 6 covers announcements Oct 30 – Nov 5.
$c = ias_build_candidates([
  $listing(['id' => 41, 'stage' => 'announced', 'announced_at' => '2026-10-30', 'sale' => '2026-12-12']),
  $listing(['id' => 42, 'stage' => 'announced', 'announced_at' => '2026-11-05']),
  $listing(['id' => 43, 'stage' => 'announced', 'announced_at' => '2026-10-29']),            // last week's
  $listing(['id' => 44, 'stage' => 'upcoming', 'announced_at' => '2026-11-02', 'sale' => '2026-12-19']), // promoted since: still new this week
], $dt('2026-11-04 05:00'), $opts);
$ru = $c['roundup:2026-11-06'];
t_eq(['2026-11-06', '2026-11-06', '2026-11-07'], [$ru['window_start'], $ru['window_ideal'], $ru['window_end']], 'roundup: Friday → Friday → Saturday');
t_eq([41, 44, 42], $ru['listing_ids'], 'roundup names this week\'s announcements, soonest sale first, undated last');
t_ok($ru['lane'] === 'A' && $ru['guaranteed'], 'roundup: lane A, guaranteed');
t_ok(!isset($c['listing:41']), 'no individual post for a Recently Announced listing');

// Results: from a week before install, for 30 days — but one already on the plan never expires.
$past = $listing(['id' => 51, 'stage' => 'past', 'sale' => '2026-11-07', 'price' => '$425,000', 'town' => 'Narvon']);
$late = $dt('2026-12-08 05:00'); // 31 days after the sale
t_ok(isset(ias_build_candidates([$past], $dt('2026-12-07 05:00'), $opts)['results:2026-11-07:narvon']), 'a Results post is called for 30 days after the sale');
t_eq([], ias_build_candidates([$past], $late, $opts), '…and not after, when none was ever planned (a price entered weeks late)');
t_ok(isset(ias_build_candidates([$past], $late, $opts + ['keep_results' => [51]])['results:2026-11-07:narvon']), 'but one already on the plan, still waiting for a yes, never expires');

// Two open-house sessions on one day share one post (the spec: "each open house date"), naming both.
$am = ['n' => 1, 'start' => $dt('2026-11-14 10:00'), 'end' => $dt('2026-11-14 12:00'), 'hours' => 2.0];
$pm = ['n' => 2, 'start' => $dt('2026-11-14 16:00'), 'end' => $dt('2026-11-14 18:00'), 'hours' => 2.0];
$c = ias_build_candidates([$listing(['id' => 61, 'sale' => '2026-11-21', 'open_houses' => [$pm, $am]])], $today, $opts);
$ohs = array_values(array_filter(array_keys($c), function ($k) { return strpos($k, 'open_house:61:') === 0; }));
t_eq(['open_house:61:20261114'], $ohs, 'two sessions on one day: one Open House post, not one lost under the other');
t_eq([1, 2], $c['open_house:61:20261114']['meta']['ohs'] ?? null, '…carrying both sessions, earliest first');
$c = ias_build_candidates([$listing(['id' => 62, 'sale' => '2026-11-21', 'open_houses' => [$am, ['n' => 2, 'start' => $dt('2026-11-15 13:00'), 'end' => $dt('2026-11-15 15:00'), 'hours' => 2.0]]])], $today, $opts);
t_ok(isset($c['open_house:62:20261114'], $c['open_house:62:20261115']) && !isset($c['open_house:62:20261114']['meta']['ohs']), 'two open-house days: a post each, as before');
