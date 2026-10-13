<?php
/**
 * 0.9.0, DJ, Oct 13, 2026: "why if you can call and return this am i
 * entering the data rather than just having the system call, respond, and
 * give me a choice what to add. Also I want to be able to schedule GBM posts
 * from here."
 *
 * Look up accounts: OneUp's categories and accounts kept as a directory and
 * offered as choices — each network only its own kind of account, the
 * category's only one ready chosen — so no id is typed. Google Business
 * Profile: its own words (no hashtags, no phone numbers, 1,500 characters),
 * one picture and a Learn more button, a minute after Facebook; a dry run
 * until its own switch is on and its own Verify has passed (and the toolkit
 * can stand its own Google post down); then sent for real with its own record
 * — never Facebook's — and Facebook's rules: never twice, replaced and taken
 * back only by id, a person's edit, move or delete in OneUp kept, a refusal
 * stopping Google only, never the Facebook post.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_t_oneup();
$S =& $GLOBALS['ias_t_oneup'];
$S['gbp'] = false; // no stray Google row on the lists: the plugin's own Google posts are the ones that count here
$GBP = 'accounts/1/locations/2';
$GNAME = 'Intel Auctions (5940 Plank Road  Narvon  PA  US )';
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$mk = function (string $title, string $sale) use ($T, $lead) {
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing(['title' => $title, 'address' => $title . ', Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
    'stage' => 'upcoming', 'sale' => $sale, 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
};
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$writes = function (int $from, string $acct = '') {
  return array_values(array_filter(ias_t_oneup_calls($from), function ($c) use ($acct) {
    return in_array($c['path'], ['scheduletextpost', 'scheduleimagepost', 'schedulevideopost', 'deletepost'], true)
      && ($acct === '' || (string) ($c['params']['social_network_id'] ?? '') === $acct);
  }));
};
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $_POST = [];
};
$screen = function (string $page, array $get = []) {
  $_GET = ['page' => $page] + $get;
  ob_start();
  if ($page === IAS_PAGE_SETTINGS) ias_settings_render(); else ias_calendar_render();
  $h = (string) ob_get_clean();
  $_GET = [];
  return $h;
};
$save = function (array $fields) {
  [$new, $problems] = ias_settings_sanitize($fields + ias_settings(), ias_settings());
  ias_settings_update($new);
  return $problems;
};
$gb = function (array $p) { return (array) ($p['flags']['gbp'] ?? []); };

// ---- 1. Look up accounts ----
$dir = ias_oneup_directory_fetch('admin');
t_ok(isset($dir['error']) && strpos($dir['error'], 'master switch') !== false, 'with the master switch off, Look up accounts says why and asks nothing');
t_eq([], ias_t_oneup_calls(), '…not one call to OneUp');
$html = $screen(IAS_PAGE_SETTINGS);
t_ok(strpos($html, 'Not looked up yet') !== false && strpos($html, '— look up accounts first —') !== false, 'Settings offers lists to choose from, empty until OneUp is asked');
t_ok(strpos($html, '<input id="ias-cat"') === false && strpos($html, 'name="ias[oneup_account_id]" value=') === false, '…and no box to type an id into');

ias_settings_update(['master' => true]);
$dir = ias_oneup_directory_fetch('admin');
t_ok(!isset($dir['error']) && count($dir['categories']) === 1, 'master on: OneUp\'s categories are looked up');
t_eq([['id' => $GBP, 'name' => $GNAME, 'type' => 'GBP'], ['id' => 'fb_123', 'name' => 'Intel Auctions (Narvon)', 'type' => 'Facebook']], $dir['categories'][0]['accounts'], '…with each one\'s accounts, names and kinds');
t_ok(strpos((string) wp_json_encode(get_option('ias_oneup_directory')), 'test-oneup-key') === false, '…kept without the key');
t_eq([], $writes(0), '…and nothing written to OneUp');

$o = ias_oneup_pick_options(['category' => '42']);
t_ok(strpos($o['ias-cat'], '<option value="42" selected>Intel — 2 accounts: GBP, Facebook</option>') !== false, 'the category list names each category, its accounts and their kinds');
t_ok(strpos($o['ias-acct'], 'value="fb_123" selected>Intel Auctions (Narvon)') !== false && strpos($o['ias-acct'], $GBP) === false, 'the Facebook list offers the Facebook page only — the category\'s only one, ready chosen');
t_ok(strpos($o['ias-net-gbp'], 'value="' . $GBP . '" selected>') !== false && strpos($o['ias-net-gbp'], 'fb_123') === false, 'the Google list offers the Google location only, ready chosen');
t_ok(strpos($o['ias-net-instagram'], '<optgroup') === false && strpos($o['ias-net-instagram'], '— none —') !== false, 'a network with no account in OneUp offers none');
t_ok(strpos($o['ias-acct'], 'data-cat="42"') !== false, 'accounts are grouped by category, so the page shows the chosen category\'s only');
$o = ias_oneup_pick_options(['category' => '42', 'facebook' => '999']);
t_ok(strpos($o['ias-acct'], 'value="999" selected>Facebook account 999 (saved') !== false, 'a saved id OneUp does not list stays, marked');
$o = ias_oneup_pick_options(['category' => '']);
t_ok(strpos($o['ias-acct'], '<option value="" selected>') !== false || strpos($o['ias-acct'], ' selected>') !== false, 'no category chosen yet: the lists still render');

$problems = $save(['oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'networks' => ['gbp' => ['account' => $GBP]]]);
t_eq('Intel Auctions (Narvon)', (string) ias_setting('oneup_account_name'), 'choosing the page from the list keeps its name in OneUp (the read-back finds a person\'s posts by it)');
t_eq($GNAME, ias_channel_settings('gbp')['name'], '…and the Google location\'s');
t_eq([], array_values(array_filter($problems, function ($x) { return strpos($x, 'category') !== false; })), '…with no complaint about categories');
$problems = $save(['oneup_category_id' => '77', 'oneup_account_id' => 'fb_123']);
t_ok((bool) array_filter($problems, function ($x) { return strpos($x, 'not in the chosen OneUp category') !== false; }), 'a page from another category than the one chosen is pointed out');
$save(['oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'networks' => ['gbp' => ['account' => $GBP]]]);
$html = $screen(IAS_PAGE_SETTINGS);
t_ok(strpos($html, '<select id="ias-cat" name="ias[oneup_category_id]"') !== false && strpos($html, '<select id="ias-acct" name="ias[oneup_account_id]"') !== false && strpos($html, '<select id="ias-net-gbp" name="ias[networks][gbp][account]"') !== false, 'Settings: the category, the page and each network are chosen from lists');
t_ok(strpos($html, 'From OneUp ') !== false && strpos($html, '1 category, 2 accounts.') !== false, '…saying when OneUp was asked and what it has');

// ---- 2. Google's words and request ----
$a = $mk('12 Alpha Road', '2026-11-28 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run();
$p = $lp($a);
$p['content']['text'] = "12 Alpha Road in Narvon sells November 28.\n\nOpen house Sat 1–3. Questions? Call Jacob at 717-555-0101.\n\n#NarvonPA #FarmAuction";
$req = ias_render_channel($p, 'gbp');
$text = (string) $req['params']['content'];
t_ok(strpos($text, '717-555') === false && strpos($text, 'Questions?') === false, 'Google: the sentence with the phone number goes, and the question leading to it (Google refuses a post with one)');
t_ok(strpos($text, '#') === false, '…no hashtags');
t_ok(strpos($text, 'Open house Sat 1–3.') !== false && strpos($text, 'sells November 28') !== false, '…and the rest of the words kept');
t_eq('update', (string) $req['params']['google_post_type'], 'a Google "update" post');
t_eq('LEARN_MORE', (string) ($req['params']['cta_button'] ?? ''), '…with a Learn more button');
t_ok(strpos((string) ($req['params']['cta_button_url'] ?? ''), 'utm_source=gbp') !== false, '…to the listing, tagged for Google');
t_eq(1, count(explode('~~', (string) $req['params']['image_url'])), '…one picture');
t_eq('false', (string) $req['params']['image_to_gbp_photo'], '…not also added to the profile\'s Photos');
t_ok(!isset($req['params']['first_comment']), '…and no first comment (Google has none)');
t_eq(date('Y-m-d H:i', strtotime((string) $p['scheduled_at'] . ' +1 minute')), (string) $req['params']['scheduled_date_time'], '…a minute after the Facebook post');
t_eq($GBP, (string) $req['params']['social_network_id'], '…to the location chosen');
$long = $p;
$long['content']['text'] = str_repeat('A sentence about the farm that runs on. ', 60);
t_ok(mb_strlen((string) ias_render_channel($long, 'gbp')['params']['content']) <= 1500, 'Google\'s words fit its 1,500 characters');
$vid = $p;
$vid['content']['media'] = ['kind' => 'video', 'images' => [], 'video_url' => 'https://youtu.be/abc'];
$vreq = ias_render_channel($vid, 'gbp');
t_ok($vreq['endpoint'] === 'schedule_image' && (string) ($vreq['params']['image_url'] ?? '') !== '', 'a video post gives Google the listing\'s best photo instead (Google shows a picture, not a link)');

// ---- 3. Off, then a dry run ----
t_eq('off', ias_gbp_mode(), 'Google posts ship off');
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n, $GBP), '…and nothing goes to Google');
$save(['networks' => ['gbp' => ['on' => '1', 'account' => $GBP, 'types' => ['listing', 'open_house', 'auction_week', 'results']]]]);
t_eq('dry', ias_gbp_mode(), 'switched on, Google is a dry run until Send to Google is on and Verify for Google has passed');
$why = ias_gbp_blockers();
t_ok(in_array('Send to Google is off', $why, true) && in_array('dry run is on', $why, true), '…saying why (' . implode('; ', $why) . ')');
ias_push_run();
$dry = array_values(array_filter(ias_log_recent('dryrun', 500), function ($r) use ($a) { return ($r['meta']['channel'] ?? '') === 'gbp' && (int) ($r['meta']['post'] ?? 0) === (int) ias_post_by_slot('listing:' . $a)['id']; }));
t_eq(1, count($dry), 'the Listing post\'s Google request goes to the dry-run log');
t_ok(strpos((string) $dry[0]['meta']['summary'], 'Would schedule on Google Business Profile') === 0, '…as what would be scheduled');
t_eq([], $writes($n, $GBP), '…and nothing is sent');
$html = $screen(IAS_PAGE_CALENDAR);
t_ok(strpos($html, 'Google: dry run') !== false, 'the calendar\'s switches line says Google is a dry run');
$view = $screen(IAS_PAGE_CALENDAR, ['post' => (string) $lp($a)['id']]);
t_ok(strpos($view, 'data-net="gbp"') !== false && strpos($view, '>Learn more</a>') !== false, 'a post\'s page previews it for Google, with its Learn more button');
t_ok(strpos($view, 'Not sent — Google is a dry run') !== false, '…and says it is not sent, and why');

// ---- 4. Verify for Google ----
$n = count($S['calls']);
$v = ias_oneup_verify('admin', 'gbp');
$bad = array_filter($v['steps'], function ($s) { return $s['ok'] !== true; });
t_ok($v['ok'], 'Verify for Google passes every step' . ($bad ? ': ' . implode(' | ', array_map(function ($s) { return $s['label'] . ' — ' . $s['detail']; }, $bad)) : ''));
t_eq('The Google Business Profile location', (string) $v['steps'][0]['label'], '…checking the location is a Google one');
$drafts = ias_t_oneup_calls($n, 'scheduletextpost');
t_ok($drafts && ($drafts[0]['params']['isDraftPost'] ?? '') === 'true' && ($drafts[0]['params']['social_network_id'] ?? '') === $GBP && ($drafts[0]['params']['google_post_type'] ?? '') === 'update', '…with a draft for the location, as an update');
t_eq([], $S['drafts'], '…deleted again');
t_ok(ias_gbp_verified() && !ias_oneup_verified(), 'it passes for Google only — Facebook still needs its own Verify');
$save(['networks' => ['gbp' => ['on' => '1', 'account' => 'fb_123', 'types' => ['listing']]]]);
$v2 = ias_oneup_verify('admin', 'gbp');
t_ok(!$v2['ok'] && strpos((string) $v2['steps'][0]['detail'], 'not a Google Business Profile') !== false, 'Verify for Google refuses the Facebook page as a location');
$save(['networks' => ['gbp' => ['on' => '1', 'account' => $GBP, 'types' => ['listing', 'open_house', 'auction_week', 'results']]]]);
ias_oneup_verify('admin', 'gbp');
ias_oneup_verify('admin');

// ---- 5. Live ----
ias_settings_update(['dry_run' => false, 'types' => ['listing' => true]]);
$save(['networks' => ['gbp' => ['on' => '1', 'account' => $GBP, 'live' => '1', 'types' => ['listing', 'open_house', 'auction_week', 'results']]]]);
t_eq([], ias_gbp_blockers(), 'Send to Google on, Verify passed, dry run off: Google is sent for real');
t_ok(ia_social_gbp_managed(), '…and the toolkit is told its own Google post stands down');
t_eq([], ia_social_channels(), '…so its webhook has nothing left to post (Social posts Facebook and Google)');
// The toolkit closes the Google cards it queued before (its "Review and post: GBP post" and, with Facebook Social's too, the promo cards).
if (function_exists('ia_task_insert') && function_exists('ia_social_handoff_tasks')) {
  $tid = ia_task_insert(['slug' => 'draft.review.gbp.' . $a, 'title' => 'Review and post: GBP post for 12 Alpha Road', 'minutes' => 4, 'steps' => ['Copy it', 'Post it on the Google Business Profile']], 'rule', 'pipeline');
  $pid_promo = ia_task_insert(['slug' => 'promo.final.' . $a, 'title' => 'Final call', 'minutes' => 10, 'steps' => ['Suggested post: …', 'Post it on GBP and Facebook']], 'rule', 'auction_promo');
  $body = ia_task_body_decode(['slug' => 'draft.review.gbp.' . $a, 'body' => wp_json_encode(['steps' => ['Copy it']])]);
  t_ok(strpos((string) ($body['steps'][0] ?? ''), 'Nothing to post by hand') === 0, 'a Google review card still open reads "nothing to post by hand" (toolkit 4.68.0)');
  t_ok(ia_social_handoff_tasks() >= 2, '…and the daily coach run closes it, and the promo card');
  t_eq('skipped', (string) (ia_task_get((int) $tid)['status'] ?? ''), '…skipped, not deleted');
  t_eq('skipped', (string) (ia_task_get((int) $pid_promo)['status'] ?? ''), '…the promo card too');
  global $wpdb;
  $wpdb->query($wpdb->prepare('DELETE FROM ' . ia_table('tasks') . ' WHERE id IN (%d, %d)', $tid, $pid_promo));
}
$n = count($S['calls']);
ias_push_run();
$p = $lp($a);
$g = $gb($p);
t_ok(!empty($g['id']) && isset($S['scheduled'][(int) $g['id']]), 'the Listing post is scheduled for Google on OneUp (#' . ($g['id'] ?? '') . ')');
$row = $S['scheduled'][(int) ($g['id'] ?? 0)] ?? [];
t_eq($GNAME, (string) ($row['social_network_username'] ?? ''), '…on the Google location');
t_eq('update', (string) ($row['google_post_type'] ?? ''), '…as an update');
t_eq(date('Y-m-d H:i', strtotime((string) $p['scheduled_at'] . ' +1 minute')), substr((string) ($row['date_time'] ?? ''), 0, 16), '…a minute after the Facebook post');
$fb = ias_post_sent($p);
t_ok($fb['mode'] === 'live' && $fb['id'] !== '' && $fb['id'] !== (string) $g['id'], 'the Facebook post went too, as its own OneUp post with its own record');
t_eq(2, count($writes($n)), 'two posts sent: Facebook and Google, once each');
$dry_left = array_filter(ias_posts_query(['limit' => 200]), function ($x) { return !empty($x['flags']['dry_ch']['gbp']); });
t_eq([], array_values($dry_left), 'going live cleared Google\'s dry-run record');
$others = array_filter(ias_posts_query(['type' => ['open_house', 'auction_week'], 'limit' => 50]), function ($x) { return !empty($x['flags']['gbp']); });
t_eq([], array_values($others), 'post types whose own switch is off do not go to Google either');
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n), 'nothing changed: nothing sent again, to either');
$view = $screen(IAS_PAGE_CALENDAR, ['post' => (string) $p['id']]);
t_ok(strpos($view, 'On OneUp for Google') !== false && strpos($view, 'Keep this one off Google') !== false, 'the post\'s page says it is on OneUp for Google, with the switch to keep it off');

// ---- 6. A changed post: Google's copy replaced by its id ----
$old = (string) $g['id'];
$act('edit', ['ias_post' => $p['id'], 'ias_text' => "12 Alpha Road in Narvon sells November 28 at 10:00 am. It is an in-person auction.\n\nCall Jacob at 717-555-0101."]);
$n = count($S['calls']);
ias_push_run();
$p = $lp($a);
$g = $gb($p);
$dels = ias_t_oneup_calls($n, 'deletepost');
t_ok(in_array($old, array_map(function ($c) { return (string) $c['params']['post_id']; }, $dels), true), 'an edited post\'s Google copy is taken off by its id');
t_ok((string) $g['id'] !== $old && isset($S['scheduled'][(int) $g['id']]) && !isset($S['scheduled'][(int) $old]), '…and scheduled again');
t_ok(strpos((string) $S['scheduled'][(int) $g['id']]['content'], '717') === false && strpos((string) $S['scheduled'][(int) $g['id']]['content'], 'in-person auction') !== false, '…with the new words, its phone number left out');

// ---- 7. A lost answer is never sent twice ----
$b = $mk('7 Bravo Lane', '2026-11-27 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run();
$S['lose_answer'] = 2; // Facebook's and Google's answers both lost
ias_push_run();
$bp = $lp($b);
t_ok(!empty($bp['flags']['gbp_sending']) && empty($bp['flags']['gbp']), 'Google\'s answer lost: the attempt is remembered, nothing counted as sent');
$before = count(array_filter($S['scheduled'], function ($r) use ($GNAME) { return $r['social_network_username'] === $GNAME; }));
ias_push_run();
$bp = $lp($b);
$after = count(array_filter($S['scheduled'], function ($r) use ($GNAME) { return $r['social_network_username'] === $GNAME; }));
t_ok(!empty($bp['flags']['gbp']['id']) && empty($bp['flags']['gbp_sending']), 'the next run finds it on OneUp and adopts it');
t_eq($before, $after, '…without sending it twice');
t_ok(ias_post_sent($bp)['mode'] === 'live', '…and Facebook\'s own lost answer is adopted by Facebook\'s record, not Google\'s');

// ---- 8. A person moves Google's copy in OneUp: kept as they made it ----
$gid = (int) $gb($bp)['id'];
$S['scheduled'][$gid]['date_time'] = date('Y-m-d', strtotime((string) $bp['scheduled_at'] . ' +1 day')) . ' 12:41:00';
ias_push_run();
$bp = $lp($b);
t_eq('moved', (string) ($bp['flags']['gbp_locked'] ?? ''), 'a Google copy a person moved in OneUp is locked');
t_eq(0, (int) $bp['locked'], '…while the Facebook post is not');
$act('edit', ['ias_post' => $bp['id'], 'ias_text' => "7 Bravo Lane sells November 27. Bid in person."]);
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n, $GBP), 'and the plugin never replaces it, even when the post changes');
t_ok(count($writes($n, 'fb_123')) > 0, '…though the Facebook post is replaced');

// ---- 9. A person deletes Google's copy in OneUp: it stays deleted ----
$p = $lp($a);
$gid = (string) $gb($p)['id'];
unset($S['scheduled'][(int) $gid]);
ias_t_clock('2026-11-02 06:00'); // a copy sent in the last 10 minutes is not looked for yet
ias_push_run();
$p = $lp($a);
t_ok(!empty($p['flags']['gbp_missing']) && empty($p['flags']['gbp_off']), 'missing once: noted, nothing concluded yet');
ias_t_clock('2026-11-02 06:31');
$n = count($S['calls']);
ias_push_run();
$p = $lp($a);
t_eq('person', (string) ($p['flags']['gbp_off'] ?? ''), 'missing again 30 minutes later: deleted by a person — Google stays off for this post');
t_eq([], array_values(array_filter($writes($n, $GBP), function ($c) { return $c['path'] !== 'deletepost'; })), '…and it is not sent again');
t_ok(ias_post_sent($p)['mode'] === 'live' && empty($p['flags']['skipped_by']), '…while the Facebook post goes on as planned');
$act('gbp_on', ['ias_post' => $p['id']]);
$n = count($S['calls']);
ias_push_run();
$p = $lp($a);
t_ok(empty($p['flags']['gbp_off']) && !empty($gb($p)['id']) && count($writes($n, $GBP)) === 1, 'Send it to Google again: it goes once more');

// ---- 10. Keep this one off Google ----
$gid = (string) $gb($p)['id'];
$act('gbp_off', ['ias_post' => $p['id']]);
$p = $lp($a);
t_ok(!isset($S['scheduled'][(int) $gid]) && empty($p['flags']['gbp']) && ($p['flags']['gbp_off'] ?? '') === 'person', 'Keep this one off Google takes the Google copy off at once, by its id');
t_ok(ias_post_sent($p)['mode'] === 'live' && isset($S['scheduled'][(int) ias_post_sent($p)['id']]), '…and leaves the Facebook post where it is');
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n, $GBP), '…and it stays off');

// ---- 11. Refused three times: Google stops for that post, Facebook does not ----
$c = $mk('3 Charlie Court', '2026-11-26 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run();
ias_push_run(); // Facebook takes it first
$cp = $lp($c);
t_ok(ias_post_sent($cp)['mode'] === 'live' && !empty($gb($cp)['id']), 'a third listing goes to both');
$act('edit', ['ias_post' => $cp['id'], 'ias_text' => "3 Charlie Court sells November 26. Bid in person."]);
// Facebook's replacement goes first and succeeds; Google's is refused, every time (the fake's own refusal, armed for Google's calls only).
add_filter('pre_http_request', $refuse = function ($pre, $args, $url) use ($GBP) {
  if (strpos((string) $url, 'oneupapp.io/api/schedule') === false) return $pre;
  parse_str((string) (wp_parse_url((string) $url)['query'] ?? ''), $q);
  $sn = json_decode((string) ($q['social_network_id'] ?? ''), true); // the account goes as a one-item list
  if ((is_array($sn) ? (string) ($sn[0] ?? '') : '') === $GBP) $GLOBALS['ias_t_oneup']['fail_next'] = 1;
  return $pre;
}, 5, 3);
ias_push_run(); ias_push_run(); ias_push_run();
remove_filter('pre_http_request', $refuse, 5);
$cp = $lp($c);
t_eq('failed', (string) ($cp['flags']['gbp_off'] ?? ''), 'refused three times: Google is off for this post');
t_ok(!in_array($cp['status'], ['failed'], true) && ias_post_sent($cp)['mode'] === 'live', '…and the Facebook post is not failed with it');
$n = count($S['calls']);
ias_push_run();
t_eq([], $writes($n, $GBP), '…nor tried again on its own');

// ---- 12. Published, and failed on Google ----
$bp = $lp($b);
$gid = (int) $gb($bp)['id'];
ias_t_oneup_publish($gid);
ias_push_run();
$bp = $lp($b);
t_ok(!empty($gb($bp)['published']), 'published on Google: noted on the post');

// ---- 13. Skipped: the Google copy comes off ----
$d = $mk('5 Delta Drive', '2026-11-25 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$dp = $lp($d);
$gid = (string) $gb($dp)['id'];
t_ok($gid !== '', 'a fourth listing goes to Google');
$act('skip', ['ias_post' => $dp['id']]);
ias_push_run();
$dp = $lp($d);
t_ok(empty($dp['flags']['gbp']) && !isset($S['scheduled'][(int) $gid]), 'a skipped post\'s Google copy is taken off');

// ---- 14. Listing unticked for Google: copies come off, the toolkit takes Google back ----
$save(['networks' => ['gbp' => ['on' => '1', 'account' => $GBP, 'live' => '1', 'types' => ['results']]]]);
t_ok(!ia_social_gbp_managed(), 'with Listing posts no longer going to Google, the toolkit\'s own Google post comes back');
t_eq(['gbp'], ia_social_channels(), '…its webhook carries Google again');
ias_push_run();
$on_google = array_filter(ias_posts_query(['type' => 'listing', 'limit' => 50]), function ($x) { return !empty($x['flags']['gbp']) && empty($x['flags']['gbp_locked']) && empty($x['flags']['gbp']['published']); });
t_eq([], array_values($on_google), '…and the Listing posts\' Google copies are taken off (a person\'s move in OneUp kept)');

// ---- 15. An older toolkit: nothing goes to Google ----
t_ok(version_compare(IA_TOOLKIT_VERSION, IAS_GBP_TOOLKIT, '>='), 'this toolkit can stand its Google post down (' . IA_TOOLKIT_VERSION . ')');
$src = (string) file_get_contents(IAS_PATH . 'includes/gbp.php');
t_ok(strpos($src, "version_compare((string) IA_TOOLKIT_VERSION, IAS_GBP_TOOLKIT, '<')") !== false, 'an older toolkit is a reason Google is not sent');

// ---- 16. Master switch off: no call at all, Google included ----
ias_settings_update(['master' => false]);
$n = count($S['calls']);
ias_job_run();
t_eq([], ias_t_oneup_calls($n), 'master switch off: not one call to OneUp, Google included');
$dir = ias_oneup_directory_fetch('admin');
t_ok(isset($dir['error']) && count(ias_t_oneup_calls($n)) === 0, '…not even Look up accounts');

ias_t_oneup_off();
