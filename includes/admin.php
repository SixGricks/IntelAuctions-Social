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
      .ias-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;max-width:1880px}
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
      .ias-cols{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:20px;max-width:1880px}
      .ias-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;margin:0 0 16px}
      .ias-card h2,.ias-card h3{margin-top:4px}
      .ias-preview{white-space:pre-wrap;overflow-wrap:anywhere;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px;font-size:14px;line-height:1.5;max-width:640px}
      .ias-thumbs{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}
      .ias-thumbs img{width:96px;height:72px;object-fit:cover;border-radius:4px;border:1px solid #dcdcde}
      .ias-kv{border-collapse:collapse}
      .ias-kv th{text-align:left;padding:3px 12px 3px 0;color:#50575e;font-weight:600;vertical-align:top;white-space:nowrap}
      .ias-kv td{padding:3px 0;vertical-align:top}
      .ias-json{white-space:pre-wrap;overflow-wrap:anywhere;font-family:Menlo,Consolas,monospace;font-size:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px;max-height:420px;overflow:auto}
      /* One post: the bar, then edit | preview | details (stacked on a phone, preview first). */
      .ias-post{display:grid;gap:0 20px;max-width:1880px;grid-template-columns:minmax(0,1fr);grid-template-areas:"preview" "edit" "side"}
      .ias-post-edit{grid-area:edit;min-width:0}.ias-post-preview{grid-area:preview;min-width:0}.ias-post-side{grid-area:side;min-width:0}
      @media (min-width:1280px){.ias-post{grid-template-columns:minmax(0,1fr) 480px;grid-template-rows:auto 1fr;grid-template-areas:"edit preview" "edit side"}.ias-post-side{align-self:start}}
      @media (min-width:1800px){.ias-post{grid-template-columns:minmax(0,1fr) 500px 360px;grid-template-rows:auto;grid-template-areas:"edit preview side"}}
      @media (min-width:2600px){.ias-post,.ias-bar.notice,.ias-attn{max-width:2200px}.ias-post{grid-template-columns:minmax(0,1fr) 560px 420px}}
      .ias-post .ias-card{margin:0 0 16px}
      .ias-bar.notice{margin:0 0 16px;max-width:1880px;box-sizing:border-box;padding:10px 14px;display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px}
      .ias-bar .ias-bar-text{flex:1 1 420px}.ias-bar .ias-bar-text p,.ias-bar ul{margin:.15em 0}
      .ias-reasons{list-style:disc;margin-left:20px}
      .ias-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
      .ias-actions form,form.ias-act{display:inline-flex;gap:6px;align-items:center;margin:0}
      .ias-skip summary{cursor:pointer;color:#b32d2e;text-decoration:underline;list-style:none;padding:4px 2px}
      .ias-skip summary::-webkit-details-marker{display:none}
      .ias-skip[open] summary{margin-bottom:6px}
      .ias-edit textarea{width:100%;font-size:14px;line-height:1.5}
      .ias-foot{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;justify-content:space-between;border-top:1px solid #f0f0f1;padding-top:10px;margin-top:10px}
      .ias-attn{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:0 20px;max-width:1880px}
      .ias-trail{margin:0}.ias-trail li{margin-bottom:6px}
      .ias-post-side details summary,.ias-post-preview details summary{cursor:pointer;color:#2271b1}
      mark.ias-verify{background:#fcf0f1;color:#b32d2e;box-shadow:0 0 0 1px #d63638;border-radius:2px;padding:0 2px}
      /* The preview: a post as the network shows it. */
      .ias-prev-head{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
      .ias-nets{display:flex;gap:2px;background:#f0f0f1;border-radius:6px;padding:2px}
      .ias-net{border:0;background:transparent;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px;color:#50575e}
      .ias-net.is-on{background:#fff;color:#1d2327;font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.12)}
      .ias-fb{background:#fff;border:1px solid #dadde1;border-radius:8px;max-width:500px;font:15px/1.34 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#050505;overflow:hidden}
      .ias-fb-head{display:flex;gap:8px;align-items:center;padding:12px 16px 0}
      .ias-fb-avatar{width:40px;height:40px;border-radius:50%;background:#233a66;color:#fff;flex:none;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;overflow:hidden}
      .ias-fb-avatar img{width:100%;height:100%;object-fit:cover}
      .ias-fb-name{font-weight:600}.ias-fb-time{color:#65676b;font-size:13px}
      .ias-fb-text{padding:8px 16px 12px;white-space:pre-wrap;overflow-wrap:anywhere}
      .ias-fb-photos{display:flex;flex-wrap:wrap;gap:2px}
      .ias-fb-photos a{position:relative;display:block;flex:1 1 0;min-width:0}
      .ias-fb-photos img{width:100%;aspect-ratio:1;object-fit:cover;display:block}
      .ias-fb-photos a:first-child{flex-basis:100%}
      .ias-fb-photos a:first-child img{aspect-ratio:3/2}
      .ias-fb-photos a:only-child img{aspect-ratio:auto}
      .ias-photos-instagram a:first-child img{aspect-ratio:1}
      .ias-photos-x a{flex-basis:calc(50% - 1px)}.ias-photos-x a:first-child{flex-basis:calc(50% - 1px)}.ias-photos-x a:first-child img,.ias-photos-x img{aspect-ratio:16/9}.ias-photos-x a:only-child{flex-basis:100%}
      .ias-fb-photos .more{position:absolute;inset:0;background:rgba(0,0,0,.45);color:#fff;font-size:26px;font-weight:600;display:flex;align-items:center;justify-content:center}
      .ias-fb-comment{display:flex;gap:6px;padding:10px 16px 12px;border-top:1px solid #ced0d4}
      .ias-fb-comment .ias-fb-avatar{width:32px;height:32px;font-size:11px}
      .ias-fb-bubble{background:#f0f2f5;border-radius:18px;padding:8px 12px;font-size:13px;white-space:pre-wrap;overflow-wrap:anywhere;min-width:0}
      .ias-fb-bubble b{display:block}
      .ias-fb-label{font-size:11px;color:#65676b;padding:4px 16px 8px;text-transform:uppercase;letter-spacing:.04em}
      /* The picture editor: square tiles, alt text under each, the lead marked. */
      .ias-pics{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:10px;margin:8px 0 4px;padding:0;list-style:none;min-height:60px}
      .ias-pic{position:relative;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px;margin:0;cursor:move}
      .ias-pic:first-child{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
      .ias-pic img{display:block;width:100%;aspect-ratio:1;object-fit:cover;border-radius:4px;pointer-events:none;-webkit-user-drag:none;user-select:none}
      .ias-pic-n{position:absolute;top:8px;left:8px;background:rgba(0,0,0,.7);color:#fff;font-size:11px;font-weight:600;border-radius:10px;padding:1px 7px}
      .ias-pic-x{position:absolute;top:7px;right:7px;width:24px;height:24px;border-radius:50%;border:1px solid #dcdcde;background:#fff;color:#b32d2e;cursor:pointer;font-size:15px;line-height:20px;padding:0}
      .ias-pic-x:hover,.ias-pic-x:focus{background:#b32d2e;color:#fff;border-color:#b32d2e}
      .ias-pic-move{position:absolute;left:4px;right:4px;top:calc(50% - 10px);display:flex;justify-content:space-between;opacity:0}
      .ias-pic:hover .ias-pic-move,.ias-pic-move:focus-within{opacity:1}
      .ias-pic-move button{border:0;background:rgba(0,0,0,.6);color:#fff;width:24px;height:24px;border-radius:50%;cursor:pointer;padding:0;font-size:16px;line-height:22px}
      .ias-pic-alt{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:11px;color:#50575e;line-height:1.3;margin-top:4px}
      .ias-pic-hole{border:2px dashed #2271b1;border-radius:6px;background:#f0f6fc;min-height:140px}
      .ias-pic-dirty{color:#996800}
      .ias-summary-scroll{overflow-x:auto}
      @media (max-width:600px){.ias-pics{grid-template-columns:repeat(3,minmax(0,1fr))}.ias-cal{grid-template-columns:minmax(0,1fr)}.ias-day{min-height:0}.ias-day.sun{display:none}}
      @media (max-width:1100px){.ias-cols{grid-template-columns:minmax(0,1fr)}.ias-cal{grid-template-columns:repeat(2,minmax(0,1fr))}.ias-cal-head{display:none}.ias-day.blank{display:none}}
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
    if (function_exists('ias_channels_extra_on')) {
      foreach (ias_channels_extra_on() as $ch) $out .= $chip((string) ias_channel($ch)['label'], 'Dry run only — not sent yet', 'on');
    }
    $out .= '</div>';
    $why = function_exists('ias_ai_blocker') ? ias_ai_blocker() : '';
    if ($why !== '') $out .= '<div class="notice notice-warning inline"><p><strong>Drafts are stopped.</strong> ' . esc_html($why) . ' Planning goes on; a post gets its words once Claude can be asked again.</p></div>';
    return $out;
  }
}
