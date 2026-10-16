<?php
/**
 * 0.14.0, DJ, Oct 14, 2026: "I also want to be able to turn on skipped items
 * and turn off others." — and "Hover over an item on the calendar should give
 * a preview of the post."
 *
 * Every post on the calendar that can be switched has a switch: on, a post
 * that has not gone out is turned off (Skip — off OneUp at once); off, a
 * skipped, cancelled or dropped one is turned back on (Restore — the plan
 * gives it a time at once, and OneUp follows). Posts that went out, a
 * person's own, and old "Not sent" ones have none. A switch comes back to
 * the calendar. Each chip carries its preview: type and listing, where it
 * stands and when, the lead picture and how many more, the words, the start
 * of the first comment.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_t_oneup();
$S =& $GLOBALS['ias_t_oneup'];
$S['gbp'] = false;
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$mk = function (string $title, string $sale, string $county) use ($T, $lead) {
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing(['title' => $title, 'address' => $title . ', Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => $county,
    'stage' => 'upcoming', 'sale' => $sale, 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
};
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$back = '';
$act = function (string $action, array $post) use (&$back) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $back = (string) ($_POST['ias_back'] ?? '');
  $id = (int) ($post['ias_post'] ?? 0);
  if ($id && in_array($action, ias_push_now_actions(), true)) ias_push_now_flash($id); // what the calendar's handler does next
  $_POST = [];
};
$flash = function () { $k = 'ia_flash_' . get_current_user_id(); $h = (string) get_transient($k); delete_transient($k); return $h; };
$calendar = function () { $_GET = ['page' => IAS_PAGE_CALENDAR]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = []; return $h; };
$switch = function (string $html, int $id) {
  if (!preg_match('~<form method="post" class="ias-onoff-form">(?:(?!</form>).)*name="ias_post" value="' . $id . '">(?:(?!</form>).)*</form>~s', $html, $m)) return null;
  return ['on' => strpos($m[0], 'is-on') !== false, 'action' => preg_match('/name="ias_cal_action" value="(\w+)"/', $m[0], $a) ? $a[1] : '', 'html' => $m[0]];
};
$preview = function (string $html, int $id) {
  if (!preg_match('~<a class="ias-chip[^"]*" href="[^"]*post=' . $id . '" data-pv="([^"]*)"~', $html, $m)) return null;
  return json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
};

ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
$a = $mk('12 Alpha Road', '2026-11-28 10:00', 'Lancaster');
$b = $mk('7 Bravo Lane', '2026-11-27 10:00', 'Berks');
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$aid = (int) $lp($a)['id'];
$bid = (int) $lp($b)['id'];
t_ok(ias_post_sent(ias_post_get($aid))['mode'] === 'live', 'setting out: the Listing posts are on OneUp');

// ---- 1. The switch ----
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$html = $calendar();
restore_error_handler();
t_eq([], $errors, 'the calendar renders without a notice');
$sw = $switch($html, $aid);
t_ok($sw && $sw['on'] && $sw['action'] === 'skip', 'a post on its way has its switch on; pressing it turns it off');
t_ok($sw && strpos($sw['html'], 'role="switch" aria-checked="true"') !== false && strpos($sw['html'], 'name="_wpnonce"') !== false && strpos($sw['html'], 'name="ias_from" value="calendar"') !== false, '…a nonce-checked switch, named for the screen reader, that comes back to the calendar');
t_ok(strpos($html, 'its switch turns it off or back on') !== false, 'the calendar says what the switch does');

$oid = ias_post_sent(ias_post_get($aid))['id'];
$act('skip', ['ias_post' => $aid, 'ias_from' => 'calendar', 'ias_back' => ias_admin_url(IAS_PAGE_CALENDAR)]);
$p = ias_post_get($aid);
t_eq('skipped', $p['status'], 'switched off: the post is off (skipped)');
t_ok(ias_post_sent($p)['mode'] === '' && !isset($S['scheduled'][(int) $oid]), '…and taken off OneUp at once');
t_eq(ias_admin_url(IAS_PAGE_CALENDAR), $back, '…and the page comes back to the calendar');
$fl = $flash();
t_ok(strpos($fl, 'Turned off') !== false && strpos($fl, 'taken off OneUp') !== false, 'the notice says so');
$sw = $switch($calendar(), $aid);
t_ok($sw && !$sw['on'] && $sw['action'] === 'restore', 'now its switch is off; pressing it turns it back on');

$act('restore', ['ias_post' => $aid, 'ias_from' => 'calendar', 'ias_back' => ias_admin_url(IAS_PAGE_CALENDAR)]);
$p = ias_post_get($aid);
t_eq('planned', $p['status'], 'switched back on: on the plan again');
t_ok(!empty($p['scheduled_at']) && $p['scheduled_at'] >= ias_now()->modify('+48 hours')->format('Y-m-d H:i:s'), '…with a time the plan gave it (' . $p['scheduled_at'] . ')');
t_ok(ias_post_sent($p)['mode'] === 'live', '…and on OneUp again at once');
$fl = $flash();
t_ok(strpos($fl, 'Turned back on: it goes ') !== false, 'the notice says when it goes (' . strip_tags($fl) . ')');

// A post the plan dropped: turned on, it is placed.
$f = ias_post_get($bid)['flags']; $f['dropped_by'] = 'scheduler';
ias_post_update($bid, ['status' => 'dropped', 'flags' => $f, 'note' => 'No room in its window (a test).']);
$sw = $switch($calendar(), $bid);
t_ok($sw && !$sw['on'] && $sw['action'] === 'restore', 'a dropped post has its switch off');
$act('restore', ['ias_post' => $bid, 'ias_from' => 'calendar', 'ias_back' => ias_admin_url(IAS_PAGE_CALENDAR)]);
t_ok(in_array(ias_post_get($bid)['status'], ['planned', 'dropped'], true), 'switched on, the plan takes it back if it can (' . ias_post_get($bid)['status'] . ')');
$flash();

// No switch: gone out, a person's own, or "Not sent" from the old dry run.
ias_post_update($bid, ['status' => 'published']);
$hid = ias_t_oneup_human('2026-11-12 12:10', 'A post DJ made in OneUp');
ias_push_run();
$html = $calendar();
t_eq(null, $switch($html, $bid), 'a post that went out has no switch');
$human = ias_posts_query(['status' => 'human', 'limit' => 5]);
t_ok($human && $switch($html, (int) $human[0]['id']) === null, 'nor a person\'s own post');
$f = ias_post_get($bid)['flags']; $f['dry_run'] = true;
ias_post_update($bid, ['flags' => $f]);
t_eq(null, $switch($calendar(), $bid), 'nor one whose time came before posting was on');
ias_post_update($bid, ['status' => 'planned', 'flags' => array_diff_key($f, ['dry_run' => 1])]);

// The post's own page says it the same way.
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $aid]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = [];
t_ok(strpos($h, 'Turn this post off…') !== false && strpos($h, '>Turn it off</button>') !== false, 'a post\'s page offers Turn this post off…');
$act('skip', ['ias_post' => $aid]);
t_ok(strpos($back, 'post=' . $aid) !== false, 'from its own page, it comes back to its page');
$flash();
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $aid]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = [];
t_ok(strpos($h, '>Turn it back on</button>') !== false && strpos($h, 'It stays off until you turn it back on') !== false, '…and, off, Turn it back on');
$act('restore', ['ias_post' => $aid]);
$flash();

// ---- 2. The preview ----
$html = $calendar();
$pv = $preview($html, $aid);
$p = ias_post_get($aid);
t_ok(is_array($pv), 'each post on the calendar carries its preview');
t_eq('Listing · 12 Alpha Road', $pv['head'] ?? '', '…its type and listing');
t_eq(date_i18n('D M j, g:i a', strtotime((string) $p['scheduled_at'])), $pv['when'] ?? '', '…when it goes');
t_ok(strpos((string) ($pv['note'] ?? ''), 'Pushed') === 0, '…where it stands (' . ($pv['note'] ?? '') . ')');
t_eq(mb_substr(trim((string) $p['content']['text']), 0, 60), mb_substr((string) ($pv['text'] ?? ''), 0, 60), '…its words');
t_ok(empty($pv['empty']), '…as a draft');
$first = (array) ($p['content']['media']['images'][0] ?? []);
t_ok(($pv['img'] ?? '') !== '' && ($pv['img'] === (string) wp_get_attachment_image_url((int) ($first['id'] ?? 0), 'medium') || $pv['img'] === (string) ($first['url'] ?? '')), '…its lead picture, at the medium size');
t_eq(max(0, count((array) $p['content']['media']['images']) - 1), (int) ($pv['more'] ?? -1), '…and how many more');
t_ok(($pv['comment'] ?? '') !== '' && mb_strlen((string) $pv['comment']) <= 161, '…the start of its first comment');
t_eq('Click to open · drag to move', $pv['hint'] ?? '', '…and what a click and a drag do');
t_ok(strpos($html, 'data-pv=') !== false && strpos($html, ' title="Planned') === false, 'the preview replaces the old tooltip');

// A post with no draft yet says so.
$c = $mk('3 Charlie Court', '2026-11-26 10:00', 'Lebanon');
ias_sync();
$cp = $lp($c);
ias_post_update((int) $cp['id'], ['scheduled_at' => '2026-11-10 19:10:00']);
$pv = $preview($calendar(), (int) $cp['id']);
t_ok($pv && !empty($pv['empty']) && strpos((string) $pv['text'], 'No draft') === 0, 'a post with no draft yet: its preview says so (' . ($pv['text'] ?? '') . ')');

// Words are text, never HTML.
$cc = (array) ias_post_get($aid)['content'];
$cc['text'] = "A <b>bold</b> \"quote\" & more";
ias_post_update($aid, ['content' => $cc]);
$html = $calendar();
t_ok(strpos($html, '<b>bold</b>') === false && ($preview($html, $aid)['text'] ?? '') === "A <b>bold</b> \"quote\" & more", 'the words are carried escaped, and come back exactly as written');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'types' => ['listing' => false]]);
delete_option('ias_oneup_verify');
