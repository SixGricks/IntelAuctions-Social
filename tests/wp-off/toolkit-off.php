<?php
/** Run by 01-foundation.php in a process where the toolkit is not active. */
global $submenu;
t_ok(!defined('IA_TOOLKIT_VERSION'), 'the toolkit is not loaded in this process');
t_ok(function_exists('ias_dependency_problems'), 'the dependency check itself is loaded');
t_eq(['The Intel Auctions Toolkit is not active.'], ias_dependency_problems(), 'the problem is named');
t_ok(ias_boot() === false, 'the plugin does not boot');
foreach (['ias_settings', 'ias_db_migrate', 'ias_calendar_render', 'ias_register_admin'] as $fn) {
  t_ok(!function_exists($fn), "{$fn}() is not loaded");
}
do_action('admin_menu');
$slugs = array_map(function ($i) { return $i[2]; }, (array) ($submenu['edit.php?post_type=auction'] ?? []));
t_ok(!in_array('ia-social-calendar', $slugs, true), 'no Social Calendar in the menu');
ob_start(); do_action('admin_notices'); $html = ob_get_clean();
t_ok(strpos($html, 'Intel Auctions Social is doing nothing') !== false, 'the notice shows');
t_ok(has_action('ia_stage_changed') === false || !has_action('ia_stage_changed', 'ias_on_stage_changed'), 'no hooks on listings');
