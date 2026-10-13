<?php
/**
 * Phase 3 acceptance: "A test with a busy November (35 auctions) drops no
 * guaranteed post, keeps the 3-a-day cap and the 48-hour floor, and puts no
 * same-county posts on one day."
 *
 * 35 auctions across November — the three Sunbury twins on Nov 18, both Lila
 * Lane duplexes on Nov 4, 393 Boyer Rd with its contents sale the same day,
 * Superior Roofing's liquidation — announced, promoted, open-housed, sold and
 * priced on their own dates. The plan runs every morning at 5:00 from Oct 1 to
 * Dec 10, exactly as the plugin runs it: candidates from the listings as they
 * stand that day, the scheduler, then what happens before the next morning —
 * posts inside the push horizon go to "OneUp", posts whose time has passed are
 * published, Lane A posts are approved. Every morning's plan is checked against
 * every rule; at the end every property must have had its Listing and Results
 * posts, and every Friday with announcements its roundup.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/post-types.php';
require_once IAS_PATH . 'includes/candidates.php';
require_once IAS_PATH . 'includes/scheduler.php';

$tz = new DateTimeZone('America/New_York');
$S = ias_settings_defaults();
$opts = ['weights' => $S['weights'], 'bid_tiers' => $S['bid_tiers'], 'installed_at' => '2026-09-01', 'live_since' => ''];

// [street, county, division, sale (Y-m-d H:i), starting bid, open houses (days before, hours), kind]
$specs = [
  ['12 Lila Lane, Unit A', 'Lancaster', 'farm_home', '2026-11-04 18:00', 180000, [[9, 2]], 're'],
  ['12 Lila Lane, Unit B', 'Lancaster', 'farm_home', '2026-11-04 18:30', 175000, [[9, 2]], 're'],
  ['5940 Plank Road', 'Lancaster', 'farm_home', '2026-11-07 10:00', 650000, [[8, 2], [14, 3]], 're'],
  ['400 Ridge Road', 'Clinton', 'investment_rec', '2026-11-07 13:00', 220000, [[10, 2]], 're'],
  ['77 Church Street', 'Chester', 'farm_home', '2026-11-09 17:00', 310000, [[6, 1.5]], 're'],
  ['Superior Roofing Liquidation', 'Dauphin', 'intel', '2026-11-10 09:00', 0, [[3, 4]], 'liq'],
  ['18 Mill Road', 'Lancaster', 'farm_home', '2026-11-11 17:00', 425000, [[7, 2]], 're'],
  ['3 Pine Hollow', 'Centre', 'investment_rec', '2026-11-12 11:00', 95000, [[9, 2]], 're'],
  ['220 Valley View', 'York', 'farm_home', '2026-11-13 17:00', 280000, [[8, 2]], 're'],
  ['Hilltop Farm', 'Lancaster', 'farm_home', '2026-11-14 10:00', 900000, [[7, 3], [14, 2]], 're'],
  ['55 Creek Road', 'Chester', 'farm_home', '2026-11-14 13:00', 330000, [[9, 2]], 're'],
  ['8 Laurel Run', 'Clinton', 'investment_rec', '2026-11-14 15:00', 140000, [], 're'],
  ['901 Market Street', 'Northumberland', 'intel', '2026-11-16 17:00', 75000, [[6, 2]], 're'],
  ['101 Market St (twin 1)', 'Northumberland', 'intel', '2026-11-18 10:00', 60000, [[8, 2]], 're'],
  ['103 Market St (twin 2)', 'Northumberland', 'intel', '2026-11-18 11:00', 60000, [[8, 2]], 're'],
  ['105 Market St (twin 3)', 'Northumberland', 'intel', '2026-11-18 12:00', 62000, [[8, 2]], 're'],
  ['14 Orchard Lane', 'Lancaster', 'farm_home', '2026-11-18 17:00', 510000, [[9, 2]], 're'],
  ['Buck Mountain 40', 'Centre', 'investment_rec', '2026-11-19 11:00', 165000, [[10, 2]], 're'],
  ['6 Spring Garden', 'Lebanon', 'farm_home', '2026-11-19 17:00', 240000, [[7, 2]], 're'],
  ['330 Fairview', 'Lancaster', 'farm_home', '2026-11-20 17:00', 380000, [[8, 2]], 're'],
  ['393 Boyer Rd (3 parcels)', 'Northumberland', 'farm_home', '2026-11-21 10:00', 450000, [[7, 3], [14, 2]], 're'],
  ['Boyer Rd Contents', 'Northumberland', 'intel', '2026-11-21 13:00', 0, [], 'liq'],
  ['21 Kettle Road', 'Lancaster', 'farm_home', '2026-11-21 10:30', 720000, [[8, 2]], 're'],
  ['Pine Creek 80', 'Clinton', 'investment_rec', '2026-11-21 14:00', 260000, [[9, 2]], 're'],
  ['47 Brickyard Road', 'Chester', 'farm_home', '2026-11-23 17:00', 290000, [[7, 2]], 're'],
  ['2 Quarry Lane', 'York', 'intel', '2026-11-24 17:00', 85000, [[6, 2]], 're'],
  ['Long Lane Farm', 'Lancaster', 'farm_home', '2026-11-25 10:00', 830000, [[8, 3], [15, 2]], 're'],
  ['600 Turnpike', 'Dauphin', 'intel', '2026-11-25 17:00', 120000, [[7, 2]], 're'],
  ['Bear Run 55', 'Centre', 'investment_rec', '2026-11-27 11:00', 195000, [[9, 2]], 're'],
  ['9 Weaver Road', 'Lancaster', 'farm_home', '2026-11-28 10:00', 460000, [[7, 2]], 're'],
  ['70 Railroad Ave', 'Lebanon', 'intel', '2026-11-28 13:00', 70000, [[8, 2]], 're'],
  ['Stony Ridge 30', 'Clinton', 'investment_rec', '2026-11-28 14:00', 110000, [[10, 2]], 're'],
  ['44 Glen Road', 'Chester', 'farm_home', '2026-11-30 17:00', 355000, [[8, 2]], 're'],
  ['8 Harvest Drive', 'Lancaster', 'farm_home', '2026-11-30 18:00', 300000, [[9, 2]], 're'],
  ['12 Union Street', 'York', 'intel', '2026-11-30 17:30', 99000, [[7, 2]], 're'],
];
t_eq(35, count($specs), 'thirty-five November auctions');

$listings = [];
foreach ($specs as $i => [$street, $county, $div, $sale, $bid, $ohs, $kind]) {
  $id = 100 + $i;
  $sale_dt = new DateTimeImmutable($sale, $tz);
  $promote = $sale_dt->modify('-' . (21 + ($i % 3) * 5) . ' days')->format('Y-m-d');
  $announce = (new DateTimeImmutable($promote, $tz))->modify('-' . (8 + ($i % 4) * 3) . ' days')->format('Y-m-d');
  $oh_rows = [];
  foreach ($ohs as $n => [$before, $hours]) {
    $start = $sale_dt->setTime(13, 0)->modify('-' . $before . ' days');
    $oh_rows[] = ['n' => $n + 1, 'start' => $start, 'end' => $start->modify('+' . (int) ($hours * 60) . ' minutes'), 'hours' => (float) $hours];
  }
  $listings[$id] = [
    'id' => $id, 'title' => $street, 'street' => $street, 'county' => $county,
    'town' => preg_match('/Market St|Boyer/', $street) ? 'Sunbury' : (strpos($street, 'Lila Lane') !== false ? 'Strasburg' : $county . ' ' . $id), 'division' => $div, 'kind' => $kind === 'liq' ? 'liquidation' : 'real_estate',
    'date' => $sale_dt, 'sale_day' => $sale_dt->format('Y-m-d'), 'starting_bid' => $bid > 0 ? (float) $bid : null,
    'open_houses' => $oh_rows, 'announce' => $announce, 'promote' => $promote,
  ];
}

/** A listing's facts as they stand on $day. */
$facts_on = function (array $l, string $day) {
  $stage = $day < $l['announce'] ? '' : ($day < $l['promote'] ? 'announced' : ($day <= $l['sale_day'] ? 'upcoming' : 'past'));
  return [
    'id' => $l['id'], 'title' => $l['title'], 'status' => $stage === '' ? 'draft' : 'publish', 'password' => false, 'stage' => $stage, 'town' => $l['town'],
    'date' => $l['date'], 'sale_day' => $l['sale_day'], 'starting_bid' => $l['starting_bid'], 'county' => $l['county'],
    'division' => $l['division'], 'kind' => $l['kind'],
    'price' => ($stage === 'past' && $l['kind'] === 'real_estate' && $day >= ias_day_add($l['sale_day'], 1)) ? '$1' : '',
    'open_houses' => $l['open_houses'], 'has_photos' => $day >= $l['promote'], 'photos_at' => $day >= $l['promote'] ? $l['promote'] : '',
    'promoted_at' => $day >= $l['promote'] ? $l['promote'] : '', 'announced_at' => $l['announce'], 'published_at' => $l['announce'],
    'webhook_pushed' => '',
  ];
};

$rows = [];       // slot_key => post row (the plugin's table, in memory)
$problems = [];
$days_run = 0;
$start = new DateTimeImmutable('2026-10-01 05:00', $tz);
for ($now = $start; $now->format('Y-m-d') <= '2026-12-10'; $now = $now->modify('+1 day')) {
  $today = $now->format('Y-m-d');
  $days_run++;

  // What happened since yesterday: posts whose time came are published.
  foreach ($rows as $k => &$r) {
    if (in_array($r['status'], ['pushed', 'approved', 'planned'], true) && $r['scheduled_at'] !== '' && $r['scheduled_at'] <= $now->format('Y-m-d H:i')) {
      if ($r['status'] === 'planned' && $r['lane'] === 'A') continue; // never without a yes
      $r['status'] = 'published';
    }
  }
  unset($r);

  // The morning sync: candidates as the listings stand today.
  $cands = ias_build_candidates(array_map(function ($l) use ($facts_on, $today) { return $facts_on($l, $today); }, $listings), $now->setTime(0, 0), $opts);
  foreach ($cands as $k => $c) {
    if (!isset($rows[$k])) {
      $rows[$k] = ['slot_key' => $k, 'type' => $c['type'], 'listing_ids' => $c['listing_ids'], 'county' => $c['county'], 'division' => $c['division'],
        'score' => $c['score'], 'guaranteed' => $c['guaranteed'], 'lane' => $c['lane'], 'status' => $c['status'],
        'window_start' => $c['window_start'], 'window_ideal' => $c['window_ideal'], 'window_end' => $c['window_end'],
        'scheduled_at' => '', 'locked' => 0, 'flags' => ['meta' => $c['meta'], 'needs' => $c['needs']]];
      // Lane A posts are approved the morning they appear (the approval round trip is Phase 4's test).
      if ($c['lane'] === 'A' && $c['status'] === 'planned') $rows[$k]['status'] = 'approved';
    } elseif (!in_array($rows[$k]['status'], ['pushed', 'published'], true)) {
      foreach (['listing_ids', 'county', 'division', 'score', 'guaranteed', 'window_start', 'window_ideal', 'window_end'] as $f) $rows[$k][$f] = $c[$f];
      $rows[$k]['flags']['meta'] = $c['meta'];
      $rows[$k]['flags']['needs'] = $c['needs'];
    }
  }

  // The plan.
  $before = $rows;
  $plan = ias_schedule(ias_engine_posts(array_values($rows), $now, 2880), ias_engine_cfg($S, $now));
  foreach ($plan['dropped'] as $k => $why) {
    if (!empty($rows[$k]['guaranteed'])) $problems[] = "{$today}: guaranteed {$k} dropped: {$why}";
    if ($rows[$k]['status'] === 'published') { $problems[] = "{$today}: published {$k} dropped"; continue; }
    // A dropped post that was on OneUp is deleted there (Phase 5); either way it is off the plan.
    $rows[$k]['status'] = 'dropped'; $rows[$k]['flags']['dropped_by'] = 'scheduler'; $rows[$k]['scheduled_at'] = '';
  }
  foreach ($plan['relaxed'] as $k => $why) $problems[] = "{$today}: {$k} broke the county rule: {$why}";
  foreach ($plan['unplaced'] as $k => $why) if (!empty($rows[$k]['guaranteed'])) $problems[] = "{$today}: guaranteed {$k} unplaced: {$why}";
  foreach ($plan['at'] as $k => $at) {
    $old = (string) $before[$k]['scheduled_at'];
    $floor = $now->modify('+48 hours')->format('Y-m-d H:i');
    if ($rows[$k]['lane'] === 'B' && $at !== $old && $at < $floor) $problems[] = "{$today}: {$k} scheduled inside the 48-hour floor ({$at})";
    if ($rows[$k]['status'] === 'dropped') $rows[$k]['status'] = 'planned';
    $rows[$k]['scheduled_at'] = $at;
  }

  // Every day of this plan: cap, Sundays, gaps, one per property, one per county.
  $by_day = [];
  foreach ($rows as $k => $r) {
    if ($r['scheduled_at'] === '' || !in_array($r['status'], ['planned', 'approved', 'pushed', 'published'], true)) continue;
    if (substr($r['scheduled_at'], 0, 10) < $today) continue;
    $by_day[substr($r['scheduled_at'], 0, 10)][] = $k;
  }
  foreach ($by_day as $d => $keys) {
    if (count($keys) > 3) $problems[] = "{$today}: {$d} has " . count($keys) . ' posts';
    if (ias_day_dow($d) === 7) $problems[] = "{$today}: a post on Sunday {$d}";
    $mins = []; $props = []; $counties = [];
    foreach ($keys as $k) {
      $r = $rows[$k];
      $t = ias_hm_to_min(substr($r['scheduled_at'], 11, 5));
      foreach ($mins as $u) if (abs($t - $u) < 180) $problems[] = "{$today}: {$d} has two posts under 3 hours apart";
      $mins[] = $t;
      if ($t % 60 === 0) $problems[] = "{$today}: {$k} lands on the hour";
      $in_window = false;
      foreach ([[360, 450], [690, 780], [1140, 1260]] as $w) if ($t >= $w[0] && $t <= $w[1]) $in_window = true;
      if (!$in_window) $problems[] = "{$today}: {$k} at {$r['scheduled_at']} is outside the posting windows";
      foreach ($r['listing_ids'] as $lid) { if (isset($props[$lid])) $problems[] = "{$today}: {$d} has two posts for listing {$lid}"; $props[$lid] = 1; }
      foreach (array_filter(array_map('trim', explode(',', (string) $r['county']))) as $c) { if (isset($counties[$c])) $problems[] = "{$today}: {$d} has two {$c} County posts"; $counties[$c] = 1; }
    }
  }

  // Before tomorrow: Lane B posts inside the push horizon go to OneUp.
  $push_until = $now->modify('+21 days')->format('Y-m-d H:i');
  foreach ($rows as $k => &$r) {
    if ($r['scheduled_at'] !== '' && $r['scheduled_at'] <= $push_until && ($r['status'] === 'approved' || ($r['status'] === 'planned' && $r['lane'] === 'B'))) $r['status'] = 'pushed';
  }
  unset($r);
}

t_eq(71, $days_run, 'the plan ran every morning from Oct 1 to Dec 10');
t_eq([], array_slice(array_unique($problems), 0, 15), 'no rule was broken on any morning (cap, Sundays, 48-hour floor, gaps, windows, one per property, one per county, guaranteed never dropped)');

// Every property got its Listing and its Results post out; every Friday with announcements its roundup.
$published = array_filter($rows, function ($r) { return $r['status'] === 'published'; });
$have = function (string $type, int $id) use ($published) {
  foreach ($published as $r) if ($r['type'] === $type && in_array($id, $r['listing_ids'], true)) return true;
  return false;
};
$no_listing = []; $no_results = [];
foreach ($listings as $id => $l) {
  if (!$have('listing', $id)) $no_listing[] = $l['title'];
  if (!$have('results', $id)) $no_results[] = $l['title'];
}
t_eq([], $no_listing, 'every property\'s Listing post went out');
t_eq([], $no_results, 'every property\'s Results post went out (liquidations\' thank-yous included)');
$fridays = [];
foreach ($listings as $l) {
  $d = $l['announce'];
  $n = (5 - ias_day_dow($d) + 7) % 7; // the next Friday after the announcement
  $fridays[ias_day_add($d, $n === 0 ? 7 : $n)] = 1;
}
$roundups = array_map(function ($r) { return substr($r['scheduled_at'], 0, 10); }, array_filter($published, function ($r) { return $r['type'] === 'roundup'; }));
foreach (array_keys($fridays) as $fri) {
  if ($fri < '2026-10-02') continue; // announced before the simulation began
  $sat = ias_day_add($fri, 1);
  t_ok(in_array($fri, $roundups, true) || in_array($sat, $roundups, true), "the roundup for Friday {$fri} went out on its Friday or Saturday");
}

// The shared posts.
$aw = $rows['auction_week:2026-11-18:sunbury'] ?? null;
t_ok($aw && $aw['listing_ids'] === [113, 114, 115], 'the three Sunbury twins shared one Auction Week post (and only they did)');
$boyer = $rows['results:2026-11-21:sunbury'] ?? null;
t_ok($boyer && $boyer['listing_ids'] === [120, 121] && $boyer['status'] === 'published', '393 Boyer Rd and its contents sale shared one Results post');
$res = $rows['results:2026-11-04:strasburg'] ?? null;
t_ok($res && $res['listing_ids'] === [100, 101] && $res['status'] === 'published', 'both Lila Lane duplexes shared one Results post');

// For the record (not a rule): how many droppable posts the month could not fit.
$dropped = array_filter($rows, function ($r) { return $r['status'] === 'dropped'; });
$by_type = array_count_values(array_column($dropped, 'type'));
$posted = array_count_values(array_column($published, 'type'));
echo '    November: ' . count($published) . ' posts published (' . http_build_query($posted, '', ', ') . '); dropped: ' . (http_build_query($by_type, '', ', ') ?: 'none') . "\n";
t_ok(empty($by_type['listing']) && empty($by_type['results']) && empty($by_type['roundup']), 'only Open House and Auction Week posts were ever dropped');
