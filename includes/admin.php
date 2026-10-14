<?php
/**
 * Intel Auctions Social — where the screens live.
 *
 * Five pages under the Auctions menu, shown as one menu item, **Social
 * Calendar**, whose page carries tabs for the other four (the toolkit's page
 * groups, `ia_ui_page_groups`): Calendar, Brand voice, Settings, Log, Guide. The slugs
 * start with `ia-` so the toolkit's admin stylesheet and double-submit guard
 * load on them, and simple mode keeps the calendar in the menu.
 */

if (!defined('ABSPATH')) exit;

if (!defined('IAS_PAGE_CALENDAR')) define('IAS_PAGE_CALENDAR', 'ia-social-calendar');
if (!defined('IAS_PAGE_SETTINGS')) define('IAS_PAGE_SETTINGS', 'ia-social-settings');
if (!defined('IAS_PAGE_LOG'))      define('IAS_PAGE_LOG', 'ia-social-log');
if (!defined('IAS_PAGE_GUIDE'))    define('IAS_PAGE_GUIDE', 'ia-social-guide');
if (!defined('IAS_PAGE_VOICE'))    define('IAS_PAGE_VOICE', 'ia-social-voice');

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
    add_submenu_page($parent, 'Brand voice', 'Social Brand Voice', 'manage_options', IAS_PAGE_VOICE, 'ias_voice_render');
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
      ['slug' => IAS_PAGE_VOICE,    'label' => 'Brand voice'],
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
      .ias-cal{display:grid;grid-template-columns:repeat(6,minmax(0,1fr)) minmax(0,.55fr);gap:6px;max-width:1880px}
      .ias-cal-head{font-weight:600;font-size:12px;color:#50575e;padding:2px 4px}.ias-cal-head.sun{color:#8c8f94}
      .ias-cal-gap{display:none}
      .ias-day{background:#fff;border:1px solid #dcdcde;border-radius:6px;min-height:84px;padding:6px;font-size:12px;overflow:hidden}
      .ias-day.sun{background:#f6f7f7;color:#8c8f94}
      .ias-day.today{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
      .ias-day.floor{background:repeating-linear-gradient(45deg,#fff,#fff 8px,#f6f7f7 8px,#f6f7f7 16px)}
      .ias-day.past{background:#f6f7f7;color:#8c8f94}.ias-day.past .ias-chip{opacity:.8}
      .ias-day.beyond{background:transparent;border-style:dashed;color:#8c8f94}
      .ias-day-date{font-weight:600;display:flex;justify-content:space-between;gap:4px;margin-bottom:4px}
      .ias-day-date .n{font-weight:400;color:#8c8f94;cursor:help}
      .ias-today{display:none}
      .ias-chip{display:block;border-left:3px solid #2271b1;background:#f0f6fc;border-radius:3px;padding:3px 5px;margin:0 0 4px;text-decoration:none;color:#1d2327;line-height:1.3}
      .ias-chip:hover{filter:brightness(.96)}
      .ias-chip .t{font-weight:600}
      .ias-chip .tm{white-space:nowrap}
      .ias-chip.untimed{border-left-style:dashed}.ias-chip.untimed .tm{color:#8c8f94;font-style:italic}
      .ias-chip .s{display:block;color:inherit;opacity:.85;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      ' . ias_status_chip_css() . '
      .ias-chip.drag{cursor:grab}.ias-chip.is-dragging{opacity:.45}
      .ias-chip-row{position:relative}.ias-chip.has-onoff{padding-right:34px}
      .ias-onoff-form{position:absolute;top:5px;right:5px;margin:0;line-height:0}
      .ias-onoff{display:block;position:relative;width:26px;height:14px;padding:0;border:0;border-radius:8px;background:#a7aaad;cursor:pointer}
      .ias-onoff.is-on{background:#00a32a}.ias-onoff:hover{filter:brightness(.92)}.ias-onoff:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
      .ias-onoff .knob{position:absolute;top:2px;left:2px;width:10px;height:10px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:left .15s}
      .ias-onoff.is-on .knob{left:14px}
      .ias-pv{position:fixed;z-index:100001;width:340px;max-width:calc(100vw - 24px);background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 8px 28px rgba(0,0,0,.2);pointer-events:none;overflow:hidden;font-size:13px;line-height:1.45;color:#1d2327}
      .ias-pv-head{padding:10px 12px 2px;font-weight:600}.ias-pv-meta{padding:0 12px 8px;color:#50575e;font-size:12px}
      .ias-pv-img{position:relative;background:#f0f0f1}.ias-pv-img img{display:block;width:100%;height:170px;object-fit:cover}
      .ias-pv-more{position:absolute;right:8px;bottom:8px;background:rgba(0,0,0,.65);color:#fff;border-radius:10px;padding:1px 8px;font-size:12px}
      .ias-pv-text{padding:10px 12px;white-space:pre-wrap;overflow-wrap:anywhere;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:12;overflow:hidden}
      .ias-pv-text.empty{color:#8c8f94;font-style:italic}
      .ias-pv-comment{margin:0 12px;padding:8px 0 10px;border-top:1px solid #f0f0f1;color:#50575e;font-size:12px;overflow-wrap:anywhere}
      .ias-pv-stats{margin:0 12px;padding:8px 0 10px;border-top:1px solid #f0f0f1;color:#1d2327;font-size:12px;font-weight:600}
      .ias-pv-hint{padding:6px 12px;background:#f6f7f7;color:#8c8f94;font-size:11px}
      body.ias-dragging .ias-day[data-drop="1"]{outline:1px dashed #8c8f94;outline-offset:-3px}
      .ias-day.ias-drop-ok,.ias-day.ias-drop-target{outline:2px solid #2271b1 !important;outline-offset:-2px;background:#f0f6fc}
      .ias-drop-pop{position:absolute;z-index:100000;background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.18);padding:12px 14px;max-width:min(92vw,460px)}
      .ias-drop-pop .ias-drop-q{margin:0 0 8px}.ias-drop-pop .description{margin:6px 0 0}
      .ias-drop-slots{display:flex;flex-wrap:wrap;gap:6px}.ias-drop-slots .button{height:auto;line-height:1.3;padding:6px 10px;text-align:left}
      .ias-drop-cancel{margin-top:6px}
      .ias-move-slots{border:0;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:4px 14px;align-items:center}.ias-move-slot{white-space:nowrap}
      .ias-legend{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:8px 0 14px}
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
      @media (min-width:1800px){.ias-post{grid-template-columns:minmax(0,1fr) 500px 360px;grid-template-rows:auto;grid-template-areas:"edit preview side"}.ias-post-preview{position:sticky;top:40px;align-self:start;max-height:calc(100vh - 56px);overflow-y:auto}}
      @media (min-width:2600px){.ias-post,.ias-bar.notice,.ias-attn{max-width:2200px}.ias-post{grid-template-columns:minmax(0,1fr) 560px 420px}}
      .ias-post .ias-card{margin:0 0 16px}
      .ias-bar.notice{margin:0 0 16px;max-width:1880px;box-sizing:border-box;padding:10px 14px;display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px}
      .ias-bar .ias-bar-text{flex:1 1 420px}.ias-bar .ias-bar-text p,.ias-bar ul{margin:.15em 0}
      .ias-reasons,.ias-bar ul.ias-reasons{list-style:disc;margin-left:20px}
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
      @media (hover:none){.ias-pic-move{opacity:1}.ias-pic-move button,.ias-pic-x,.ias-pic-alt-edit{width:32px;height:32px;line-height:28px}}
      .ias-pic-alt-edit{position:absolute;top:36px;right:7px;width:24px;height:24px;border-radius:50%;border:1px solid #dcdcde;background:#fff;color:#2271b1;cursor:pointer;font-size:13px;line-height:20px;padding:0}
      .ias-pic-alt-edit:hover,.ias-pic-alt-edit:focus{background:#2271b1;color:#fff;border-color:#2271b1}
      @media (hover:none){.ias-pic-alt-edit{top:44px}}
      .ias-pic.is-editing .ias-pic-alt{display:none}
      .ias-pic-alt-ed textarea{width:100%;font-size:12px;line-height:1.35;margin-top:4px}
      .ias-pic-alt-btns{display:flex;gap:6px;align-items:center;margin-top:3px}
      .ias-pics.ias-flash{animation:iasflash-o 1.2s ease-out}
      @keyframes iasflash-o{from{outline:3px solid #dba617;outline-offset:2px}to{outline:3px solid transparent;outline-offset:2px}}
      .ias-pic-move button{border:0;background:rgba(0,0,0,.6);color:#fff;width:24px;height:24px;border-radius:50%;cursor:pointer;padding:0;font-size:16px;line-height:22px}
      .ias-pic-alt{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:11px;color:#50575e;line-height:1.3;margin-top:4px}
      .ias-pic-hole{border:2px dashed #2271b1;border-radius:6px;background:#f0f6fc;min-height:140px}
      .ias-pic-dirty{color:#996800}
      .ias-summary-scroll{overflow-x:auto}
      /* Talk it over: the chat under the words. */
      .ias-chat-log{max-height:360px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;padding:2px 2px 8px}
      .ias-msg{max-width:88%;border-radius:12px;padding:7px 11px;font-size:13px;line-height:1.45;overflow-wrap:anywhere}
      .ias-msg b{display:block;font-size:11px;color:#50575e;font-weight:600;margin-bottom:1px}
      .ias-msg-t{white-space:pre-wrap}
      .ias-msg.me{align-self:flex-end;background:#e5effa;border-bottom-right-radius:3px}
      .ias-msg.ai{align-self:flex-start;background:#f0f0f1;border-bottom-left-radius:3px}
      .ias-msg.is-wait .ias-msg-t{color:#50575e;font-style:italic}
      .ias-msg.err{align-self:flex-start;background:#fcf0f1;color:#8a2424}
      .ias-msg-acts{margin-top:4px;font-size:12px}.ias-msg-note{color:#50575e}
      .ias-msg-change{margin-top:6px;border-top:1px solid #dcdcde;padding-top:6px}
      .ias-msg-what{font-size:12px;font-weight:600;color:#1d2327}
      .ias-msg-diff{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:6px 8px;margin:4px 0;font-size:12px;line-height:1.45;max-height:150px;overflow-y:auto;overflow-wrap:break-word}
      .ias-msg-diff-h{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin-bottom:2px}
      .ias-msg-diff del{background:#fcf0f1;color:#8a2424}.ias-msg-diff ins{background:#edfaef;color:#00631a;text-decoration:underline}
      .ias-msg-pics{display:flex;flex-wrap:wrap;gap:4px;margin:4px 0}.ias-msg-pics img{width:40px;height:40px;object-fit:cover;border-radius:4px;border:1px solid #dcdcde}
      .ias-msg-pics img:first-child{outline:2px solid #2271b1;outline-offset:-2px}
      .ias-msg-change.is-off .ias-msg-diff,.ias-msg-change.is-off .ias-msg-pics{opacity:.55}
      .ias-msg-change.is-on .ias-msg-note{color:#996800;font-weight:600}
      .ias-msg-warn{margin-top:4px;font-size:12px;color:#8a2424}
      .ias-msg-rule{margin-top:6px;font-size:12px;border-top:1px solid #dcdcde;padding-top:6px}
      .ias-chat-quick{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}
      .ias-chat-chip{border:1px solid #c3c4c7;background:#fff;border-radius:14px;padding:3px 10px;font-size:12px;cursor:pointer;color:#1d2327}
      .ias-chat-chip:hover:not(:disabled){border-color:#2271b1;color:#2271b1}.ias-chat-chip:disabled{opacity:.5;cursor:default}
      .ias-chat-row{display:flex;gap:8px;align-items:flex-end}
      .ias-chat-row textarea{flex:1;min-width:0;resize:vertical;font-size:14px}
      .ias-edit textarea.ias-flash{animation:iasflash 1.2s ease-out}
      @keyframes iasflash{from{background:#fcf9e8;box-shadow:0 0 0 2px #dba617}to{background:#fff;box-shadow:none}}
      .ias-post-preview.is-updating .ias-fb{opacity:.6;transition:opacity .2s}
      /* Brand voice. */
      .ias-diff{white-space:normal;overflow-wrap:break-word;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;margin:8px 0;font-size:13px;line-height:1.5;max-width:720px}
      .ias-diff del,.ias-rule-diff del{background:#fcf0f1;color:#8a2424}.ias-diff ins,.ias-rule-diff ins{background:#edfaef;color:#00631a;text-decoration:underline}
      .ias-rule-diff{list-style:none;margin:8px 0;padding:0}.ias-rule-diff li{margin:0 0 4px}
      .ias-voice-table td{vertical-align:top}.ias-voice-table details summary{cursor:pointer;color:#2271b1}
      .ias-voice-table form.ias-act{display:flex;margin:4px 0 0}
      .ias-asks{list-style:disc;margin:0 0 0 20px}
      .ias-suggest{border-color:#72aee6;background:#f0f6fc}
      .ias-filters{line-height:2}
      .ias-gbp-cta{padding:8px 12px 12px}.ias-gbp-cta a{display:inline-block;border:1px solid #1a73e8;color:#1a73e8;border-radius:18px;padding:5px 16px;font-weight:600;text-decoration:none;font-size:13px}
      .ias-gbp-line .ias-act{display:inline-block;margin:4px 6px 0 0}
      .ias-pulse{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:-6px 0 12px;font-size:13px;color:#50575e}
      .ias-pulse-dot{width:9px;height:9px;border-radius:50%;background:#c3c4c7;flex:none}
      .ias-pulse.is-ok .ias-pulse-dot{background:#00a32a}.ias-pulse.is-error .ias-pulse-dot{background:#d63638}.ias-pulse.is-new .ias-pulse-dot{background:#dba617}
      .ias-pulse.is-error{color:#8a2424}.ias-pulse.is-new{color:#1d2327;font-weight:600}
      .ias-pulse [hidden]{display:none!important}
      .ias-move{margin-top:6px}.ias-move summary{cursor:pointer;color:#2271b1}.ias-move form{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:6px}
      .ias-post [hidden],.ias-bar [hidden]{display:none!important} /* the .button display of WordPress beats the hidden attribute */
      .ias-switches-fold{margin:10px 0 16px}.ias-switches-fold summary{cursor:pointer;list-style:none;display:flex;flex-wrap:wrap;align-items:center;gap:8px}
      .ias-switches-fold summary::-webkit-details-marker{display:none}.ias-switches-fold summary .ias-switch{font-size:13px}
      .ias-switches-more{color:#2271b1;text-decoration:underline;font-size:12px}.ias-switches-fold[open] .ias-switches{margin-top:8px}
      @media (max-width:782px){.ias-voice-table thead{display:none}.ias-voice-table,.ias-voice-table tbody,.ias-voice-table tr,.ias-voice-table td{display:block;width:auto!important}.ias-voice-table tr{border-bottom:1px solid #dcdcde;padding:6px 0}.ias-voice-table td{padding:4px 8px}}
      @media (max-width:1100px){.ias-cols{grid-template-columns:minmax(0,1fr)}}
      /* A phone: the days that carry posts, in a list; each run of empty days one line. */
      @media (max-width:782px){.ias-cal{display:flex;flex-direction:column;gap:6px}.ias-cal-head{display:none}
        .ias-day{min-height:0}.ias-day:not(.has):not(.today),.ias-day.past{display:none}.ias-day.floor{background:#fff}
        .ias-today{display:inline}.ias-day-date{font-size:13px}.ias-chip{padding:6px 8px;font-size:13px}.ias-chip .s{font-size:12px}
        .ias-chip.has-onoff{padding-right:52px}.ias-onoff-form{top:50%;right:8px;transform:translateY(-50%)}.ias-onoff{width:36px;height:20px;border-radius:11px}.ias-onoff .knob{width:16px;height:16px}.ias-onoff.is-on .knob{left:18px}
        .ias-cal-gap{display:block;color:#8c8f94;font-size:12px;padding:2px 8px;border-left:2px dotted #c3c4c7;margin-left:8px}}
      @media (max-width:600px){.ias-pics{grid-template-columns:repeat(3,minmax(0,1fr))}}
    </style>';
  }
}

/**
 * The switches as a strip of chips — on every screen of this plugin, so
 * nobody wonders whether anything can reach Facebook. $compact (the calendar
 * and a post's page): one line saying what that means for Facebook and each
 * other network switched on, the chips folded under it, so the calendar and a
 * post's own bar are on the first screen of a phone.
 */
if (!function_exists('ias_switches_strip')) {
  function ias_switches_strip(bool $compact = false): string {
    $s = ias_settings();
    $out = '<div class="ias-switches">';
    $chip = function (string $label, string $state, string $class) {
      return '<span class="ias-switch ' . esc_attr($class) . '"><b>' . esc_html($label) . '</b>' . esc_html($state) . '</span>';
    };
    $out .= $chip('Master switch', !empty($s['master']) ? 'On' : 'Off — nothing posts', !empty($s['master']) ? 'on' : 'off');
    if (!empty($s['paused'])) $out .= $chip('Paused', 'Nothing posts until resumed', 'alert');
    foreach (ias_post_types() as $type => $def) {
      if ($type === 'human') continue;
      $on = ias_type_on($type);
      $out .= $chip($def['short'], $on ? 'On' : 'Off', $on ? 'on' : 'off');
    }
    if (function_exists('ias_channels_extra_on')) {
      foreach (ias_channels_extra_on() as $ch) {
        $state = (array) apply_filters('ias_channel_state', [], $ch);
        if (!$state) continue; // a preview (Instagram, X, LinkedIn) is not a switch
        $out .= $chip((string) ias_channel($ch)['label'], (string) preg_replace('/^[^:]+:\s*/', '', (string) $state[1]), (string) $state[0]);
      }
    }
    $out .= '</div>';
    if ($compact) {
      $on = array_values(array_filter(array_keys(ias_post_types()), function ($t) { return $t !== 'human' && ias_type_on($t); }));
      $setup = function_exists('ias_oneup_blockers') ? ias_oneup_blockers(true) : [];
      if (empty($s['master'])) $line = ['off', 'Off: nothing goes to Facebook — the master switch is off.'];
      elseif (!empty($s['paused'])) $line = ['alert', 'Paused: nothing goes out until you resume.'];
      elseif (!$on) $line = ['off', 'Off: no post type is switched on, so nothing goes to Facebook.'];
      elseif ($setup) $line = ['alert', 'Not posting yet: ' . $setup[0] . '.'];
      else $line = ['on', 'On: ' . implode(', ', array_map(function ($t) { return ias_post_type($t)['short']; }, $on)) . ' posts go to Facebook.'];
      $more = '';
      foreach (function_exists('ias_channels_summary') ? ias_channels_summary() : [] as [$class, $text]) $more .= ' <span class="ias-switch ' . esc_attr($class) . '">' . esc_html($text) . '</span>';
      $out = '<details class="ias-switches-fold"><summary><span class="ias-switch ' . esc_attr($line[0]) . '">' . esc_html($line[1]) . '</span>' . $more . ' <span class="ias-switches-more">All the switches</span></summary>' . $out . '</details>';
    }
    $gone = function_exists('ias_dry_run_switched_off') ? ias_dry_run_switched_off() : [];
    if ($gone) {
      $names = array_map(function ($t) { return $t === 'gbp' ? 'Google' : (string) ias_post_type($t)['short']; }, $gone);
      $out .= '<div class="notice notice-warning inline"><p><strong>The dry run is gone: every switch is on or off now.</strong> Because the dry run was on, these were switched off so the update would not start posting by itself: ' . esc_html(implode(', ', $names)) . '. Turn on the ones that should post in <a href="' . esc_url(ias_admin_url(IAS_PAGE_SETTINGS)) . '">Settings</a> — on means they really post. (This note goes once Settings is saved.)</p></div>';
    }
    $why = function_exists('ias_ai_blocker') ? ias_ai_blocker() : '';
    if ($why !== '') $out .= '<div class="notice notice-warning inline"><p><strong>Drafts are stopped.</strong> ' . esc_html($why) . ' Planning goes on; a post gets its words once Claude can be asked again.</p></div>';
    return $out;
  }
}
