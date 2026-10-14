<?php
/**
 * 0.18.0, DJ, Oct 14, 2026 ("Yes" to correction comments posted by the
 * plugin, on OneUp's Intermediate plan): a fact that changed after a post
 * went out is corrected in a comment on it, posted through OneUp
 * (reply-to-post) — by a person's click, or by the hourly run with
 * "Post corrections automatically" on (off at install). Never on a Sunday,
 * never with the master switch off or paused, never twice: a lost answer is
 * looked for among the page's comments, and only a person sends it again.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_t_oneup();
$S =& $GLOBALS['ias_t_oneup'];
$S['gbp'] = false;
$S['docs'] = true;
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$gal = [];
for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo('Alpha ' . $i);
$a = ias_t_listing(['title' => '12 Alpha Road', 'address' => '12 Alpha Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
$lp = function () use ($a) { return ias_post_by_slot('listing:' . $a); };
$weekday = function (int $plus_hours) { // the clock moved on, never onto a Sunday
  $t = strtotime(ias_now_sql()) + $plus_hours * HOUR_IN_SECONDS;
  if (date('N', $t) === '7') $t += DAY_IN_SECONDS;
  ias_t_clock(date('Y-m-d H:i', $t));
};
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $_POST = [];
  $k = 'ia_flash_' . get_current_user_id(); $h = (string) get_transient($k); delete_transient($k); return $h;
};
$card = function () { ob_start(); do_action('ias_calendar_before_grid'); return (string) ob_get_clean(); };
$bid = function (string $v) use ($a) { update_post_meta($a, 'Starting_Bid', $v); };

ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$oid = (int) ias_post_sent($lp())['id'];
ias_t_clock(date('Y-m-d H:i', strtotime((string) $lp()['scheduled_at']) + 2 * HOUR_IN_SECONDS));
$weekday(0);
ias_t_oneup_publish($oid);
ias_push_run();
$pid = (int) $lp()['id'];
$fb = '1336677789520495_' . $oid;
t_ok($lp()['status'] === 'published' && empty($lp()['flags']['unconfirmed']), 'setting out: the Listing post went out');
t_eq($fb, ias_correction_fb_id($lp()), 'Facebook\'s id for it, from the published list\'s "FB_…" (before any analytics read)');

// ---- A fact changes: the correction is drafted, and with the switch off, waits for a click ----
$bid('$275,000');
ias_job_run();
$c = (array) ($lp()['flags']['correction'] ?? []);
t_ok(strpos((string) ($c['text'] ?? ''), '$275,000') !== false && empty($c['done']), 'the starting bid changed: a correction is drafted (' . ($c['text'] ?? '') . ')');
t_eq([], $S['comments'], 'with Post corrections automatically off (as installed), nothing is posted');
$html = $card();
t_ok(strpos($html, '>Post it as a comment</button>') !== false && strpos($html, '>I posted it</button>') !== false && strpos($html, '>Not needed</button>') !== false, 'the calendar offers Post it as a comment, beside I posted it and Not needed');

// Facebook's id unknown: no button, and why.
$f = $lp()['flags'];
$keep = $f['oneup'];
unset($f['oneup']['published_id'], $f['oneup']['fb_id']);
ias_post_update($pid, ['flags' => $f]);
t_ok(strpos(html_entity_decode(ias_correction_button($lp()), ENT_QUOTES, 'UTF-8'), 'Facebook\'s id for the post is not known yet') !== false && strpos(ias_correction_button($lp()), 'Post it as a comment') === false, 'Facebook\'s id not known yet: no button, and it says why');
$f['oneup'] = $keep;
ias_post_update($pid, ['flags' => $f]);

// ---- Post it as a comment ----
$n = count($S['calls']);
$flash = $act('correction_post', ['ias_post' => $pid]);
$call = ias_t_oneup_calls($n, 'reply-to-post')[0] ?? ['method' => '', 'params' => []];
t_eq(1, count($S['comments']), 'pressed: one comment goes');
t_eq(['facebook', $fb, (string) $c['text'], '1336677789520495'], [$S['comments'][0]['platform'] ?? '', $S['comments'][0]['post_id'] ?? '', $S['comments'][0]['comment'] ?? '', $S['comments'][0]['social_account_id'] ?? ''], '…on the Facebook post, as the page, with the correction\'s words');
t_eq('POST', $call['method'], '…a POST to OneUp\'s comments, the words in a JSON body (as documented)');
$c = (array) $lp()['flags']['correction'];
t_ok(strpos((string) $c['done'], 'posted by admin') === 0 && ($c['comment_id'] ?? '') === 'c1' && empty($c['sending']), 'the correction is marked posted, by who, with the comment\'s id');
t_eq([], ias_corrections_waiting(), '…and no longer waits');
t_ok(strpos($flash, 'Posted the correction as a comment on Facebook') !== false, 'the page says so');
$act('correction_post', ['ias_post' => $pid]);
t_eq(1, count($S['comments']), 'pressed again: nothing more (it is posted)');

// ---- Never on a Sunday ----
$sunday = strtotime(ias_now_sql());
while (date('N', $sunday) !== '7') $sunday += DAY_IN_SECONDS;
ias_t_clock(date('Y-m-d', $sunday) . ' 10:00');
$bid('$270,000');
ias_job_run();
t_ok(strpos(ias_correction_button($lp()), 'never on a Sunday') !== false, 'Sunday: a new correction cannot be posted from here, and it says why');
$act('correction_post', ['ias_post' => $pid]);
t_eq(1, count($S['comments']), '…not even by pressing for it');

// ---- Post corrections automatically ----
ias_settings_update(['corrections_auto' => true]);
ias_job_run();
t_eq(1, count($S['comments']), 'switched on, the hourly run still posts nothing on a Sunday');
$weekday(24);
ias_job_run();
t_eq(2, count($S['comments']), 'Monday: the hourly run posts the waiting correction by itself');
t_ok(strpos((string) $lp()['flags']['correction']['done'], 'posted by the plugin') === 0 && strpos($S['comments'][1]['comment'], '$270,000') !== false, '…marked posted by the plugin');

// ---- Never twice: an answer that never came back ----
$S['comment_lose'] = 1;
$bid('$265,000');
ias_job_run();
$c = (array) $lp()['flags']['correction'];
t_ok(!empty($c['sending']) && empty($c['done']) && count($S['comments']) === 3, 'OneUp posted it but its answer was lost: the try is kept, not marked posted');
ias_job_run();
$c = (array) $lp()['flags']['correction'];
t_eq(3, count($S['comments']), 'the next run does not send it again');
t_ok(strpos((string) $c['done'], 'posted by the plugin') === 0 && ($c['comment_id'] ?? '') === 'c3', '…it finds it among the page\'s comments and marks it posted');

// A lost answer where the comment never arrived: found missing, it still waits for a person.
$S['comment_lose'] = 1;
$bid('$262,000');
ias_job_run();
array_pop($S['comments']); // it never reached Facebook
$S['feed_posts'] = [$fb];
ias_job_run();
ias_job_run();
$c = (array) $lp()['flags']['correction'];
t_ok(!empty($c['sending']) && empty($c['done']) && count($S['comments']) === 3, 'not found on the post: never sent again by the plugin');
$btn = ias_correction_button($lp());
t_ok(strpos($btn, '>Post it again</button>') !== false && strpos($btn, 'Check the post on Facebook first') !== false, '…a person is offered Post it again, told to check Facebook first');
$act('correction_retry', ['ias_post' => $pid]);
$c = (array) $lp()['flags']['correction'];
t_ok(count($S['comments']) === 4 && strpos((string) $c['done'], 'posted by admin') === 0, 'Post it again: posted, by the person who chose to');

// ---- A plan without comments: said, and not tried again for a week ----
$S['comment_code'] = 403;
$bid('$261,000');
ias_job_run();
$c = (array) $lp()['flags']['correction'];
t_ok(empty($c['done']) && strpos((string) ($c['error'] ?? ''), 'Intermediate plan') !== false, 'OneUp answers 403: the correction says the plan has no comments');
$n = count($S['calls']);
$weekday(24);
ias_job_run();
t_eq(0, count(ias_t_oneup_calls($n, 'reply-to-post')), '…and the hourly run does not try again for a week');
$S['comment_code'] = 200;

// ---- Off means off ----
ias_settings_update(['paused' => true]);
delete_option('ias_comments_no_plan');
$n = count($S['calls']);
ias_job_run();
t_ok(!ias_t_oneup_calls($n, 'reply-to-post') && strpos(ias_correction_button($lp()), 'posting is paused') !== false, 'paused: nothing is posted, and the button says why');
ias_settings_update(['paused' => false, 'master' => false]);
$n = count($S['calls']);
ias_job_run();
t_eq([], ias_t_oneup_calls($n, 'reply-to-post'), 'the master switch off: nothing is posted');
ias_settings_update(['master' => true]);
ias_job_run();
t_ok(strpos((string) $lp()['flags']['correction']['done'], 'posted by the plugin') === 0, 'back on: the waiting correction goes');

// ---- Where it says so ----
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $pid]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = [];
t_ok(strpos($h, 'Correction posted by the plugin as a comment') !== false, 'the post\'s page says the correction was posted, and by whom');
ob_start(); ias_settings_render(); $set = (string) ob_get_clean();
t_ok(strpos($set, 'name="ias[corrections_auto]"') !== false && strpos($set, 'Correction comments') !== false, 'Settings has the switch');
t_ok(!ias_settings_defaults()['corrections_auto'], '…off at install');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'types' => ['listing' => false], 'corrections_auto' => false]);
delete_option('ias_oneup_verify');
delete_option('ias_comments_no_plan');
