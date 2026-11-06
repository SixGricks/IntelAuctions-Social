<?php
/**
 * 0.29.0, DJ, Nov 6, 2026: "We are now using LinkedIn but the posts are not
 * hitting. Check what's needed to get this more than just a preview." —
 * LinkedIn becomes a network the plugin sends to, the way Google is: its own
 * switch (off at install, and turned off once by the update where the old
 * preview switch was on), its own Verify, its own record (`flags.li`), every
 * post type by default (DJ's choice), only to the Intel Auctions page — the
 * OneUp category also holds other companies' LinkedIn pages — and Facebook's
 * rules: never twice, replaced and taken back only by id, a person's delete in
 * OneUp kept, a refusal stopping LinkedIn only.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_t_oneup();
$S =& $GLOBALS['ias_t_oneup'];
$S['gbp'] = false;
$S['linkedin'] = true;
$LI = 'urn:li:organization:144101021';
$OTHER = 'urn:li:organization:15962879';
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$mk = function (string $title, string $sale) use ($T, $lead) {
  $gal = []; for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing(['title' => $title, 'address' => $title . ', Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
    'stage' => 'upcoming', 'sale' => $sale, 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
};
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$writes = function (int $from, string $acct = '') {
  return array_values(array_filter(ias_t_oneup_calls($from), function ($c) use ($acct) {
    $sn = (string) ($c['params']['social_network_id'] ?? '');
    $d = json_decode($sn, true);
    if (is_array($d)) $sn = (string) ($d[0] ?? '');
    return in_array($c['path'], ['scheduletextpost', 'scheduleimagepost', 'schedulevideopost', 'deletescheduledpost'], true) && ($acct === '' || $sn === $acct);
  }));
};
$act = function (string $action, array $post) { $_POST = ['ias_cal_action' => $action] + array_map('strval', $post); do_action('ias_calendar_action', $action); $_POST = []; };
$screen = function (string $page, array $get = []) {
  $_GET = ['page' => $page] + $get; ob_start();
  if ($page === IAS_PAGE_SETTINGS) ias_settings_render(); else ias_calendar_render();
  $h = (string) ob_get_clean(); $_GET = []; return $h;
};
$save = function (array $fields) { [$new, $problems] = ias_settings_sanitize($fields + ias_settings(), ias_settings()); ias_settings_update($new); return $problems; };
$li = function (array $p) { return (array) ($p['flags']['li'] ?? []); };
wp_set_current_user(1);

// ---- 1. The update: a preview switch left on is turned off, and Settings says so ----
$raw = (array) get_option('ias_settings', []);
$raw['networks']['linkedin'] = ['on' => true, 'account' => ''];
update_option('ias_settings', $raw);
delete_option('ias_linkedin_previewed');
do_action('admin_init');
t_ok(!ias_channel_settings('linkedin')['on'], 'the update turns LinkedIn\'s old preview switch off: it posts now, and an update never starts posting');
t_ok(!empty(get_option('ias_linkedin_previewed')['was_on']), '…and remembers that it did');
t_eq(['listing', 'open_house', 'auction_week', 'results', 'roundup'], ias_channel_settings('linkedin')['types'], 'every post type goes to LinkedIn by default (DJ\'s choice)');
ias_settings_update(['master' => true]);
ias_oneup_directory_fetch('admin');
$html = $screen(IAS_PAGE_SETTINGS);
t_ok(strpos($html, 'LinkedIn posts now.') !== false, 'Settings says LinkedIn posts now and was switched off by the update');
$o = ias_oneup_pick_options(['category' => '42']);
t_ok(strpos($o['ias-net-linkedin'], 'value="' . $LI . '"') !== false && strpos($o['ias-net-linkedin'], 'value="' . $OTHER . '"') !== false && strpos($o['ias-net-linkedin'], 'fb_123') === false, 'the LinkedIn list offers the category\'s LinkedIn pages only');
t_ok(strpos($o['ias-net-linkedin'], ' selected>Intel Auctions') === false, '…and with two pages there, chooses none for you');
$save(['oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'networks' => ['linkedin' => ['account' => $LI]]]);
t_eq('Intel Auctions', ias_channel_settings('linkedin')['name'], 'choosing the page keeps its name in OneUp (the read-back finds its posts by it)');
update_option('ias_linkedin_previewed', ['seen' => true] + (array) get_option('ias_linkedin_previewed')); // as saving Settings does
t_ok(strpos($screen(IAS_PAGE_SETTINGS), 'LinkedIn posts now.') === false, '…and once Settings has been saved the note goes');

// ---- 2. LinkedIn's words and request ----
$a = $mk('12 Alpha Road', '2026-11-28 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run();
$p = $lp($a);
$req = ias_render_channel($p, 'linkedin');
t_eq($LI, (string) $req['params']['social_network_id'], 'LinkedIn: to the Intel Auctions page chosen, by its id');
t_eq(date('Y-m-d H:i', strtotime((string) $p['scheduled_at'] . ' +2 minutes')), (string) $req['params']['scheduled_date_time'], '…two minutes after the Facebook post (Google takes one)');
t_ok(strpos((string) $req['params']['content'], 'utm_source=linkedin') !== false, '…the listing link in the words, tagged for LinkedIn');
t_ok($req['endpoint'] === 'schedule_image' && count(explode('~~', (string) $req['params']['image_url'])) <= 9, '…with its pictures, nine at most');

// ---- 3. Off; on, it waits for Verify for LinkedIn ----
$n = count($S['calls']);
ias_settings_update(['types' => ['listing' => true]]);
ias_oneup_verify('admin');
ias_push_run();
t_eq([], $writes($n, $LI), 'LinkedIn posts off: nothing goes to LinkedIn (Facebook goes as before)');
$save(['networks' => ['linkedin' => ['on' => '1', 'account' => $LI, 'types' => ['listing', 'open_house', 'auction_week', 'results', 'roundup']]]]);
t_ok(in_array('Verify for LinkedIn has not passed for this page', ias_li_blockers(), true), 'switched on, nothing goes until Verify for LinkedIn has passed — and it says so');
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n, $LI), '…nothing is sent');
$view = $screen(IAS_PAGE_CALENDAR, ['post' => (string) $lp($a)['id']]);
t_ok(strpos($view, 'data-net="linkedin"') !== false && strpos($view, 'Not sent to LinkedIn: ') !== false && strpos($view, 'A preview only') === false, 'a post\'s page has a LinkedIn tab that says why it is not sent — no longer "a preview only"');

// ---- 4. Verify for LinkedIn ----
$n = count($S['calls']);
$v = ias_oneup_verify('admin', 'linkedin');
$bad = array_filter($v['steps'], function ($s) { return $s['ok'] !== true; });
t_ok($v['ok'], 'Verify for LinkedIn passes every step' . ($bad ? ': ' . implode(' | ', array_map(function ($s) { return $s['label'] . ' — ' . $s['detail']; }, $bad)) : ''));
t_eq('The LinkedIn page', (string) $v['steps'][0]['label'], '…checking the page is a LinkedIn one');
$drafts = ias_t_oneup_calls($n, 'scheduletextpost');
t_ok($drafts && ($drafts[0]['params']['isDraftPost'] ?? '') === 'true' && strpos((string) ($drafts[0]['params']['social_network_id'] ?? ''), $LI) !== false, '…with a draft for that page');
t_eq([], $S['drafts'], '…deleted again');
t_ok(ias_li_verified(), 'it has passed for LinkedIn');
$save(['networks' => ['linkedin' => ['on' => '1', 'account' => 'fb_123', 'types' => ['listing']]]]);
$v2 = ias_oneup_verify('admin', 'linkedin');
t_ok(!$v2['ok'] && strpos((string) $v2['steps'][0]['detail'], 'not a LinkedIn page') !== false, 'Verify for LinkedIn refuses the Facebook page as a LinkedIn page');
$save(['networks' => ['linkedin' => ['on' => '1', 'account' => $LI, 'types' => ['listing', 'open_house', 'auction_week', 'results', 'roundup']]]]);
ias_oneup_verify('admin', 'linkedin');

// ---- 5. Live ----
t_eq([], ias_li_blockers(), 'LinkedIn posts on and Verify for LinkedIn passed: LinkedIn is sent');
$n = count($S['calls']);
ias_push_run();
$p = $lp($a);
$l = $li($p);
t_ok(!empty($l['id']) && isset($S['scheduled'][(int) $l['id']]), 'the Listing post is scheduled for LinkedIn on OneUp (#' . ($l['id'] ?? '') . ')');
$row = $S['scheduled'][(int) ($l['id'] ?? 0)] ?? [];
t_eq('Intel Auctions', (string) ($row['social_network_username'] ?? ''), '…on the Intel Auctions page');
t_eq([], $writes($n, $OTHER), '…and nothing ever to another company\'s page in the category');
t_ok(ias_post_sent($p)['mode'] === 'live' && ias_post_sent($p)['id'] !== (string) $l['id'], 'the Facebook post went too, with its own record');
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n), 'nothing changed: nothing sent again');
$view = $screen(IAS_PAGE_CALENDAR, ['post' => (string) $p['id']]);
t_ok(strpos($view, 'On OneUp for LinkedIn') !== false && strpos($view, 'Keep this one off LinkedIn') !== false, 'the post\'s page says it is on OneUp for LinkedIn, with the switch to keep it off');

// ---- 6. A changed post: LinkedIn's copy replaced by its id ----
$old = (string) $l['id'];
$act('edit', ['ias_post' => $p['id'], 'ias_text' => "12 Alpha Road in Narvon sells November 28 at 10:00 am.\n\nCall Jacob at 717-555-0101."]);
$n = count($S['calls']);
ias_push_run();
$p = $lp($a);
$dels = ias_t_oneup_calls($n, 'deletescheduledpost');
t_ok(in_array($old, array_map(function ($c) { return (string) $c['params']['post_id']; }, $dels), true) && (string) $li($p)['id'] !== $old, 'an edited post\'s LinkedIn copy is taken off by its id and scheduled again');

// ---- 7. A lost answer is never sent twice ----
$b = $mk('7 Bravo Lane', '2026-11-27 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run();
$S['lose_answer'] = 2; // Facebook's and LinkedIn's answers both lost
ias_push_run();
$bp = $lp($b);
t_ok(!empty($bp['flags']['li_sending']) && empty($bp['flags']['li']), 'LinkedIn\'s answer lost: the attempt is remembered, nothing counted as sent');
$count = function () { return count(array_filter($GLOBALS['ias_t_oneup']['scheduled'], function ($r) { return $r['social_network_username'] === 'Intel Auctions'; })); };
$before = $count();
ias_push_run();
$bp = $lp($b);
t_ok(!empty($bp['flags']['li']['id']) && empty($bp['flags']['li_sending']) && $count() === $before, 'the next run finds it on OneUp and adopts it, without sending it twice');

// ---- 8. A person deletes LinkedIn's copy in OneUp: it stays deleted ----
$p = $lp($a);
unset($S['scheduled'][(int) $li($p)['id']]);
ias_t_clock('2026-11-02 06:00');
ias_push_run();
ias_t_clock('2026-11-02 06:31');
$n = count($S['calls']);
ias_push_run();
$p = $lp($a);
t_eq('person', (string) ($p['flags']['li_off'] ?? ''), 'missing twice, 30 minutes apart: deleted by a person — LinkedIn stays off for this post');
t_eq([], array_values(array_filter($writes($n, $LI), function ($c) { return $c['path'] !== 'deletescheduledpost'; })), '…not sent again');
t_ok(ias_post_sent($p)['mode'] === 'live', '…and the Facebook post goes on');
$act('li_on', ['ias_post' => $p['id']]);
ias_push_run();
t_ok(empty(($p = $lp($a))['flags']['li_off']) && !empty($li($p)['id']), 'Send it to LinkedIn again: it goes once more');

// ---- 9. Keep this one off LinkedIn ----
$lid = (string) $li($p)['id'];
$act('li_off', ['ias_post' => $p['id']]);
$p = $lp($a);
t_ok(!isset($S['scheduled'][(int) $lid]) && empty($p['flags']['li']) && ($p['flags']['li_off'] ?? '') === 'person', 'Keep this one off LinkedIn takes its copy off at once, by its id');
t_ok(ias_post_sent($p)['mode'] === 'live' && isset($S['scheduled'][(int) ias_post_sent($p)['id']]), '…and leaves the Facebook post where it is');

// ---- 10. Refused three times: LinkedIn stops for that post, Facebook does not ----
$c = $mk('3 Charlie Court', '2026-11-26 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$cp = $lp($c);
t_ok(ias_post_sent($cp)['mode'] === 'live' && !empty($li($cp)['id']), 'a third listing goes to both');
$act('edit', ['ias_post' => $cp['id'], 'ias_text' => "3 Charlie Court sells November 26."]);
add_filter('pre_http_request', $refuse = function ($pre, $args, $url) use ($LI) {
  if (strpos((string) $url, 'oneupapp.io/api/schedule') === false) return $pre;
  parse_str((string) (wp_parse_url((string) $url)['query'] ?? ''), $q);
  $q = array_merge($q, is_array($args['body'] ?? null) ? $args['body'] : []);
  $sn = json_decode((string) ($q['social_network_id'] ?? ''), true);
  if ((is_array($sn) ? (string) ($sn[0] ?? '') : '') === $LI) $GLOBALS['ias_t_oneup']['fail_next'] = 1;
  return $pre;
}, 5, 3);
ias_push_run(); ias_push_run(); ias_push_run();
remove_filter('pre_http_request', $refuse, 5);
$cp = $lp($c);
t_eq('failed', (string) ($cp['flags']['li_off'] ?? ''), 'refused three times: LinkedIn is off for this post');
t_ok($cp['status'] !== 'failed' && ias_post_sent($cp)['mode'] === 'live', '…and the Facebook post is not failed with it');

// ---- 11. Published on LinkedIn ----
$bp = $lp($b);
ias_t_oneup_publish((int) $li($bp)['id']);
ias_push_run();
t_ok(!empty($li($lp($b))['published']), 'published on LinkedIn: noted on the post');

// ---- 11b. Send this version instead replaces a copy a person changed in OneUp (Codex, PR #16) ----
$b = $mk('8 Foxtrot Road', '2026-11-21 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$bp = $lp($b);
$lid = (int) $li($bp)['id'];
$S['scheduled'][$lid]['content'] = 'A person rewrote this in OneUp.';
ias_push_run();
$bp = $lp($b);
t_ok(($bp['flags']['li_locked'] ?? '') !== '' && $li($bp)['id'] == $lid, 'a LinkedIn copy a person edited in OneUp is kept as they made it');
$act('li_unlock', ['ias_post' => $bp['id']]);
$n = count($S['calls']);
ias_push_run();
$bp = $lp($b);
$dels = array_map(function ($c) { return (string) $c['params']['post_id']; }, ias_t_oneup_calls($n, 'deletescheduledpost'));
t_ok(in_array((string) $lid, $dels, true) && (int) $li($bp)['id'] !== $lid && isset($S['scheduled'][(int) $li($bp)['id']]), 'Send this version to LinkedIn instead: the person\'s copy is replaced by the plugin\'s, by its id');
t_ok(strpos((string) $S['scheduled'][(int) $li($bp)['id']]['content'], 'A person rewrote') === false, '…with the plugin\'s words');

// ---- 12. A person's own post with LinkedIn ticked goes to LinkedIn ----
$d = $mk('5 Delta Drive', '2026-11-25 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run();
$r = ias_manual_create(['listing' => $d, 'counts_as' => '', 'networks' => ['facebook', 'linkedin'], 'text' => "5 Delta Drive: the office is open Saturday to answer questions.", 'comment' => '', 'day' => '2026-11-12', 'slot' => '1', 'time' => '', 'pics' => [], 'note' => '', 'draft' => '', 'holds' => []], 'admin');
$mid = (int) ($r['id'] ?? 0);
t_ok($mid > 0, 'a person\'s own post, Facebook and LinkedIn ticked' . (empty($r['ok']) ? ' (' . strip_tags((string) $r['message']) . ')' : ''));
ias_push_run();
$m = ias_post_get($mid);
t_ok(!empty($li($m)['id']) && ias_post_sent($m)['mode'] === 'live', '…goes to both');

// ---- 12b. An answer lost, then the master switch off: the copy OneUp took is still taken back (Codex, PR #16) ----
$e = $mk('9 Echo Lane', '2026-11-23 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run();
$S['lose_answer'] = 2; // Facebook's and LinkedIn's answers both lost
ias_push_run();
$ep = $lp($e);
t_ok(!empty($ep['flags']['li_sending']) && empty($ep['flags']['li']), 'LinkedIn\'s answer lost: only the attempt is known');
$onli = function () { return array_keys(array_filter($GLOBALS['ias_t_oneup']['scheduled'], function ($r) { return $r['social_network_username'] === 'Intel Auctions' && strpos((string) $r['content'], '9 Echo Lane') !== false; })); };
t_ok(count($onli()) === 1, '…though OneUp did take it');
ias_settings_update(['master' => false]);
ias_push_run();
t_eq([], $onli(), 'master switch off before the next run: the copy OneUp took is found on its list and taken back — never left to publish');
ias_settings_update(['master' => true]);
ias_push_run(); // on again: everything goes back

// ---- 13. LinkedIn posts switched off: its copies come back off ----
$n = count($S['calls']);
$save(['networks' => ['linkedin' => ['account' => $LI, 'types' => ['listing', 'open_house', 'auction_week', 'results', 'roundup']]]]); // the switch unticked
ias_push_run();
$on = array_filter(ias_posts_query(['limit' => 100]), function ($x) { $l = (array) ($x['flags']['li'] ?? []); return !empty($l['id']) && empty($l['published']); });
t_eq([], array_values($on), 'off means off: every LinkedIn copy not yet out is taken back off OneUp');
t_ok(count(ias_t_oneup_calls($n, 'deletescheduledpost')) > 0 && ias_post_sent($lp($c))['mode'] === 'live', '…by its id, while the Facebook posts stay');
