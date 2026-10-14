<?php
/**
 * 0.12.0, DJ, Oct 14, 2026: "I want to turn dry run off and remove it.
 * Either things are active or inactive." — and "I also want a "Publish now"
 * button if a post is past it's scheduled date."
 *
 * The update retires the dry run once: a site that was in it has every post
 * type and Google switched off (the update never starts posting by itself),
 * the dry run's records go, and a note under the switches says what was
 * switched off until Settings is saved; a site that was live keeps its
 * switches. Off means off: a switch turned off (a post type, Pause, the
 * master switch, Google) takes what is on OneUp back off — at once from the
 * page, soonest first, the rest on the next run — and on again sends it.
 * Publish now sends a post whose time passed without it going out, a few
 * minutes ahead: never while posting is off, never on a Sunday, never a
 * post that needs a decision; on a Results post it is the yes; a post whose
 * time came in the old dry run ("Not sent") can go too.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$mk = function (string $title, string $sale) use ($T, $lead) {
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing(['title' => $title, 'address' => $title . ', Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
    'stage' => 'upcoming', 'sale' => $sale, 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
};
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $id = (int) ($post['ias_post'] ?? 0);
  if ($id && in_array($action, ias_push_now_actions(), true)) ias_push_now_flash($id); // what the calendar's handler does next
  $_POST = [];
};
$flash = function () { $k = 'ia_flash_' . get_current_user_id(); $h = (string) get_transient($k); delete_transient($k); return $h; };
$view = function (int $id) { $_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $id]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = []; return $h; };
$calendar = function () { $_GET = ['page' => IAS_PAGE_CALENDAR]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = []; return $h; };
ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123']);

// ---- 1. The update retires the dry run: a site that was in it is switched off ----
$a = $mk('12 Alpha Road', '2026-11-28 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run();
$ap = $lp($a);
$f = $ap['flags']; $f['dry_hash'] = 'old'; $f['dry_ch'] = ['instagram' => 'old'];
ias_post_update((int) $ap['id'], ['flags' => $f]);
$saved = get_option('ias_settings', []);
$saved = array_merge($saved, ['master' => true, 'dry_run' => true, 'types' => ['listing' => true, 'results' => true],
  'networks' => ['gbp' => ['on' => true, 'live' => true, 'account' => 'accounts/1/locations/2', 'types' => ['listing']]]]);
update_option('ias_settings', $saved, true);
update_option('ias_push_mode', 'dry', false);
delete_option('ias_dry_run_retired');
ias_dry_run_retire();
$raw = get_option('ias_settings', []);
t_ok(!array_key_exists('dry_run', $raw) && !array_key_exists('live', $raw['networks']['gbp']), 'the stored dry run and Google\'s separate Send switch are gone');
t_ok(!ias_type_on('listing') && !ias_type_on('results') && !ias_channel_settings('gbp')['on'], 'it was in the dry run: every post type and Google are switched off — the update never starts posting by itself');
t_ok((bool) ias_setting('master', false), '…the master switch kept (on alone, it posts nothing)');
$f = ias_post_get((int) $ap['id'])['flags'];
t_ok(!isset($f['dry_hash']) && !isset($f['dry_ch']), 'the dry run\'s records on posts are cleared');
t_eq(false, get_option('ias_push_mode', false), '…and its mode option');
t_eq(['listing', 'results', 'gbp'], ias_dry_run_switched_off(), 'what was switched off is kept, for the note');
$strip = ias_switches_strip(true);
t_ok(strpos($strip, 'The dry run is gone') !== false && strpos($strip, 'Listing, Results, Google') !== false, 'the note under the switches says so, by name');
t_ok(strpos($strip, 'Dry run') === false || strpos($strip, '<b>Dry run</b>') === false, '…and there is no Dry run switch any more');
ias_dry_run_retire();
t_eq(['listing', 'results', 'gbp'], ias_dry_run_switched_off(), 'it runs once');
update_option('ias_dry_run_retired', ['off' => []] + (array) get_option('ias_dry_run_retired', []), false); // what saving Settings does
t_ok(strpos(ias_switches_strip(true), 'The dry run is gone') === false, 'once Settings is saved the note goes');

// A site that was live keeps its switches; Google stays on only where it was really sent.
$saved = array_merge(get_option('ias_settings', []), ['dry_run' => false, 'types' => ['listing' => true], 'networks' => ['gbp' => ['on' => true, 'live' => false, 'account' => 'x', 'types' => ['listing']]]]);
update_option('ias_settings', $saved, true);
delete_option('ias_dry_run_retired');
ias_dry_run_retire();
t_ok(ias_type_on('listing') && !ias_channel_settings('gbp')['on'], 'a site that was live keeps its post types; Google planned but never sent is off');
$saved = array_merge(get_option('ias_settings', []), ['dry_run' => false, 'networks' => ['gbp' => ['on' => true, 'live' => true, 'account' => 'x', 'types' => ['listing']]]]);
update_option('ias_settings', $saved, true);
delete_option('ias_dry_run_retired');
ias_dry_run_retire();
t_ok(ias_channel_settings('gbp')['on'], '…and Google really being sent stays on');
ias_settings_update(['types' => ['listing' => false], 'networks' => ['gbp' => ['on' => false, 'account' => '', 'types' => ['listing']]]]);

// ---- 2. Off means off ----
ias_t_oneup();
$S =& $GLOBALS['ias_t_oneup'];
$S['gbp'] = false;
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
$b = $mk('7 Bravo Lane', '2026-11-27 10:00');
$c = $mk('3 Charlie Court', '2026-11-26 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$ids = array_map(function ($l) use ($lp) { return (int) $lp($l)['id']; }, [$a, $b, $c]);
$on_oneup = function () use ($ids) { return count(array_filter($ids, function ($id) { return ias_post_sent(ias_post_get($id))['mode'] === 'live'; })); };
t_eq(3, $on_oneup(), 'switched on: three Listing posts on OneUp');

ias_settings_update(['types' => ['listing' => false]]);
$r = ias_push_switched();
t_eq(0, $on_oneup(), 'Listing posts switched off: all three come back off OneUp at once (Settings runs this on save)');
t_eq(3, $r['deleted'], '…counted');
t_eq('3 taken back off OneUp.', ias_push_switched_words($r), '…and the page says so');
t_ok(!empty(ias_post_get($ids[0])['flags']['was_on_oneup']), '…each remembered as having been there');
ias_settings_update(['types' => ['listing' => true]]);
$r = ias_push_switched();
t_eq(3, $on_oneup(), 'switched on again: they go back at once');
t_eq('3 scheduled on OneUp.', ias_push_switched_words($r), '…and the page says so');

ias_settings_update(['paused' => true]);
ias_push_switched();
t_eq(0, $on_oneup(), 'Pause: everything on OneUp comes back off');
t_ok(ia_social_facebook_managed(), '…and Facebook stays with this plugin while paused');
ias_settings_update(['paused' => false]);
ias_push_switched();
t_eq(3, $on_oneup(), 'Resume: it goes again');

ias_settings_update(['master' => false]);
ias_push_switched();
t_eq(0, $on_oneup(), 'the master switch off: everything of ours comes back off OneUp');
$n = count($S['calls']);
ias_push_run();
t_eq([], ias_t_oneup_calls($n), '…and then not one call');
ias_settings_update(['master' => true]);
ias_push_switched();
t_eq(3, $on_oneup(), 'on again: it all goes back');

// Soonest first, and a page never waits long: out of time, the rest follow on the hourly run.
$GLOBALS['ias_push_deadline'] = microtime(true) - 1;
t_ok(ias_push_out_of_time(), 'past its deadline, a run started from a page is out of time');
unset($GLOBALS['ias_push_deadline']);
t_ok(!ias_push_out_of_time(), '…the hourly run has none');
$posts = ias_posts_query(['status' => ['planned', 'approved', 'pushed'], 'type' => 'listing', 'limit' => 10]);
t_ok(count($posts) >= 2 && (string) $posts[0]['scheduled_at'] <= (string) $posts[1]['scheduled_at'], 'the run goes soonest first');

// No key: nothing can be taken back, and the calendar says so plainly.
ias_settings_update(['types' => ['listing' => false]]);
remove_all_filters('ias_oneup_key');
ias_push_run();
t_eq(3, $on_oneup(), 'with no OneUp key nothing can be taken back');
$cal = $calendar();
t_ok(strpos($cal, 'coming back off OneUp') !== false && strpos($cal, 'without the OneUp key') !== false, '…and the calendar says they are coming back off, and what is missing');
add_filter('ias_oneup_key', function () { return 'test-oneup-key'; });
ias_push_switched();
t_eq(0, $on_oneup(), 'the key back: they come off');
ias_settings_update(['types' => ['listing' => true]]);
ias_push_switched();

// ---- 3. Publish now ----
// A post whose time passed without it going out (say posting was off at its time).
$d = $mk('9 Delta Drive', '2026-11-25 10:00');
ias_sync(); ias_drafts_run(30);
$dp = $lp($d);
$did = (int) $dp['id'];
ias_post_update($did, ['scheduled_at' => '2026-11-02 05:10:00', 'status' => 'planned']);
$dp = ias_post_get($did);
t_eq('', ias_post_sent($dp)['mode'], 'a Listing post past its time, not on OneUp');
$state = ias_publish_now_state($dp);
t_ok($state && $state['why_not'] === '', 'Publish now belongs on it, and it can go');
$html = $view($did);
t_ok(strpos($html, 'has passed and it did not go out') !== false && strpos($html, '>Publish now</button>') !== false, 'its page says its time passed, with Publish now');
$cal = $calendar();
t_ok(strpos($cal, 'passed their time without going out') !== false || strpos($cal, 'passed its time without going out') !== false, 'the calendar lists posts past their time, to open and publish');
$n = count($S['calls']);
$act('publish_now', ['ias_post' => $did]);
$dp = ias_post_get($did);
$want = ias_now()->modify('+' . IAS_PUBLISH_NOW_MINUTES . ' minutes')->format('Y-m-d H:i');
t_eq($want . ':00', (string) $dp['scheduled_at'], 'Publish now: its time is now, a few minutes ahead');
t_ok(($dp['flags']['pinned'] ?? '') === $want && ($dp['flags']['pinned_by'] ?? '') !== '', '…held there for the plan to work around');
$sent = ias_post_sent($dp);
t_ok($sent['mode'] === 'live' && isset($S['scheduled'][(int) $sent['id']]), '…and it is on OneUp at once');
t_eq($want, substr((string) ($S['scheduled'][(int) $sent['id']]['date_time'] ?? ''), 0, 16), '…for that minute');
$fl = $flash();
t_ok(strpos($fl, 'Publishing.') !== false && strpos($fl, 'scheduled on OneUp') !== false, '…and the page says so');
t_eq(null, ias_publish_now_state($dp), 'once on OneUp, Publish now is gone');
ias_plan_run();
t_eq($want . ':00', (string) ias_post_get($did)['scheduled_at'], 'the morning plan leaves it where the person put it');

// Not while its post type is off; not a post that needs a decision; never on a Sunday.
$e = $mk('5 Echo Lane', '2026-11-24 10:00');
ias_sync(); ias_drafts_run(30);
$eid = (int) $lp($e)['id'];
ias_post_update($eid, ['scheduled_at' => '2026-11-02 04:40:00', 'status' => 'planned']);
ias_settings_update(['types' => ['listing' => false]]);
$state = ias_publish_now_state(ias_post_get($eid));
t_ok($state && strpos($state['why_not'], 'Listing posts are off') !== false, 'Listing posts off: Publish now says it cannot go, and why (' . ($state['why_not'] ?? 'none') . ')');
t_ok(strpos($view($eid), '>Publish now</button>') === false && strpos($view($eid), 'Publish now: not while') !== false, '…its page shows the reason instead of the button');
$n = count($S['calls']);
$act('publish_now', ['ias_post' => $eid]);
t_ok(strpos($flash(), 'Not published') !== false && ias_post_sent(ias_post_get($eid))['mode'] === '' && (string) ias_post_get($eid)['scheduled_at'] === '2026-11-02 04:40:00', '…and pressing it anyway sends nothing');
ias_settings_update(['types' => ['listing' => true]]);
$f = ias_post_get($eid)['flags']; $f['holds'] = ['A test reason'];
ias_post_update($eid, ['status' => 'held', 'flags' => $f]);
t_ok(strpos($view($eid), '>Publish now</button>') === false, 'a post that needs a decision is not offered Publish now');
$f['holds'] = [];
ias_post_update($eid, ['status' => 'planned', 'flags' => $f]);
ias_t_clock('2026-11-08 09:00'); // Sunday
$state = ias_publish_now_state(ias_post_get($eid));
t_ok($state && strpos($state['why_not'], 'Sunday') !== false, 'never on a Sunday');
ias_t_clock('2026-11-02 05:30');

// A post whose time came in the old dry run: Not sent — and it can go now.
$f = ias_post_get($eid)['flags']; $f['dry_run'] = true;
ias_post_update($eid, ['status' => 'published', 'flags' => $f]);
$ep = ias_post_get($eid);
t_eq('notsent', ias_post_shown_status($ep), 'a post whose time came in the old dry run shows as Not sent, not Published');
t_ok(strpos($view($eid), 'Not sent') !== false && strpos($view($eid), '>Publish now</button>') !== false, '…its page says so, with Publish now');
$act('publish_now', ['ias_post' => $eid]);
$ep = ias_post_get($eid);
t_ok($ep['status'] === 'planned' && empty($ep['flags']['dry_run']) && ias_post_sent($ep)['mode'] === 'live', '…pressed: back on its way and on OneUp');

// A Results post waiting for a yes, past its time: Publish now is the yes.
ias_settings_update(['types' => ['listing' => true, 'results' => true], 'approvers' => 'dj@intelauctions.com']);
add_filter('pre_wp_mail', '__return_true');
$sold = ias_t_listing(['title' => '22 Mill Road', 'address' => '22 Mill Road, Paradise, PA 17562, USA', 'town' => 'Paradise', 'county' => 'Lancaster',
  'sale' => '2026-10-31 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'price' => '425000', 'featured' => ias_t_photo('Mill front')]);
ia_stage_set($sold, 'past');
ias_job_run();
$rp = ias_post_by_slot('results:2026-10-31:paradise');
t_eq('waiting', $rp['status'], 'a Results post waits for approval');
ias_post_update((int) $rp['id'], ['scheduled_at' => '2026-11-02 05:00:00']);
$rp = ias_post_get((int) $rp['id']);
$state = ias_publish_now_state($rp);
t_ok($state && $state['why_not'] === '', 'past its time while it waited, it can be published now');
$act('publish_now', ['ias_post' => (int) $rp['id']]);
$rp = ias_post_get((int) $rp['id']);
t_ok($rp['status'] === 'approved' && $rp['approved_by'] !== '' && ($rp['approvals']['decision']['via'] ?? '') === 'Publish now', 'Publish now on a Results post is the yes, recorded as such');
t_eq('live', ias_post_sent($rp)['mode'], '…and it goes to OneUp at once');
remove_all_filters('pre_wp_mail');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'paused' => false, 'types' => ['listing' => false, 'results' => false]]);
delete_option('ias_oneup_verify');
