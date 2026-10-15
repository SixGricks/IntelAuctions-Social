<?php
/**
 * More ways to organise a post, by type and division (0.25.0, from DJ's list): each used only for a post that
 * has what it needs, and the day-counting ones only on the day they fit.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/post-types.php';
require_once IAS_PATH . 'includes/content.php';

$tz = ia_wp_tz();
$d = function (string $s) use ($tz) { return new DateTimeImmutable($s, $tz); };
$farm = ['id' => 1, 'title' => '7 Mill Road', 'excerpt' => 'A 60-acre farm with a bank barn.', 'kind' => 'real_estate', 'size_raw' => '60 acres', 'acres' => 60.0,
  'division' => 'farm_home', 'date' => $d('2026-11-12 10:00'), 'price' => '', 'open_houses' => [['n' => 1, 'start' => $d('2026-11-05 13:00'), 'hours' => 2]]];
$keys = function (array $post, array $facts) { return array_keys(ias_templates_fitting($post, $facts)); };

$all = ias_templates_more();
t_ok(count($all) >= 20, count($all) . ' more templates, across the post types');
foreach ($all as $k => $def) {
  t_ok(count($def) === 5 && is_string($def[0]) && is_string($def[1]) && $def[2] && $def[3], 'template ' . $k . ' has a label, a brief, types, divisions and needs');
  t_ok(!preg_match('/\b(register|registration|catalog|catalogue|pickup|inspection)\b/i', $def[1]) || strpos($def[1], 'Never mention registering') !== false, 'template ' . $k . ' asks for no fact the plugin does not have');
  t_ok(!preg_match('/Farm & Home|Investment (and|&) Recreation/', $def[1]), 'template ' . $k . ' signs with no division name');
}

$lst = ['type' => 'listing', 'slot_key' => 'listing:1', 'scheduled_at' => '2026-11-03 09:00:00'];
$k = $keys($lst, [$farm]);
t_ok(in_array('l_farm', $k, true) && in_array('l_home', $k, true) && in_array('place', $k, true), 'a farm listing: the Farm & Home templates and the five openings');
t_ok(!in_array('l_hunting', $k, true) && !in_array('l_equipment', $k, true) && !in_array('l_rental', $k, true), '…never another division\'s');
$hunt = array_merge($farm, ['division' => 'investment_rec', 'excerpt' => '42 wooded acres with deer and turkey sign, a cabin and a lane.']);
$k = $keys($lst, [$hunt]);
t_ok(in_array('l_hunting', $k, true) && in_array('l_outdoors', $k, true) && !in_array('l_farm', $k, true), 'wooded ground with deer: the hunting and outdoors templates');
$plain = array_merge($hunt, ['excerpt' => 'A level lot near town.']);
t_ok(!in_array('l_hunting', $keys($lst, [$plain]), true), '…and no hunting template where the listing says nothing of it');
$rent = array_merge($farm, ['division' => 'investment_rec', 'excerpt' => 'A duplex with two rented units.', 'size_raw' => '', 'acres' => null]);
$k = $keys($lst, [$rent]);
t_ok(in_array('l_rental', $k, true) && !in_array('l_outdoors', $k, true), 'a rented duplex: the rental template; no size, no outdoors template');
$liq = array_merge($farm, ['division' => 'intel', 'kind' => 'liquidation', 'excerpt' => 'Shop tools, two tractors and household goods.', 'size_raw' => '', 'acres' => null]);
$k = $keys($lst, [$liq]);
t_ok(in_array('l_equipment', $k, true) && in_array('l_discover', $k, true) && !in_array('l_home', $k, true), 'a contents sale: the Intel templates');

// The day-counting ones fit only on the day they are true.
$aw = ['type' => 'auction_week', 'slot_key' => 'auction_week:1'];
$at = function (string $when) use ($aw) { return $aw + ['scheduled_at' => $when]; };
t_ok(!in_array('a_countdown', $keys($at('2026-11-03 09:00:00'), [$farm]), true), 'nine days out: no countdown');
t_ok(in_array('a_countdown', $keys($at('2026-11-09 09:00:00'), [$farm]), true) && !in_array('a_tomorrow', $keys($at('2026-11-09 09:00:00'), [$farm]), true), 'three days out: the countdown');
t_ok(in_array('a_tomorrow', $keys($at('2026-11-11 09:00:00'), [$farm]), true) && !in_array('a_countdown', $keys($at('2026-11-11 09:00:00'), [$farm]), true), 'the day before: "Tomorrow is auction day"');
t_ok(in_array('a_today', $keys($at('2026-11-12 07:00:00'), [$farm]), true), 'the morning of the sale: "Auction day is here"');
$oh = ['type' => 'open_house', 'slot_key' => 'open_house:1:20261105', 'flags' => ['meta' => ['oh' => 1]]];
t_ok(in_array('o_soon', $keys($oh + ['scheduled_at' => '2026-11-04 09:00:00'], [$farm]), true), 'an open house the next day: the reminder');
t_ok(!in_array('o_soon', $keys($oh + ['scheduled_at' => '2026-11-02 09:00:00'], [$farm]), true), '…not three days ahead of it');

// Results.
$res = ['type' => 'results', 'slot_key' => 'results:1'];
$sold = array_merge($farm, ['price' => '$612,000']);
$k = $keys($res, [$sold]);
t_ok(in_array('r_sold', $k, true) && in_array('r_county', $k, true) && in_array('r_result', $k, true) && !in_array('r_wrap', $k, true), 'a priced farm sale: SOLD first and the county results, beside the five Results openings');
$k = $keys($res, [array_merge($liq, ['price' => ''])]);
t_ok(in_array('r_wrap', $k, true) && !in_array('r_sold', $k, true), 'a contents sale with no price: "That\'s a wrap", never "SOLD"');

// The roundup rotates too, and every label reads on the screens.
$k = $keys(['type' => 'roundup', 'slot_key' => 'roundup:2026-11-06'], [$farm, $hunt]);
t_ok(in_array('list', $k, true) && in_array('w_spotlight', $k, true) && in_array('w_ahead', $k, true), 'the weekly roundup has ways of its own: ' . implode(', ', $k));
t_ok(!in_array('w_spotlight', $keys(['type' => 'roundup', 'slot_key' => 'roundup:x'], [$farm]), true), '…a spotlight only with more than one sale');
t_eq('Hunting ground', ias_template_label('l_hunting'), 'labels for the screens');
t_ok(strpos(ias_prompt_rules(), '"SOLD" on a Results post') !== false, 'the voice allows "SOLD" in capitals on a Results post');
