<?php
/**
 * Deleting the plugin removes everything it stored (the spec: "two custom
 * tables, removed cleanly on uninstall"), and nothing else. Runs last; puts
 * the tables back afterwards so the site stays usable.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_clock('2026-11-02 05:30');
$T = ias_t_terms();
$photo = ias_t_photo('Uninstall photo');
$listing = ias_t_listing(['title' => '1 Uninstall Lane', 'town' => 'Narvon', 'county' => 'Lancaster', 'stage' => 'upcoming', 'sale' => '2026-11-21 10:00', 'gallery' => [$photo]]);
update_post_meta($photo, '_ias_photo', ['q' => 5, 'kind' => 'other', 'alt' => 'x']);
update_post_meta($listing, '_ias_sale_kind', 'real_estate');
ias_sync();
ias_log('run', 0, ['summary' => 'before uninstall']);
[$dir] = ias_upload_dir('graphics');
file_put_contents($dir . '/uninstall-test.jpg', 'x');
update_option('ia_job_last_run_ias_social', time(), false);
set_transient('ias_updater_response', 'none', 60);
update_option('ia_job_last_run_kpi_daily', 12345, false); // the toolkit's own: must survive
update_option('ias_voice_notes', ['Never use the word nestled.'], false);
update_option('ias_voice_suggest', ['rules' => []], false);
update_option('ias_voice_learned', ['ts' => time()], false);
update_option('ias_voice_pruned', '2026-11-02', false);
update_option('ias_oneup_directory', ['at' => '2026-11-02 05:30:00', 'categories' => []], false);
update_option('ias_gbp_verify', ['ok' => true], false);
update_option('ias_gbp_mode', 'live', false);
update_option('ias_pulse', ['ts' => time()], false);
$wpdb->insert($wpdb->options, ['option_name' => 'ias_chat_lock_123', 'option_value' => (string) time(), 'autoload' => 'no']);
$wpdb->insert(ias_table('chat'), ['post_id' => 1, 'who' => 'person', 'message' => 'Shorter', 'created_at' => ias_now_sql()]);

t_ok(ias_db_ready() && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . ias_table('posts')) > 0, 'before: the plugin has data');

if (!defined('WP_UNINSTALL_PLUGIN')) define('WP_UNINSTALL_PLUGIN', 'intel-auctions-social/intel-auctions-social.php');
include IAS_PATH . 'uninstall.php';
wp_cache_flush();

foreach (['posts', 'history', 'chat', 'voice'] as $t) t_eq(null, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'ias_' . $t)), "table ias_{$t} is gone");
foreach (['ias_settings', 'ias_db_version', 'ias_installed_at', 'ias_last_plan', 'ia_job_last_run_ias_social', 'ias_voice_notes', 'ias_voice_suggest', 'ias_voice_learned', 'ias_voice_pruned', 'ias_chat_lock_123', 'ias_oneup_directory', 'ias_gbp_verify', 'ias_gbp_mode', 'ias_pulse'] as $o) t_eq(false, get_option($o), "option {$o} is gone");
t_eq(false, get_transient('ias_updater_response'), 'the updater cache is gone');
t_eq(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_ias\\_%'"), 'every _ias_ meta key is gone');
t_ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_iast_fixture'") > 0, 'other meta (the test fixtures\' marker) is untouched');
t_eq(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ia_events WHERE event LIKE 'ias.%'"), 'this plugin\'s log entries are gone');
t_ok(!is_dir(wp_upload_dir()['basedir'] . '/intel-social'), 'the generated pictures are gone');
t_eq('12345', (string) get_option('ia_job_last_run_kpi_daily'), 'the toolkit\'s own job bookkeeping is untouched');
t_ok(get_post($listing) !== null && get_post($photo) !== null, 'the listing and its photo are untouched');

// Put it back for whoever uses the site next (the uninstall took the test markers too).
wp_delete_post($listing, true);
wp_delete_attachment($photo, true);
ias_db_migrate();
t_ok(ias_db_ready(), 'the tables come back on the next migrate');
delete_option('ia_job_last_run_kpi_daily');
