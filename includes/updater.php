<?php
// GitHub-based auto-updater for Intel Auctions Social (same mechanism as the toolkit's).
if (!defined('ABSPATH')) exit;

if (!class_exists('IAS_Updater')) {

class IAS_Updater {

  private string $plugin_slug;
  private string $plugin_file;
  private string $github_repo = 'SixGricks/IntelAuctions-Social';
  private string $api_url     = 'https://api.github.com/repos/SixGricks/IntelAuctions-Social/releases/latest';
  private string $transient_key = 'ias_updater_response';
  private int    $cache_seconds = 43200; // 12 hours

  public function __construct() {
    $this->plugin_file = plugin_basename(IAS_FILE);
    $this->plugin_slug = dirname($this->plugin_file);

    add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
    add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
    add_filter('upgrader_source_selection', [$this, 'rename_source'], 10, 4);
    add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
  }

  /**
   * Fetch the latest release from GitHub (cached).
   */
  private function fetch_release(): ?object {
    $cached = get_transient($this->transient_key);
    if ($cached === 'none') {
      return null;
    }
    if ($cached !== false && is_object($cached)) {
      return $cached;
    }

    $response = wp_remote_get($this->api_url, [
      'headers' => ['Accept' => 'application/vnd.github.v3+json'],
      'timeout' => 10,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
      set_transient($this->transient_key, 'none', 1800); // retry after 30 min
      return null;
    }

    $release = json_decode(wp_remote_retrieve_body($response));
    if (!is_object($release) || empty($release->tag_name)) {
      set_transient($this->transient_key, 'none', 1800);
      return null;
    }

    // Skip pre-releases
    if (!empty($release->prerelease)) {
      set_transient($this->transient_key, 'none', 1800);
      return null;
    }

    set_transient($this->transient_key, $release, $this->cache_seconds);
    return $release;
  }

  /**
   * Strip leading "v" from a tag name to get a clean version string.
   */
  private function parse_version(string $tag): string {
    return preg_replace('/^[vV]/', '', $tag);
  }

  /**
   * Get the best download URL from a release.
   * Prefers an uploaded .zip asset over the auto-generated zipball.
   */
  private function get_download_url(object $release): string {
    if (!empty($release->assets) && is_array($release->assets)) {
      // Prefer the canonically named build; never install a random zip
      // that happens to be attached to the release (logs, samples…).
      // Only that name: a toolkit zip attached by mistake must never be
      // installed over this plugin.
      foreach ($release->assets as $asset) {
        if (!empty($asset->browser_download_url) && !empty($asset->name)
          && (string) $asset->name === 'intel-auctions-social.zip') {
          return $this->trusted_url((string) $asset->browser_download_url);
        }
      }
    }
    return $this->trusted_url((string) ($release->zipball_url ?? ''));
  }

  /** A package address on GitHub's own hosts, else nothing: a tampered release record cannot point the installer elsewhere (0.27.0, as the toolkit's 4.72.0). */
  private function trusted_url(string $url): string {
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $ok = (array) apply_filters('ias_updater_hosts', ['github.com', 'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com']);
    return strpos($url, 'https://') === 0 && in_array($host, $ok, true) ? $url : '';
  }

  /**
   * Inject update data into WordPress's update transient.
   */
  public function check_for_update($transient) {
    if (!is_object($transient)) {
      return $transient;
    }

    $release = $this->fetch_release();
    if (!$release) {
      return $transient;
    }

    $remote_version = $this->parse_version($release->tag_name);
    $local_version  = defined('IAS_VERSION') ? IAS_VERSION : '0.0.0';

    if (version_compare($remote_version, $local_version, '>')) {
      $transient->response[$this->plugin_file] = (object) [
        'slug'         => $this->plugin_slug,
        'plugin'       => $this->plugin_file,
        'new_version'  => $remote_version,
        'url'          => 'https://github.com/' . $this->github_repo,
        'package'      => $this->get_download_url($release),
        'icons'        => [],
        'banners'      => [],
        'tested'       => '',
        'requires'     => '5.3',
        'requires_php' => '7.4',
      ];
    } else {
      $transient->no_update[$this->plugin_file] = (object) [
        'slug'         => $this->plugin_slug,
        'plugin'       => $this->plugin_file,
        'new_version'  => $remote_version,
        'url'          => 'https://github.com/' . $this->github_repo,
      ];
    }

    return $transient;
  }

  /**
   * Provide plugin info for the "View details" modal.
   */
  public function plugin_info($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->plugin_slug) {
      return $result;
    }

    $release = $this->fetch_release();
    if (!$release) {
      return $result;
    }

    $remote_version = $this->parse_version($release->tag_name);
    $body = !empty($release->body) ? nl2br(esc_html($release->body)) : 'See GitHub releases for details.';

    return (object) [
      'name'          => 'Intel Auctions Social',
      'slug'          => $this->plugin_slug,
      'version'       => $remote_version,
      'author'        => '<a href="https://github.com/' . $this->github_repo . '">Six Gricks Solutions (DJ)</a>',
      'homepage'      => 'https://github.com/' . $this->github_repo,
      'requires'      => '5.3',
      'requires_php'  => '7.4',
      'downloaded'    => 0,
      'last_updated'  => $release->published_at ?? '',
      'sections'      => [
        'description' => $body,
        'changelog'   => $body,
      ],
      'download_link' => $this->get_download_url($release),
    ];
  }

  /**
   * Rename the extracted folder from GitHub's default name to the expected plugin slug.
   * GitHub zipballs extract to "Owner-Repo-sha/" — WordPress needs "intel-auctions-social/".
   */
  public function rename_source($source, $remote, $upgrader, $hook_extra) {
    if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
      return $source;
    }

    $expected = trailingslashit($this->plugin_slug);
    if (basename(untrailingslashit($source)) === $this->plugin_slug) {
      return $source;
    }

    $corrected = trailingslashit(dirname(untrailingslashit($source))) . $expected;

    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->move($source, $corrected, true)) {
      return $corrected;
    }

    // Installing under the GitHub zipball folder name would create a
    // second plugin copy — abort the upgrade cleanly instead.
    return new WP_Error('ia_rename_failed', 'Could not rename the update folder to ' . $this->plugin_slug . ' — upgrade aborted, the installed version is unchanged.');
  }

  /**
   * Clear cached release data after the plugin has been updated.
   */
  public function clear_cache($upgrader, $options): void {
    $action = $options['action'] ?? '';
    if (
      !in_array($action, ['update', 'install'], true) ||
      ($options['type'] ?? '') !== 'plugin'
    ) {
      return;
    }

    $plugins = $options['plugins'] ?? ($options['plugin'] ?? []);
    if (is_string($plugins)) {
      $plugins = [$plugins];
    }

    $is_ours = is_array($plugins) && in_array($this->plugin_file, $plugins, true);

    // For installs the plugins key may be empty; invalidate unconditionally.
    if ($is_ours || $action === 'install') {
      delete_transient($this->transient_key);

      // Invalidate opcache for all plugin PHP files to prevent stale
      // bytecode from mixing old/new function definitions after upgrade.
      if (function_exists('opcache_invalidate')) {
        $dir = defined('IAS_PATH') ? IAS_PATH : plugin_dir_path(__DIR__);
        $files = glob($dir . '*.php') ?: [];
        $files = array_merge($files, glob($dir . 'includes/*.php') ?: []);
        foreach ($files as $file) {
          opcache_invalidate($file, true);
        }
      }
    }
  }
}

// Only run the updater in admin/cron contexts — it has no front-end role,
// and keeping it out of public requests removes any chance of its hooks
// crashing a visitor-facing page. Inside the class_exists guard so we never
// instantiate a foreign class that happens to share our name.
//
// Wrapped in try/catch so a transient network / wp_remote_get / filesystem
// failure during plugin activation can't take down the admin with a fatal.
if (is_admin() || (function_exists('wp_doing_cron') && wp_doing_cron())) {
  try {
    new IAS_Updater();
  } catch (\Throwable $e) {
    if (function_exists('error_log')) {
      error_log('[Intel Auctions Social] Updater init failed: ' . $e->getMessage());
    }
  }
}

} // end class_exists guard
