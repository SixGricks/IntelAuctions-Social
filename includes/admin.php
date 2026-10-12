<?php
/**
 * Intel Auctions Social — where the screens live.
 *
 * Four pages under the Auctions menu, shown as one menu item, **Social
 * Calendar**, whose page carries tabs for the other three (the toolkit's page
 * groups, `ia_ui_page_groups`): Calendar, Settings, Log, Guide. The slugs
 * start with `ia-` so the toolkit's admin stylesheet and double-submit guard
 * load on them, and simple mode keeps the calendar in the menu.
 */

if (!defined('ABSPATH')) exit;

if (!defined('IAS_PAGE_CALENDAR')) define('IAS_PAGE_CALENDAR', 'ia-social-calendar');
if (!defined('IAS_PAGE_SETTINGS')) define('IAS_PAGE_SETTINGS', 'ia-social-settings');
if (!defined('IAS_PAGE_LOG'))      define('IAS_PAGE_LOG', 'ia-social-log');
if (!defined('IAS_PAGE_GUIDE'))    define('IAS_PAGE_GUIDE', 'ia-social-guide');

/** The Auctions menu's parent slug. */
if (!function_exists('ias_menu_parent')) {
  function ias_menu_parent(): string {
    return 'edit.php?post_type=' . ia_schema_post_type();
  }
}

/** URL of one of this plugin's pages, with query args. */
if (!function_exists('ias_admin_url')) {
  function ias_admin_url(string $page = IAS_PAGE_CALENDAR, array $args = []): string {
    $url = admin_url(ias_menu_parent() . '&page=' . $page);
    return $args ? add_query_arg($args, $url) : $url;
  }
}

/**
 * Registered at the same priority as Post Auction Results (50): the toolkit's
 * callbacks were added first, so the calendar lands after Results and before
 * Training — after the listing's own life, which is what it follows.
 */
if (!function_exists('ias_register_admin')) {
  function ias_register_admin(): void {
    $parent = ias_menu_parent();
    add_submenu_page($parent, 'Social Calendar', 'Social Calendar', 'manage_options', IAS_PAGE_CALENDAR, 'ias_calendar_render');
    add_submenu_page($parent, 'Social Settings', 'Social Settings', 'manage_options', IAS_PAGE_SETTINGS, 'ias_settings_render');
    add_submenu_page($parent, 'Social Log', 'Social Log', 'manage_options', IAS_PAGE_LOG, 'ias_log_render');
    add_submenu_page($parent, 'Social Guide', 'Social Guide', 'manage_options', IAS_PAGE_GUIDE, 'ias_guide_render');
  }
}
add_action('admin_menu', 'ias_register_admin', 50);

/** One menu item with tabs (the three other pages hide from the menu but keep their URLs). */
add_filter('ia_ui_page_groups', function ($groups) {
  if (!is_array($groups)) return $groups;
  $groups['social'] = [
    'label'       => 'Social Calendar',
    'head'        => IAS_PAGE_CALENDAR,
    'menu_parent' => ias_menu_parent(),
    'items'       => [
      ['slug' => IAS_PAGE_CALENDAR, 'label' => 'Calendar'],
      ['slug' => IAS_PAGE_SETTINGS, 'label' => 'Settings'],
      ['slug' => IAS_PAGE_LOG,      'label' => 'Log'],
      ['slug' => IAS_PAGE_GUIDE,    'label' => 'Guide'],
    ],
  ];
  return $groups;
});

/** The calendar stays in the Auctions menu in simple mode — it is a weekly errand, not a tool. */
add_filter('ia_simple_mode_keep_auction_pages', function ($slugs) {
  $slugs = (array) $slugs;
  $slugs[] = IAS_PAGE_CALENDAR;
  return array_values(array_unique($slugs));
});

/** Card on Intel SEO → All Tools. */
add_filter('ia_tools_index', function ($sections) {
  if (!is_array($sections) || empty($sections['advanced']['items']) || !is_array($sections['advanced']['items'])) return $sections;
  $sections['advanced']['items'][] = [
    'slug'  => IAS_PAGE_CALENDAR,
    'label' => 'Social Calendar',
    'desc'  => 'The next 30 days of Facebook posts for every listing, with approvals, the switches and the log (Intel Auctions Social).',
  ];
  return $sections;
});

/** The few styles the calendar and panels need beyond the toolkit's stylesheet. */
if (!function_exists('ias_admin_styles')) {
  function ias_admin_styles(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    echo '<style>
      .ias-switches{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 16px}
      .ias-switch{border:1px solid #dcdcde;border-radius:6px;padding:6px 10px;background:#fff;font-size:12px}
      .ias-switch b{display:block;font-size:13px}
      .ias-switch.on{border-color:#00a32a;background:#edfaef}
      .ias-switch.off{color:#50575e}
      .ias-switch.alert{border-color:#d63638;background:#fcf0f1}
      .ias-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;max-width:1400px}
      .ias-cal-head{font-weight:600;font-size:12px;color:#50575e;padding:2px 4px}
      .ias-day{background:#fff;border:1px solid #dcdcde;border-radius:6px;min-height:96px;padding:6px;font-size:12px;overflow:hidden}
      .ias-day.sun{background:#f6f7f7;color:#8c8f94}
      .ias-day.today{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
      .ias-day.floor{background:repeating-linear-gradient(45deg,#fff,#fff 8px,#f6f7f7 8px,#f6f7f7 16px)}
      .ias-day.blank{background:transparent;border:0}
      .ias-day-date{font-weight:600;display:flex;justify-content:space-between;margin-bottom:4px}
      .ias-day-date .n{font-weight:400;color:#8c8f94}
      .ias-chip{display:block;border-left:3px solid #2271b1;background:#f0f6fc;border-radius:3px;padding:3px 5px;margin:0 0 4px;text-decoration:none;color:#1d2327;line-height:1.3}
      .ias-chip:hover{background:#e5effa}
      .ias-chip .t{font-weight:600}
      .ias-chip .s{display:block;color:#50575e;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .ias-chip.st-waiting{border-color:#dba617;background:#fcf9e8}
      .ias-chip.st-held,.ias-chip.st-failed{border-color:#d63638;background:#fcf0f1}
      .ias-chip.st-approved,.ias-chip.st-pushed,.ias-chip.st-published{border-color:#00a32a;background:#edfaef}
      .ias-chip.st-dropped,.ias-chip.st-skipped,.ias-chip.st-cancelled{border-color:#8c8f94;background:#f6f7f7;color:#8c8f94;text-decoration:line-through}
      .ias-chip.st-human{border-color:#8c8f94;background:#fff}
      .ias-legend{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 14px}
      .ias-cols{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:20px;max-width:1400px}
      .ias-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;margin:0 0 16px}
      .ias-card h2,.ias-card h3{margin-top:4px}
      .ias-preview{white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px;font-size:14px;line-height:1.5;max-width:560px}
      .ias-thumbs{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}
      .ias-thumbs img{width:96px;height:72px;object-fit:cover;border-radius:4px;border:1px solid #dcdcde}
      .ias-kv{border-collapse:collapse}
      .ias-kv th{text-align:left;padding:3px 12px 3px 0;color:#50575e;font-weight:600;vertical-align:top;white-space:nowrap}
      .ias-kv td{padding:3px 0;vertical-align:top}
      .ias-json{white-space:pre-wrap;font-family:Menlo,Consolas,monospace;font-size:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px;max-height:420px;overflow:auto}
      @media (max-width:1100px){.ias-cols{grid-template-columns:1fr}.ias-cal{grid-template-columns:repeat(2,minmax(0,1fr))}.ias-cal-head{display:none}.ias-day.blank{display:none}}
    </style>';
  }
}

/**
 * The switches as a strip of chips — on every screen of this plugin, so
 * nobody wonders whether anything can reach Facebook.
 */
if (!function_exists('ias_switches_strip')) {
  function ias_switches_strip(): string {
    $s = ias_settings();
    $out = '<div class="ias-switches">';
    $chip = function (string $label, string $state, string $class) {
      return '<span class="ias-switch ' . esc_attr($class) . '"><b>' . esc_html($label) . '</b>' . esc_html($state) . '</span>';
    };
    $out .= $chip('Master switch', !empty($s['master']) ? 'On' : 'Off — never calls OneUp', !empty($s['master']) ? 'on' : 'off');
    $out .= $chip('Dry run', !empty($s['dry_run']) ? 'On — payloads go to the log' : 'Off', !empty($s['dry_run']) ? 'on' : 'off');
    if (!empty($s['paused'])) $out .= $chip('Paused', 'No pushes until resumed', 'alert');
    foreach (ias_post_types() as $type => $def) {
      if ($type === 'human') continue;
      $on = ias_type_on($type);
      $out .= $chip($def['short'], $on ? 'On' : 'Off', $on ? 'on' : 'off');
    }
    $events = ias_type_on('events');
    $out .= $chip('FB Events', $events ? 'On' : 'Off', $events ? 'on' : 'off');
    $out .= '</div>';
    return $out;
  }
}
