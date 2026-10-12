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
t_ok($d['master'] === false && $d['dry_run'] === true && $d['paused'] === false, 'master off, dry run on, not paused at install');
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
