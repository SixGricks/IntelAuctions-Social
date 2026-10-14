<?php
/**
 * 0.19.0, DJ, Oct 14, 2026 ("Yes"): Try again reuses OneUp's failed post.
 * A post OneUp could not publish stays on its failed list; Try again now
 * reschedules that copy (POST reschedulefailedpost: its id, the new time)
 * instead of sending a second one — when the post is as it was then. A post
 * changed since, a copy OneUp will not reschedule, or one sent before 0.19.0
 * gets a fresh copy, as before. A lost answer is looked for, never sent
 * twice; a failed row OneUp has not cleared yet never fails it again.
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
$fail = function (string $why = 'The image size exceeds the platform limit.') use (&$S, $lp) {
  $oid = (int) ias_post_sent($lp())['id'];
  $row = $S['scheduled'][$oid];
  unset($S['scheduled'][$oid]);
  $row['fail_reason'] = $why;
  $S['failed'][$oid] = $row;
  ias_push_run();
  return $oid;
};
$retry = function () use ($lp) { // Try again on the post's page, as the calendar's handler runs it
  $id = (int) $lp()['id'];
  $_POST = ['ias_cal_action' => 'retry', 'ias_post' => (string) $id];
  do_action('ias_calendar_action', 'retry');
  $_POST = [];
  ias_push_now_flash($id);
  delete_transient('ia_flash_' . get_current_user_id());
};
$writes = function (int $from) { return count(ias_t_oneup_calls($from, 'scheduleimagepost')) + count(ias_t_oneup_calls($from, 'scheduletextpost')) + count(ias_t_oneup_calls($from, 'schedulevideopost')); };

ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
t_ok(!empty($lp()['flags']['oneup']['body']), 'what was sent now keeps its fingerprint without the time (`body`)');

// ---- 1. Failed, Try again: OneUp's own copy goes again ----
$old = $fail();
t_eq('failed', $lp()['status'], 'OneUp could not publish it: Failed');
$n = count($S['calls']);
$retry();
$p = $lp();
$rs = ias_t_oneup_calls($n, 'reschedulefailedpost');
t_eq(1, count($rs), 'Try again: OneUp is asked to reschedule its failed copy');
t_eq([(string) $old, substr((string) $p['scheduled_at'], 0, 16)], [(string) ($rs[0]['params']['post_id'] ?? ''), (string) ($rs[0]['params']['scheduled_date_time'] ?? '')], '…by its id, for the post\'s time');
t_eq('POST', $rs[0]['method'] ?? '', '…a POST, as documented');
t_eq(0, $writes($n), '…and no second copy is sent');
t_ok((string) ias_post_sent($p)['id'] === (string) $old && isset($S['scheduled'][$old]) && !isset($S['failed'][$old]), 'the post keeps its OneUp id; the copy is back on the scheduled list');
t_ok(ias_post_shown_status($p) === 'pushed' && empty($p['flags']['reuse_failed']) && empty($p['flags']['sending']), '…shown as Pushed, nothing left pending');
$trail = implode(' | ', array_column((array) ($p['flags']['trail'] ?? []), 'what'));
t_ok(strpos($trail, 'rescheduled for') !== false && strpos($trail, 'no second copy') !== false, 'its history says so');
ias_t_clock('2026-11-02 06:15');
ias_push_run();
ias_t_clock('2026-11-02 06:50');
ias_push_run();
t_ok(ias_post_shown_status($lp()) === 'pushed' && (string) ias_post_sent($lp())['id'] === (string) $old, 'the read-backs after it change nothing');

// ---- 2. Changed since it failed: a fresh copy ----
$old = $fail();
$c = (array) $lp()['content'];
$c['text'] .= "\n\nOpen house added Saturday.";
ias_post_update((int) $lp()['id'], ['content' => $c]);
$n = count($S['calls']);
$retry();
t_ok(count(ias_t_oneup_calls($n, 'reschedulefailedpost')) === 0 && $writes($n) === 1, 'changed since it failed: no reschedule — a fresh copy goes');
t_ok((string) ias_post_sent($lp())['id'] !== (string) $old && isset($S['failed'][$old]), '…with its own id; the old copy stays on OneUp\'s failed list');

// ---- 3. OneUp will not reschedule it: a fresh copy, at once ----
$old = $fail();
$_POST = ['ias_cal_action' => 'retry', 'ias_post' => (string) $lp()['id']];
do_action('ias_calendar_action', 'retry');
$_POST = [];
unset($S['failed'][$old]); // gone from OneUp's failed list before the push
$n = count($S['calls']);
ias_push_now_flash((int) $lp()['id']);
delete_transient('ia_flash_' . get_current_user_id());
t_ok(count(ias_t_oneup_calls($n, 'reschedulefailedpost')) === 1 && $writes($n) === 1 && ias_post_sent($lp())['id'] !== '', 'OneUp refuses the reschedule (not on its failed list): a fresh copy goes in the same click');

// ---- 4. The answer is lost: looked for, never sent twice ----
$old = $fail();
$S['resched_lose'] = 1;
$n = count($S['calls']);
$retry();
t_ok(!empty($lp()['flags']['sending']) && $writes($n) === 0, 'rescheduled, but the answer was lost: the try is kept, nothing else sent');
ias_push_run();
t_ok((string) ias_post_sent($lp())['id'] === (string) $old && empty($lp()['flags']['sending']) && empty($lp()['flags']['reuse_failed']), 'the next run finds it on the scheduled list and keeps it');
t_eq([0, 1], [$writes($n), count(ias_t_oneup_calls($n, 'reschedulefailedpost'))], '…no second copy and no second reschedule');

// ---- 5. OneUp's failed row lingers after the reschedule ----
$old = $fail();
$S['resched_keep_failed'] = true;
$retry();
t_ok(isset($S['failed'][$old], $S['scheduled'][$old]), 'OneUp keeps the old failed row beside the rescheduled copy for a while');
ias_push_run();
t_eq('pushed', ias_post_shown_status($lp()), '…the read-back does not fail the post again: the scheduled list wins');
$S['resched_keep_failed'] = false;
unset($S['failed'][$old]);

// ---- 6. A post sent before 0.19.0 ----
$old = $fail();
$f = $lp()['flags'];
unset($f['oneup']['body']);
ias_post_update((int) $lp()['id'], ['flags' => $f]);
$n = count($S['calls']);
$retry();
t_ok(count(ias_t_oneup_calls($n, 'reschedulefailedpost')) === 0 && $writes($n) === 1, 'sent before 0.19.0 (no fingerprint without the time): a fresh copy, as before');

$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $lp()['id']];
$old = $fail();
ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = [];
t_ok(strpos(html_entity_decode($h, ENT_QUOTES, 'UTF-8'), "OneUp's own copy goes again") !== false, 'the Failed card says what Try again does');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'types' => ['listing' => false]]);
delete_option('ias_oneup_verify');
