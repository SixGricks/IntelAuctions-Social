<?php
/**
 * Intel Auctions Social — the dependency on the Intel Auctions Toolkit.
 *
 * Every listing fact, the Claude engine, the event log, the hourly dispatcher
 * and the admin look all come from the toolkit. Without it (or with one older
 * than IAS_MIN_TOOLKIT, or one whose helpers failed to load) this plugin
 * registers nothing but the notice below: no menu, no cron job, no hooks on
 * listings. Loaded on every request, before the toolkit, so nothing here may
 * call a toolkit function at load time.
 */

if (!defined('ABSPATH')) exit;

/**
 * The toolkit functions this plugin calls. Checked by name so a toolkit that
 * loaded only partly (its loader catches a broken include) is treated as
 * missing rather than fatalling half way through a run.
 */
if (!function_exists('ias_required_toolkit_functions')) {
  function ias_required_toolkit_functions(): array {
    return (array) apply_filters('ias_required_toolkit_functions', [
      // listings, stages and facts
      'ia_schema_post_type', 'ia_has_acf', 'ia_acf_field_auction_date', 'ia_acf_field_gallery',
      'ia_acf_field_starting_bid', 'ia_acf_field_video', 'ia_acf_field_sellers', 'ia_acf_field_auction_type',
      'ia_auction_stage', 'ia_stage_auctions', 'ia_stage_term_id', 'ia_upcoming_taxonomy',
      'ia_open_houses', 'ia_open_house_format', 'ia_parse_datetime', 'ia_wp_tz', 'ia_parse_property_size',
      'ia_get_map_address_string', 'ia_auction_town', 'ia_auction_state', 'ia_auction_group_names',
      'ia_auction_lead_id', 'ia_team_member_field', 'ia_acf_field_team_phone',
      'ia_results_figures', 'ia_results_format_price',
      // engine, log, cron, admin look
      'ia_ai_generate', 'ia_ai_brand_context', 'ia_event_log', 'ia_events_recent', 'ia_cron_jobs',
      'ia_ui_header', 'ia_ui_badge', 'ia_flash_set', 'ia_flash_out',
    ]);
  }
}

/**
 * What stops the plugin from running, as plain sentences ([] = good to go).
 * Meaningful only from plugins_loaded on, when the toolkit has loaded.
 */
if (!function_exists('ias_dependency_problems')) {
  function ias_dependency_problems(): array {
    if (!defined('IA_TOOLKIT_VERSION')) {
      return ['The Intel Auctions Toolkit is not active.'];
    }
    $have = (string) IA_TOOLKIT_VERSION;
    if (version_compare($have, IAS_MIN_TOOLKIT, '<')) {
      return ['The Intel Auctions Toolkit is version ' . $have . '; this plugin needs ' . IAS_MIN_TOOLKIT . ' or newer.'];
    }
    $missing = [];
    foreach (ias_required_toolkit_functions() as $fn) {
      if (!function_exists($fn)) $missing[] = $fn . '()';
    }
    if ($missing) {
      return ['The Intel Auctions Toolkit loaded without some of what this plugin calls: ' . implode(', ', array_slice($missing, 0, 6))
        . (count($missing) > 6 ? ' and ' . (count($missing) - 6) . ' more' : '') . '. Check the toolkit\'s own notice for the error.'];
    }
    return [];
  }
}

add_action('admin_notices', function () {
  if (!current_user_can('activate_plugins') || !function_exists('ias_dependency_problems')) return;
  $problems = ias_dependency_problems();
  if (!$problems) return;
  echo '<div class="notice notice-warning"><p><strong>Intel Auctions Social is doing nothing.</strong> ';
  echo esc_html(implode(' ', $problems));
  echo ' It plans no posts and calls no outside service until the toolkit is active at ' . esc_html(IAS_MIN_TOOLKIT) . ' or newer.</p></div>';
});
