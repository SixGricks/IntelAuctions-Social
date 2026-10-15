<?php
/** Phase 1 helpers: id lists in a column, times of day, settings defaults. */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/db.php';
require_once IAS_PATH . 'includes/admin-settings.php';

t_eq(',12,13,', ias_ids_to_column([12, 13, 12, 0]), 'ids to column dedupes and drops zero');
t_eq('', ias_ids_to_column([]), 'no ids is an empty column');
t_eq([12, 13], ias_ids_from_column(',12,13,'), 'column back to ids');
t_eq(360, ias_hm_minutes('06:00'), '06:00 is 360 minutes');
t_eq(-1, ias_hm_minutes('25:00'), '25:00 is not a time');
t_eq('19:05', ias_minutes_hm(19 * 60 + 5), 'minutes back to HH:MM');

$d = ias_settings_defaults();
t_ok($d['master'] === false && !array_key_exists('dry_run', $d) && $d['paused'] === false && !array_filter($d['types']) && empty($d['networks']['gbp']['on']), 'master off, every post type and Google off, not paused at install — and no dry run (on or off, nothing in between)');
t_ok(!array_key_exists('live', $d['networks']['gbp']), 'Google is one switch: no separate Send to Google');
t_ok(!in_array(true, $d['types'], true), 'every post type off at install');
t_eq(['roundup' => 60, 'listing' => 70, 'open_house' => 90, 'auction_week' => 80, 'results' => 100], $d['weights'], 'weights are the spec\'s');
t_eq([[500000, 1.2], [250000, 1.1], [100000, 1.0], [0, 0.9]], $d['bid_tiers'], 'starting-bid multipliers are the spec\'s');
t_eq([['06:00', '07:30'], ['11:30', '13:00'], ['19:00', '21:00']], $d['slots'], 'posting windows are the spec\'s');
t_eq(3, $d['daily_cap'], 'three a day');
t_eq(48, $d['lead_hours'], '48-hour veto window');

// Saved values merge over the defaults, nested switches one level deep.
update_option('ias_settings', ['types' => ['listing' => true], 'daily_cap' => 2, 'gone_key' => 1]);
$s = ias_settings();
t_ok($s['types']['listing'] === true && $s['types']['results'] === false, 'saved switch merges over default switches');
t_eq(2, $s['daily_cap'], 'saved cap wins');
t_ok(!isset($s['gone_key']), 'a key the code no longer knows is ignored');
update_option('ias_settings', []);

// The facts fingerprint (0.23.0): plain values per field, per type — so a change can be named and new wording is never a change.
require_once IAS_PATH . 'includes/content.php';
$tz = new DateTimeZone('America/New_York');
$fx = ['id' => 7, 'title' => 'Hershey Farm', 'address' => '12 Hershey Road, Narvon, PA', 'town' => 'Narvon', 'county' => 'Lancaster', 'state' => 'PA',
  'date' => new DateTimeImmutable('2026-11-28 10:00', $tz), 'has_time' => true, 'bidding' => 'in_person', 'starting_bid_raw' => '$300,000', 'size_raw' => '85 acres',
  'open_houses' => [['start' => new DateTimeImmutable('2026-11-14 13:00', $tz), 'hours' => 2.0]], 'sellers' => 'John Hershey', 'lead' => ['name' => 'Jacob', 'phone' => '717-555-0101'],
  'office_phone' => '717-917-5844', 'kind' => 'real_estate', 'division' => 'farm_home', 'parcels' => [], 'excerpt' => "A   productive farm.\n", 'price' => ''];
$lp = ['type' => 'listing', 'flags' => ['meta' => ['sale_day' => '2026-11-28']]];
$sig = ias_facts_sig($lp, [$fx]);
t_eq($sig, ias_facts_sig($lp, [$fx + ['excerpt' => 'A productive farm.']]), 'whitespace in the description is no change');
t_eq(['open houses'], ias_facts_changed($sig, ias_facts_sig($lp, [array_merge($fx, ['open_houses' => []])])), 'an open house taken off: named as the open houses');
t_eq(['auction date and time', 'description'], ias_facts_changed($sig, ias_facts_sig($lp, [array_merge($fx, ['date' => new DateTimeImmutable('2026-11-29 10:00', $tz), 'excerpt' => 'New words.'])])), 'two changes named, in order');
t_eq($sig, ias_facts_sig($lp, [array_merge($fx, ['photos' => [1, 2, 3], 'video_link' => 'https://youtu.be/x', 'video_file_url' => 'https://f'])]), 'photos and videos are not facts the words are written from');
$rp = ['type' => 'results', 'flags' => ['meta' => ['sale_day' => '2026-11-28']]];
$rsig = ias_facts_sig($rp, [$fx]);
t_eq($rsig, ias_facts_sig($rp, [array_merge($fx, ['sellers' => 'Someone Else', 'open_houses' => [], 'lead' => null, 'starting_bid_raw' => ''])]), 'a Results post is not written from the sellers, the open houses, the lead or the starting bid');
t_eq(['sale price'], ias_facts_changed($rsig, ias_facts_sig($rp, [array_merge($fx, ['price' => '$412,000'])])), '…but its sale price is');
t_eq($sig, ias_facts_sig($lp, [array_merge($fx, ['price' => '$412,000'])]), 'a Listing post is not written from the sale price');
t_eq([], ias_facts_changed([], $sig), 'an old fingerprint with no fields: nothing can be named');
t_eq(md5((string) wp_json_encode($sig)), ias_facts_hash($lp, [$fx]), 'the hash is the fingerprint\'s');
