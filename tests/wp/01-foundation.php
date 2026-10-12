<?php
/**
 * Phase 1 acceptance: "Activates cleanly next to the toolkit. With the toolkit
 * off, it shows a notice and does nothing."
 */
global $wpdb, $submenu;

t_ok(defined('IA_TOOLKIT_VERSION') && version_compare(IA_TOOLKIT_VERSION, IAS_MIN_TOOLKIT, '>='), 'the toolkit is active and new enough');
t_eq([], ias_dependency_problems(), 'no dependency problems with the toolkit active');
t_ok(ias_boot(), 'the plugin booted');
ias_db_migrate();
t_ok(ias_db_ready(), 'schema stamped ' . IAS_DB_VERSION);
foreach (['posts', 'history'] as $t) {
  t_eq(ias_table($t), $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', ias_table($t))), "table {$t} exists");
}

// Menu: one Social Calendar item under Auctions, the other three reachable as tabs.
do_action('admin_menu');
$parent = ias_menu_parent();
$slugs = array_map(function ($i) { return $i[2]; }, (array) ($submenu[$parent] ?? []));
t_ok(in_array(IAS_PAGE_CALENDAR, $slugs, true), 'Social Calendar is in the Auctions menu');
t_ok(!in_array(IAS_PAGE_SETTINGS, $slugs, true) && !in_array(IAS_PAGE_LOG, $slugs, true), 'Settings and Log are tabs, not menu items');
t_ok(in_array(IAS_PAGE_CALENDAR, (array) apply_filters('ia_simple_mode_keep_auction_pages', []), true), 'simple mode keeps the calendar');

// Every screen renders without notices or errors.
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
foreach (['ias_calendar_render' => 'Social Calendar', 'ias_settings_render' => 'Safety switches', 'ias_log_render' => 'Social Log', 'ias_guide_render' => 'Intel Auctions Social — Guide'] as $fn => $needle) {
  $_GET = ['page' => 'x'];
  ob_start(); $fn(); $html = ob_get_clean();
  t_ok(strpos($html, $needle) !== false, "{$fn} renders ({$needle})");
  t_ok(strpos($html, 'Master switch') !== false, "{$fn} shows the switches strip");
}
restore_error_handler();
t_eq([], $errors, 'no PHP notices while rendering');

// Settings: install defaults are safe; sanitising keeps them in range.
$s = ias_settings();
t_ok(!$s['master'] && $s['dry_run'] && !in_array(true, $s['types'], true), 'nothing can reach Facebook at install');
[$clean, $problems] = ias_settings_sanitize([
  'master' => '1', 'types' => ['listing' => '1'], 'daily_cap' => '99', 'lead_hours' => '24',
  'slots' => [['07:30', '06:00'], ['19:00', '21:00']], 'approvers' => 'DJ@IntelAuctions.com, nope',
  'bid_tiers' => [['500,000', '1.2'], ['0', '0.9']],
], $s);
t_ok($clean['master'] === true && $clean['types']['listing'] === true && $clean['types']['results'] === false, 'switches read from checkboxes');
t_ok(!isset($clean['daily_cap']), 'an out-of-range cap is refused');
t_eq([['19:00', '21:00']], $clean['slots'], 'a window that ends before it starts is refused');
t_eq('dj@intelauctions.com', $clean['approvers'], 'approvers cleaned to valid addresses');
t_eq([[500000, 1.2], [0, 0.9]], $clean['bid_tiers'], 'bid tiers parsed from "500,000"');
t_ok((bool) array_filter($problems, function ($p) { return strpos($p, 'veto window') !== false; }), 'a veto window under 48 hours is warned about');

// The listing panel renders on an auction.
$id = wp_insert_post(['post_type' => ia_schema_post_type(), 'post_status' => 'publish', 'post_title' => 'Panel Test Farm']);
ob_start(); ias_listing_panel_render(get_post($id)); $html = ob_get_clean();
t_ok(strpos($html, 'No social posts for this property yet') !== false, 'the listing panel renders');
wp_delete_post($id, true);

// The toolkit switched off: in a fresh process, a notice and nothing else.
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/wp-run.php') . ' ' . escapeshellarg(rtrim(ABSPATH, '/'))
  . ' ' . escapeshellarg(dirname(__DIR__) . '/wp-off/toolkit-off.php');
$out = (string) shell_exec('IAS_TEST_TOOLKIT_OFF=1 ' . $cmd . ' 2>&1');
t_ok(strpos($out, 'ALL PASSED') !== false, "with the toolkit off the plugin does nothing:\n" . $out);
