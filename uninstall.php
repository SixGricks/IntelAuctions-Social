<?php
/**
 * Intel Auctions Social — uninstall cleanup (and the catalog of everything
 * the plugin stores; a new option, meta key or file goes on this list).
 *
 * The spec asks for the tables to be "removed cleanly on uninstall", so
 * deleting the plugin removes all of it: every table (posts, history, and from
 * 0.7.0 the Talk it over chat and the brand-voice log), the settings and state
 * options, the dispatcher's bookkeeping for this plugin's job, the per-image
 * photo grades and crops, the per-listing markers, the generated pictures in
 * uploads/intel-social/, and this plugin's entries in the toolkit's event log.
 * The listings themselves, and anything already posted to Facebook, are not
 * touched.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

// Tables.
foreach (['posts', 'history', 'chat', 'voice'] as $name) {
  $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ias_' . $name); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Options: settings, schema stamp, run state (ias_push_lock: the push run's lock, written straight to the table).
foreach ([
  'ias_settings', 'ias_db_version', 'ias_installed_at', 'ias_live_since',
  'ias_last_plan', 'ias_last_plan_summary', 'ias_last_review',
  'ias_push_mode', // before 0.12.0: dry run or live (the dry run is gone; kept here so an old site's copy goes too)
  'ias_dry_run_retired', // when 0.12.0 retired the dry run, and what it switched off (the note under the switches until Settings is saved)
  'ias_readback_last', 'ias_stats_last', 'ias_comments_no_plan', 'ias_plan_hold', 'ias_push_queue', 'ias_oneup_verify', 'ias_push_lock', 'ias_oneup_api_key',
  'ias_oneup_directory', // Look up accounts: OneUp's categories and accounts (ids and names, never the key)
  'ias_gbp_verify', 'ias_gbp_mode', // Google Business Profile: its Verify (and, before 0.12.0, whether it was last sent for real)
  'ias_youtube_verify', 'ias_youtube_playlists', 'ias_youtube_storage', // YouTube (0.22.0): its Verify, and the channel's playlists as Verify read them (each listing's video record is the _ias_youtube meta below; the ias_youtube_page_check transient goes with the others)
  'ias_pulse', // the last time an open calendar asked OneUp what changed, and what it found
  'ias_oneup_id_form', // how OneUp took the account as a list: a JSON list or social_network_id[0]
  'ias_oneup_verify_leftovers', // test drafts an earlier Verify could not delete, by id — the next Verify deletes them
  'ias_facts_sig_v', // 0.23.0: which way a post's facts fingerprint is worked out — a new one re-stamps every post once
  'ias_youtube_route', // 0.22.2: how a video reaches OneUp's storage, as Verify for YouTube found — 'site' (through this website, its copy in uploads/intel-social/video-tmp, removed below) or 'direct'
  'ias_voice_notes', 'ias_voice_suggest', 'ias_voice_learned', 'ias_voice_pruned', // Brand voice: the house rules, Claude's unsaved suggestion, when it last read the log, the day the log was last pruned
] as $key) {
  delete_option($key);
}
// Talk it over's per-post locks (ias_chat_lock_<post id>, written straight to the table like the push lock).
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ias\\_chat\\_lock\\_%'");
// YouTube (0.22.3): the per-listing send-on lock (ias_yt_xfer_<listing id>, written the same way, held while a file goes on to OneUp's storage).
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ias\\_yt\\_xfer\\_%'");
// The toolkit dispatcher's bookkeeping for this plugin's job ids (ias_*).
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ia\\_job\\_last\\_run\\_ias\\_%' OR option_name LIKE 'ia\\_job\\_last\\_duration\\_ias\\_%' OR option_name LIKE 'ia\\_job\\_last\\_issue\\_ias\\_%' OR option_name LIKE 'ia\\_job\\_lock\\_ias\\_%'");

// Transients — among them ias_youtube_page_check (Property Videos' read-back, at most every two minutes) and
// ias_youtube_tmp_sweep (the daily sweep of stray files in uploads/intel-social/video-tmp).
delete_transient('ias_updater_response');
// Every other transient is ias_-prefixed and goes with the statement below: ias_visits_* (the summary's site visits),
// ias_youtube_tmp_sweep, ias_youtube_page_check, ias_appr_refused_* (0.24.1: a refused approval link, logged once a day).
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ias\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ias\\_%'");

// Post meta on listings and attachments (photo grades, crops, markers): every key starts _ias_.
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_ias\\_%'");

// User meta (notice dismissals).
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ias\\_%'");

// This plugin's entries in the toolkit's event log (the toolkit keeps its own).
$events = $wpdb->prefix . 'ia_events';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($events))) === $events) {
  $wpdb->query("DELETE FROM {$events} WHERE event LIKE 'ias.%'");
}

// Generated pictures (results graphics, roundup collages, crops).
$uploads = wp_upload_dir(null, false);
$dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . 'intel-social';
if (!empty($uploads['basedir']) && is_dir($dir)) {
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($it as $file) {
    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
  }
  @rmdir($dir);
}
