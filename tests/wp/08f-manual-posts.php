<?php
/**
 * 0.25.0, DJ, Oct 15, 2026: "I want to be able to create a post from in the social calendar manually and choose
 * what platforms it goes to. Then I want to make sure the social algorithm considers that post when planning
 * other things out." All five networks can be ticked (Facebook and Google go; Instagram, X and LinkedIn wait for
 * their own switch); a person's words or Claude's draft for them to check, under the same checks; it counts in
 * the plan, and standing for one of a listing's own posts turns that post off.
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
wp_set_current_user(1);
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map(function ($v) { return is_array($v) ? array_map('strval', $v) : (string) $v; }, $post);
  do_action('ias_calendar_action', $action);
  $id = (int) ($_POST['ias_post'] ?? 0);
  $back = (string) ($_POST['ias_back'] ?? '');
  if ($id && in_array($action, ias_push_now_actions(), true)) ias_push_now_flash($id);
  $_POST = [];
  return $back;
};
$flash = function () { $k = 'ia_flash_' . get_current_user_id(); $h = (string) get_transient($k); delete_transient($k); return $h; };
$page = function (array $get) { $_GET = ['page' => IAS_PAGE_CALENDAR] + $get; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = []; return $h; };
$gal = []; for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo('Manual ' . $i);
$a = ias_t_listing(['title' => '9 Own Words Road', 'address' => '9 Own Words Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-21 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$250,000', 'gallery' => $gal,
  'oh' => [['2026-11-14 13:00', 2]]]);
ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]); // Open House posts off: a person's own post has no type switch
ias_job_run();
$oh = null;
foreach (ias_posts_query(['type' => 'open_house', 'limit' => 5]) as $row) $oh = $row;
t_ok($oh && in_array($oh['status'], ['planned', 'held'], true), 'the listing has a planned Open House post');

// ---- 1. The screen ----
$html = $page(['new' => 1]);
t_ok(strpos($html, 'Create post') !== false && strpos($html, 'Draft it for me') !== false, 'New post: the form, with Draft it for me');
foreach (['Facebook', 'Google', 'Instagram', 'X', 'LinkedIn'] as $n) t_ok(strpos($html, '> ' . $n) !== false, 'it can go to ' . $n);
t_ok(strpos($html, 'kept for when it goes live') !== false, 'Instagram, X and LinkedIn say they wait until they go live');
t_ok(strpos($page([]), '+ New post') !== false, 'the calendar has a New post button');

// ---- 2. Draft it for me ----
$form = ['ias_listing' => $a, 'ias_counts_as' => 'open_house', 'ias_networks' => ['facebook', 'instagram'], 'ias_day' => '2026-11-10', 'ias_slot' => '1', 'ias_note' => 'Invite people to the open house.', 'ias_text' => ''];
$GLOBALS['ias_t_claude_manual'] = [];
$act('manual_draft', $form);
$f = $flash();
$kept = ias_manual_form_get();
t_ok(strpos($f, 'Drafted') !== false && strpos((string) $kept['text'], '9 Own Words Road sells') !== false, 'Claude drafts it from the listing, into the form, for the person to check');
$asked = (string) end($GLOBALS['ias_t_claude_manual']);
t_ok(strpos($asked, 'goes out on Tuesday, November 10, 2026') !== false && strpos($asked, 'Invite people to the open house.') !== false && strpos($asked, 'OPEN HOUSE POST') !== false, 'Claude is told the post\'s day, the person\'s note and which of the listing\'s posts it stands for');
$after_first = count($GLOBALS['ias_t_claude_manual']); // the fake's filter is added once per test file, so one call can show more than once
$act('manual_draft', $form);
$flash();
t_eq($after_first, count($GLOBALS['ias_t_claude_manual']), 'pressing it again with the same listing, note and day pays nothing: the same draft');
t_ok(strpos($page(['new' => 1]), esc_textarea((string) $kept['text'])) !== false, 'the form comes back with the draft in it');

// ---- 3. Create: it counts, it stands for the Open House post ----
$back = $act('manual_create', array_merge($form, ['ias_text' => (string) $kept['text'], 'ias_pics' => ['a' . $gal[2], 'a' . $gal[0]]]));
$f = $flash();
preg_match('/post=(\d+)/', $back, $m);
$mid = (int) ($m[1] ?? 0);
$mp = ias_post_get($mid);
t_ok($mp && $mp['type'] === 'manual' && $mp['status'] === 'planned', 'created: a post of its own (' . strip_tags($f) . ')');
t_ok(substr((string) $mp['scheduled_at'], 0, 10) === '2026-11-10' && (string) ($mp['flags']['pinned'] ?? '') === substr((string) $mp['scheduled_at'], 0, 16), '…on Nov 10 in the time of day chosen, pinned there like a Move');
t_eq(['facebook', 'instagram'], ias_post_networks($mp), '…for the networks ticked');
t_eq(['a' . $gal[2], 'a' . $gal[0]], ias_media_keys($mp), '…with the pictures ticked, in that order');
t_ok(strpos((string) $mp['content']['first_comment'], 'http') !== false, '…and the listing\'s link in the first comment, as on its other posts');
t_eq('claude', (string) $mp['content']['generated_by'], 'it remembers the words are Claude\'s draft, taken as it was');
$oh = ias_post_get((int) $oh['id']);
t_ok($oh['status'] === 'skipped' && ($oh['flags']['skipped_by'] ?? '') === 'person' && (int) ($oh['flags']['replaced_by'] ?? 0) === $mid, 'the listing\'s own Open House post is turned off — it stands for it');
t_ok(strpos($f, 'turned off') !== false && strpos($f, 'Instagram: kept for when that network goes live') !== false, 'the notice says so, and that Instagram waits');
ias_sync();
t_eq('skipped', ias_post_get((int) $oh['id'])['status'], 'the next sync leaves the turned-off post off (a person turned it off)');
t_eq('planned', ias_post_get($mid)['status'], '…and never cancels the person\'s own post as "no longer called for"');

// The plan counts it and works around it.
$rows = ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'pushed', 'published', 'human', 'dropped'], 'limit' => 2000]);
$eng = array_values(array_filter(ias_engine_posts($rows, ias_now(), 60 * 48), function ($e) use ($mp) { return $e['key'] === $mp['slot_key']; }));
t_ok($eng && $eng[0]['fixed_at'] === substr((string) $mp['scheduled_at'], 0, 16), 'the scheduler takes it as fixed where the person put it — counted in that day\'s posts and spacing');
$was = (string) $mp['scheduled_at'];
ias_plan_run();
t_eq($was, (string) ias_post_get($mid)['scheduled_at'], 'the plan never moves it');
$listing_post = ias_post_by_slot('listing:' . $a);
t_ok(abs(strtotime((string) $listing_post['scheduled_at']) - strtotime($was)) >= 3 * 3600 || substr((string) $listing_post['scheduled_at'], 0, 10) !== '2026-11-10', 'the plan\'s own posts keep their spacing from it');

// It goes to Facebook — with no Open House type switch: ticking Facebook is its switch.
ias_push_run();
$mp = ias_post_get($mid);
t_ok(ias_post_sent($mp)['mode'] === 'live' && isset($S['scheduled'][(int) ias_post_sent($mp)['id']]), 'it is scheduled on OneUp for Facebook');
t_ok(strpos((string) $S['scheduled'][(int) ias_post_sent($mp)['id']]['content'], '9 Own Words Road sells') !== false, '…with the words as the person created it');
t_ok(ias_push_ready($mp) === '' && ias_gbp_ready($mp) === 'Google is not ticked for it', 'Google is not ticked: it does not go to Google');
t_eq([], ias_push_switched_off($mp), 'no post-type switch stands in its way');
ias_settings_update(['master' => false]);
t_ok(in_array('the master switch is off', ias_push_switched_off($mp), true), '…but the master switch does');
ias_settings_update(['master' => true]);
t_eq('A person\'s own post is never rewritten — change its words, or talk it over.', ias_generate_post($mid)['error'] ?? '', 'the system never rewrites it');

$view = $page(['post' => $mid]);
t_ok(strpos($view, 'Your post ·') !== false && strpos($view, 'Your post post') === false, 'its page opens, named once');
t_ok(strpos($view, 'Save networks') !== false && strpos($view, 'Delete this post') !== false && strpos($view, 'Stands for the listing') !== false, '…with where it goes, what it stands for, and Delete');
t_ok(strpos($view, '>Rewrite<') === false, '…and no Rewrite: the system never rewrites a person\'s post');

// Unticking Facebook takes it back.
$act('manual_networks', ['ias_post' => $mid, 'ias_networks' => ['instagram']]);
$flash();
$mp = ias_post_get($mid);
t_ok(ias_post_sent($mp)['mode'] === '' && ias_push_ready($mp) === 'Facebook is not ticked for it', 'Facebook unticked: its copy is taken back off OneUp at once');
$rows = ias_posts_query(['status' => ['planned'], 'limit' => 2000]);
t_eq([], array_values(array_filter(ias_engine_posts($rows, ias_now(), 60 * 48), function ($e) use ($mp) { return $e['key'] === $mp['slot_key']; })), 'not on the page now, it no longer takes a place in the page\'s plan');

// Delete: the Open House post comes back.
$act('manual_delete', ['ias_post' => $mid]);
$f = $flash();
t_eq('cancelled', ias_post_get($mid)['status'], 'Delete cancels it');
t_ok(in_array(ias_post_get((int) $oh['id'])['status'], ['planned', 'held'], true) && strpos($f, 'back on the plan') !== false, '…and the Open House post it stood for is back on the plan');

// ---- 4. About nothing in particular: the checks still run ----
$base = ['ias_listing' => 0, 'ias_counts_as' => '', 'ias_networks' => ['facebook'], 'ias_day' => '2026-11-11', 'ias_slot' => 'exact', 'ias_time' => '10:20'];
$act('manual_create', array_merge($base, ['ias_text' => 'Our office is closed Thursday. Tickets are $5 at the door.']));
$f = $flash();
$p2 = null; foreach (ias_posts_query(['type' => 'manual', 'status' => ['planned', 'held'], 'limit' => 10]) as $row) $p2 = $row;
t_ok($p2 && $p2['status'] === 'planned' && (string) $p2['scheduled_at'] === '2026-11-11 10:20:00', 'a post about no listing, at an exact time: its own figures are the person\'s, nothing to hold');
t_eq('Our office is closed Thursday. Tickets are $5 at the door.', ias_post_subject($p2) === 'Our office is closed Thursday. Tickets are $5 at the door.' ? $p2['content']['text'] : ias_post_subject($p2), 'the calendar names it by its first line');
$act('manual_create', array_merge($base, ['ias_time' => '15:40', 'ias_text' => 'A great home, perfect for families. Come by Saturday.']));
$f = $flash();
$p3 = null; foreach (ias_posts_query(['type' => 'manual', 'status' => 'held', 'limit' => 10]) as $row) $p3 = $row;
t_ok($p3 && strpos((string) $p3['note'], 'Fair housing') !== false && strpos($f, 'needs a decision') !== false, 'fair housing still holds a person\'s own post');
$act('manual_create', array_merge($base, ['ias_day' => '2026-11-08', 'ias_text' => 'Sunday words.']));
t_ok(strpos($flash(), 'Never on Sunday') !== false, 'never on Sunday');
$act('manual_create', array_merge($base, ['ias_networks' => [], 'ias_text' => 'Nowhere.']));
t_ok(strpos($flash(), 'Tick at least one network') !== false, 'it has to go somewhere');
$act('manual_create', array_merge($base, ['ias_counts_as' => 'results', 'ias_text' => 'Sold.']));
t_ok(strpos($flash(), 'Choose the listing it stands for') !== false, 'standing for a listing\'s post needs the listing');
$act('manual_draft', array_merge($base, ['ias_text' => '']));
t_ok(strpos($flash(), 'Choose a listing or write a note') !== false, 'Draft it for me needs a listing or a note to write from');

// ---- 5. Only for X: no Facebook, no place in the page's plan; its time passing sends nothing ----
$act('manual_create', array_merge($base, ['ias_networks' => ['x'], 'ias_time' => '08:05', 'ias_text' => 'For X only.']));
$flash();
$px = null; foreach (ias_posts_query(['type' => 'manual', 'limit' => 20]) as $row) if (ias_post_networks($row) === ['x']) $px = $row;
t_ok($px && $px['status'] === 'planned', 'an X-only post is kept');
$n = count($S['calls']);
ias_t_clock('2026-11-11 09:00');
ias_push_run();
$px = ias_post_get((int) $px['id']);
t_ok($px['status'] === 'cancelled' && strpos((string) $px['note'], 'nothing went out') !== false, 'its time passed with no live network ticked: it says nothing went out');
t_eq([], array_values(array_filter(ias_t_oneup_calls($n), function ($c) { return strpos((string) ($c['params']['content'] ?? ''), 'For X only') !== false; })), '…and nothing of it was sent anywhere');
