<?php
/**
 * Plugin Name: Intel Auctions Social
 * Description: Turns each auction into a set of Facebook posts, plans them three a day through OneUp, and keeps a person in the loop on the posts that carry hard facts. Needs the Intel Auctions Toolkit.
 * Version: 0.4.4
 * Author: Six Gricks Solutions (DJ)
 * Requires at least: 5.3
 * Requires PHP: 7.4
 *
 * A sister plugin to the Intel Auctions Toolkit (SixGricks/IntelAuction): it
 * reads every listing fact through the toolkit's helpers and spends Claude
 * through the toolkit's metered engine, and does nothing at all while the
 * toolkit is missing or too old. Deactivating it switches Facebook posting
 * off as one unit without touching listings, schema or SEO.
 */

if (!defined('ABSPATH')) exit;

if (PHP_VERSION_ID < 70400) {
  add_action('admin_notices', function () {
    if (!current_user_can('activate_plugins')) return;
    echo '<div class="notice notice-error"><p><strong>Intel Auctions Social:</strong> requires PHP 7.4 or higher. ';
    echo 'This server runs PHP ' . esc_html(PHP_VERSION) . '. The plugin has not been loaded.</p></div>';
  });
  return;
}

if (!defined('IAS_VERSION'))      define('IAS_VERSION', '0.4.4');
if (!defined('IAS_FILE'))         define('IAS_FILE', __FILE__);
if (!defined('IAS_PATH'))         define('IAS_PATH', plugin_dir_path(__FILE__));
if (!defined('IAS_URL'))          define('IAS_URL', plugin_dir_url(__FILE__));
/** The oldest toolkit whose helpers this plugin can rely on. */
if (!defined('IAS_MIN_TOOLKIT'))  define('IAS_MIN_TOOLKIT', '4.66.0');

/**
 * The dependency check and the updater load whatever state the toolkit is
 * in: the check is what says the toolkit is missing, and the updater is how a
 * fix arrives. Everything else waits for ias_boot().
 */
try {
  require_once IAS_PATH . 'includes/dependency.php';
  require_once IAS_PATH . 'includes/updater.php';
} catch (\Throwable $ias_e) {
  if (function_exists('error_log')) {
    error_log(sprintf('[Intel Auctions Social] Load failed: %s in %s:%d', $ias_e->getMessage(), $ias_e->getFile(), $ias_e->getLine()));
  }
}
unset($ias_e);

/**
 * The files that make up the plugin, loaded only once the toolkit is known to
 * be there. Order matters only in that nothing here calls another file's
 * functions at load time — hooks are added, not run.
 */
if (!function_exists('ias_includes')) {
  function ias_includes(): array {
    return [
      'includes/settings.php',
      'includes/db.php',
      'includes/log.php',
      'includes/post-types.php',
      'includes/facts.php',
      'includes/candidates.php',
      'includes/fairhousing.php',
      'includes/checks.php',
      'includes/photos.php',
      'includes/graphics.php',
      'includes/content.php',
      'includes/sync.php',
      'includes/scheduler.php',
      'includes/oneup.php',
      'includes/plan.php',
      'includes/approval.php',
      'includes/admin.php',
      'includes/admin-calendar.php',
      'includes/admin-settings.php',
      'includes/admin-log.php',
      'includes/admin-guide.php',
      'includes/admin-listing.php',
      'includes/admin-post.php',
      'includes/admin-plan.php',
      'includes/admin-approval.php',
    ];
  }
}

/**
 * Boot after every plugin has loaded: the toolkit's folder sorts after this
 * one, so at this file's load time the toolkit has not defined anything yet.
 * A missing or old toolkit leaves only the notice behind. Includes load behind
 * a Throwable catch, as the toolkit's do, so a broken file is a notice rather
 * than the site's "critical error" page.
 */
if (!function_exists('ias_boot')) {
  function ias_boot(): bool {
    static $booted = null;
    if ($booted !== null) return $booted;
    $booted = false;

    if (!function_exists('ias_dependency_problems') || ias_dependency_problems()) {
      return false; // dependency.php shows the notice
    }

    try {
      foreach (ias_includes() as $file) {
        require_once IAS_PATH . $file;
      }
    } catch (\Throwable $e) {
      if (function_exists('error_log')) {
        error_log(sprintf('[Intel Auctions Social] Load failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
      }
      add_action('admin_notices', function () use ($e) {
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-error"><p><strong>Intel Auctions Social</strong> failed to load and is doing nothing until it is fixed.<br><code>'
          . esc_html($e->getMessage()) . '</code> at <code>' . esc_html($e->getFile()) . ':' . (int) $e->getLine() . '</code></p></div>';
      });
      return false;
    }

    $booted = true;
    /** Everything is loaded and the toolkit is there. */
    do_action('ias_loaded');
    return true;
  }
}
add_action('plugins_loaded', 'ias_boot', 20);

/**
 * Activation runs after plugins_loaded has already fired, so boot here
 * directly; the tables are then created at once rather than on the next
 * admin page. With the toolkit missing nothing is created — the first request
 * after the toolkit arrives does it (ias_db_migrate on admin_init).
 */
register_activation_hook(__FILE__, function () {
  if (function_exists('ias_boot') && ias_boot() && function_exists('ias_db_migrate')) {
    ias_db_migrate();
  }
});
