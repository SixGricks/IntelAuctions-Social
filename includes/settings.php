<?php
/**
 * Intel Auctions Social — settings.
 *
 * One autoloaded option, `ias_settings`, merged over the defaults below, so a
 * key added in a later release has its default the moment the code arrives.
 * Every switch that can make something public starts OFF: the master switch,
 * each post type, and the dry run starts ON.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('ias_settings_defaults')) {
  function ias_settings_defaults(): array {
    return [
      // Safety switches (spec: all off at install).
      'master'    => false,  // off = plan and log, never call OneUp
      'dry_run'   => true,   // write the exact payloads to the log instead of sending them
      'paused'    => false,  // the Pause button on the calendar: stops every push at once
      'types'     => [       // per post type, turned on one at a time as each earns trust
        'listing' => false, 'open_house' => false, 'auction_week' => false,
        'results' => false, 'roundup' => false,
      ],

      // Priority = weight × starting-bid multiplier.
      'weights'   => ['roundup' => 60, 'listing' => 70, 'open_house' => 90, 'auction_week' => 80, 'results' => 100],
      // [min starting bid, multiplier], highest first; the last row catches everything else.
      'bid_tiers' => [[500000, 1.2], [250000, 1.1], [100000, 1.0], [0, 0.9]],

      // Limits.
      'daily_cap'      => 3,
      'daily_min'      => 2,
      'min_gap_hours'  => 3,
      'lead_hours'     => 48,   // the veto window: nothing new is scheduled closer than this
      'plan_days'      => 30,
      'push_days'      => 21,
      'plan_hour'      => 5,    // the morning re-plan runs from 5:00 site time
      'slots'          => [['06:00', '07:30'], ['11:30', '13:00'], ['19:00', '21:00']],
      'jitter_minutes' => 25,

      // People.
      'approvers'   => 'dj@intelauctions.com, jacob@intelauctions.com',
      'review_day'  => 1,       // Monday (ISO 1–7)
      'review_hour' => 7,

      // More networks (DJ, Oct 13, 2026): planned and dry-run only until each gets its own Verify.
      'networks' => [
        'instagram' => ['on' => false, 'account' => ''],
        'x'         => ['on' => false, 'account' => ''],
        'linkedin'  => ['on' => false, 'account' => ''],
      ],

      // OneUp (the key: Settings → OneUp, or INTEL_ONEUP_API_KEY in wp-config, which wins).
      'oneup_category_id'  => '',
      'oneup_account_id'   => '',   // the Facebook page's social_network_id in OneUp
      'oneup_account_name' => '',   // how OneUp's lists name that page (filled in by Check connection / Verify OneUp)
      'oneup_calendar_url' => 'https://www.oneupapp.io/',
      'link_in_comment'    => true, // false = OneUp cannot post first comments; the link ends the post instead

      // Links back to the site.
      'utm_source' => 'facebook',
      'utm_medium' => 'social',

      // Copy and pictures.
      'brand_font_id'        => 0,       // a .ttf/.otf in the Media Library; blank = the bundled font
      'fair_housing_extra'   => '',      // more phrases to stop, one per line
      'grade_size'           => 'medium', // image size Claude grades photos at (tokens grow with pixels)
      'generate_per_run'     => 8,       // most Claude drafts written in one hourly run
      'chat_turns'           => 30,      // Talk it over: Claude's answers a post can have at most (0 = no chat)
      'voice_examples'       => 3,       // posts a person wrote or approved, shown to Claude in each new draft (0 = none)
      'voice_auto'           => true,    // Claude reads the brand-voice log on its own (at most weekly, after 8 new posts) and suggests rules for a person to save
    ];
  }
}

/** All settings, saved values over the defaults (nested arrays merged one level). */
if (!function_exists('ias_settings')) {
  function ias_settings(): array {
    $saved = get_option('ias_settings', []);
    $saved = is_array($saved) ? $saved : [];
    $out = ias_settings_defaults();
    foreach ($saved as $key => $value) {
      if (!array_key_exists($key, $out)) continue; // a key a later release dropped
      if (in_array($key, ['types', 'weights'], true) && is_array($value)) {
        $out[$key] = array_merge($out[$key], array_intersect_key($value, $out[$key]));
      } elseif ($key === 'networks' && is_array($value)) {
        foreach ($out['networks'] as $ch => $def) if (isset($value[$ch]) && is_array($value[$ch])) $out['networks'][$ch] = array_merge($def, array_intersect_key($value[$ch], $def));
      } else {
        $out[$key] = $value;
      }
    }
    return (array) apply_filters('ias_settings', $out);
  }
}

if (!function_exists('ias_setting')) {
  function ias_setting(string $key, $default = null) {
    $all = ias_settings();
    return array_key_exists($key, $all) ? $all[$key] : $default;
  }
}

/** Save some settings (the rest keep their values). */
if (!function_exists('ias_settings_update')) {
  function ias_settings_update(array $changes): void {
    $saved = get_option('ias_settings', []);
    $saved = is_array($saved) ? $saved : [];
    foreach ($changes as $key => $value) $saved[$key] = $value;
    update_option('ias_settings', $saved, true);
  }
}

/** Is post type $type switched on? */
if (!function_exists('ias_type_on')) {
  function ias_type_on(string $type): bool {
    $types = (array) ias_setting('types', []);
    return !empty($types[$type]);
  }
}

/** The approvers' addresses, cleaned. */
if (!function_exists('ias_approvers')) {
  function ias_approvers(): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string) ias_setting('approvers', '')) as $email) {
      $email = sanitize_email($email);
      if ($email !== '' && is_email($email)) $out[] = strtolower($email);
    }
    return array_values(array_unique($out));
  }
}

/** Where the OneUp key comes from: 'constant' (wp-config, wins), 'option' (Settings) or '' (none). */
if (!function_exists('ias_oneup_key_source')) {
  function ias_oneup_key_source(): string {
    if (defined('INTEL_ONEUP_API_KEY') && is_string(INTEL_ONEUP_API_KEY) && trim(INTEL_ONEUP_API_KEY) !== '') return 'constant';
    return trim((string) get_option('ias_oneup_api_key', '')) !== '' ? 'option' : '';
  }
}

/**
 * The OneUp API key ('' when not set): INTEL_ONEUP_API_KEY in wp-config.php,
 * else the one saved under Settings → OneUp (option `ias_oneup_api_key`, not
 * autoloaded, never shown back in full, never logged — DJ, Oct 13, 2026: the
 * keys belong in Settings, not only in wp-config).
 */
if (!function_exists('ias_oneup_key')) {
  function ias_oneup_key(): string {
    $src = ias_oneup_key_source();
    $key = $src === 'constant' ? trim(INTEL_ONEUP_API_KEY) : ($src === 'option' ? trim((string) get_option('ias_oneup_api_key', '')) : '');
    return trim((string) apply_filters('ias_oneup_key', $key)); // the tests supply theirs this way
  }
}

/** A short fingerprint of the key (never the key): Verify OneUp records the key it passed with. */
if (!function_exists('ias_oneup_key_print')) {
  function ias_oneup_key_print(): string {
    $key = ias_oneup_key();
    return $key === '' ? '' : substr(hash('sha256', $key), 0, 12);
  }
}

/** The site's "now" as a DateTimeImmutable in the site time zone. */
if (!function_exists('ias_now')) {
  function ias_now(): DateTimeImmutable {
    $now = apply_filters('ias_now', null); // tests pin the clock here
    if ($now instanceof DateTimeImmutable) return $now->setTimezone(ia_wp_tz());
    return new DateTimeImmutable('now', ia_wp_tz());
  }
}

/**
 * The plugin's "now" as a MySQL datetime in site time. Every timestamp the
 * plugin stores comes from here (not current_time()), so stored times and the
 * rules that compare against them run on the same clock — the one tests pin.
 */
if (!function_exists('ias_now_sql')) {
  function ias_now_sql(): string {
    return ias_now()->format('Y-m-d H:i:s');
  }
}
