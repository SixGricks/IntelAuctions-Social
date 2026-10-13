<?php
/**
 * 0.10.0, DJ, Oct 13, 2026: "can we make sure that Jacob and the intel team
 * never needs to touch OneUp? I would love a clear 2 way communication
 * between oneup and the plugin. Instant update both directions."
 *
 * A person's change reaches OneUp in the same request (ias_push_now), after
 * reading OneUp's scheduled list — never concluding a delete from that
 * light read. While the calendar is open, OneUp is asked every minute (one
 * read for everyone looking) and the screen says what changed. Everything
 * once done in OneUp is done here: Move (the time holds, even inside the
 * veto window — the person said yes), Try again on a post OneUp could not
 * publish, Edit it here again on a post someone changed in OneUp, and the
 * plugin's version sent to Google again. Nothing sends anyone into OneUp.
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
$mk = function (string $title, string $sale) use ($T, $lead) {
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing(['title' => $title, 'address' => $title . ', Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
    'stage' => 'upcoming', 'sale' => $sale, 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
};
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$writes = function (int $from) {
  return array_values(array_filter(ias_t_oneup_calls($from), function ($c) { return in_array($c['path'], ['scheduletextpost', 'scheduleimagepost', 'schedulevideopost', 'deletescheduledpost'], true); }));
};
$reads = function (int $from, string $path) { return count(ias_t_oneup_calls($from, $path)); };
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $id = (int) ($post['ias_post'] ?? 0);
  if ($id && in_array($action, ias_push_now_actions(), true)) ias_push_now_flash($id); // what the calendar's handler does next
  $_POST = [];
};
$flash = function () { $k = 'ia_flash_' . get_current_user_id(); $h = (string) get_transient($k); delete_transient($k); return $h; };
$view = function (int $id) { $_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $id]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = []; return $h; };

// Live, Facebook (Listing posts).
ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['dry_run' => false, 'types' => ['listing' => true]]);
$a = $mk('12 Alpha Road', '2026-11-28 10:00');
$b = $mk('7 Bravo Lane', '2026-11-27 10:00');
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$p = $lp($a);
t_ok(ias_post_sent($p)['mode'] === 'live', 'setting out: the Listing post is on OneUp');

// ---- 1. A person's change goes to OneUp at once ----
$old = ias_post_sent($p)['id'];
$n = count($S['calls']);
$act('edit', ['ias_post' => $p['id'], 'ias_text' => "12 Alpha Road in Narvon sells November 28. It is an in-person auction.\n\nBring the family to look around."]);
$p = $lp($a);
$new = ias_post_sent($p)['id'];
t_ok($new !== $old && isset($S['scheduled'][(int) $new]) && !isset($S['scheduled'][(int) $old]), 'Save changes: replaced on OneUp in the same request, not on the next hourly run');
t_ok(strpos((string) $S['scheduled'][(int) $new]['content'], 'Bring the family') !== false, '…with the new words');
t_eq(1, $reads($n, 'getscheduledposts'), '…after reading OneUp\'s scheduled list once (a person\'s change there is never overwritten)');
t_eq(0, $reads($n, 'getpublishedposts') + $reads($n, 'getfailedposts'), '…and nothing more: the light read');
$f = $flash();
t_ok(strpos($f, '<strong>OneUp</strong> — Facebook: replaced on OneUp for ') !== false, 'the notice says what happened on OneUp');

// The light read never concludes a delete: a post missing from the list stays as it is.
$bp = $lp($b);
$bid = (int) ias_post_sent($bp)['id'];
$keep = $S['scheduled'][$bid];
unset($S['scheduled'][$bid]);
ias_t_clock('2026-11-02 06:00');
ias_push_now([(int) $p['id']]);
ias_t_clock('2026-11-02 06:45');
ias_push_now([(int) $p['id']]);
$bp = $lp($b);
t_ok(empty($bp['flags']['missing_since']) && $bp['status'] !== 'skipped', 'a light read (a person\'s change) never takes a missing post for deleted — only the full read-back can');
$S['scheduled'][$bid] = $keep;

// ---- 2. Move ----
$sun = '2026-11-08';
$act('move', ['ias_post' => $p['id'], 'ias_day' => $sun, 'ias_time' => '12:10']);
t_ok(strpos($flash(), 'Never on Sunday') !== false, 'Move refuses a Sunday');
$act('move', ['ias_post' => $p['id'], 'ias_day' => '2026-11-02', 'ias_time' => '06:50']);
t_ok(strpos($flash(), 'at least 15 minutes from now') !== false, '…and a time about to pass');
$n = count($S['calls']);
$act('move', ['ias_post' => $p['id'], 'ias_day' => '2026-11-03', 'ias_time' => '12:10']); // tomorrow: inside the 48-hour window
$p = $lp($a);
t_eq('2026-11-03 12:10:00', (string) $p['scheduled_at'], 'Move puts it at the person\'s day and time');
t_eq('2026-11-03 12:10', (string) ($p['flags']['pinned'] ?? ''), '…and it holds');
$sent = ias_post_sent($p);
t_ok(isset($S['scheduled'][(int) $sent['id']]) && substr((string) $S['scheduled'][(int) $sent['id']]['date_time'], 0, 16) === '2026-11-03 12:10', '…on OneUp at once, at that time — inside the veto window: the person said yes');
t_ok(strpos($flash(), 'Moved to') !== false, '…saying so');
ias_plan_run();
t_eq('2026-11-03 12:10:00', (string) $lp($a)['scheduled_at'], 'the morning plan keeps it where the person put it');
$html = $view((int) $p['id']);
t_ok(strpos($html, 'moved there by') !== false && strpos($html, 'Let the plan choose the time') !== false, 'the post says who moved it, and how to hand the time back');
$act('unpin', ['ias_post' => $p['id']]);
$p = $lp($a);
t_ok(empty($p['flags']['pinned']) && (string) $p['scheduled_at'] === '2026-11-03 12:10:00', 'Let the plan choose, inside the 48-hour window: it stays put (on OneUp and inside the window, nothing is moved)');
t_ok(strpos($flash(), 'inside the 48-hour window') !== false, '…and the notice says why');
$act('move', ['ias_post' => $p['id'], 'ias_day' => '2026-11-06', 'ias_time' => '12:10']);
$flash();
$act('unpin', ['ias_post' => $p['id']]);
$p = $lp($a);
$eng = array_values(array_filter(ias_engine_posts([$p], ias_now(), 60 * 48), function ($x) use ($p) { return $x['id'] === (int) $p['id']; }));
t_ok(empty($p['flags']['pinned']) && $eng && $eng[0]['fixed_at'] === null, 'Let the plan choose, outside it: the time no longer holds — the plan may move it (it kept ' . $p['scheduled_at'] . ', which fits)');
t_ok(strpos($flash(), 'The plan chooses its time again') !== false, '…saying so');
t_ok(substr((string) ($S['scheduled'][(int) ias_post_sent($p)['id']]['date_time'] ?? ''), 0, 16) === substr((string) $p['scheduled_at'], 0, 16), '…and OneUp follows at once');

// ---- 3. Try again ----
$pid = (int) $p['id'];
$oid = (int) ias_post_sent($p)['id'];
$row = $S['scheduled'][$oid];
unset($S['scheduled'][$oid]);
$row['failure_reason'] = 'Facebook rejected the image';
$S['failed'][$oid] = $row;
ias_readback_run();
t_eq('failed', $lp($a)['status'], 'a post OneUp could not publish: Failed');
$html = $view($pid);
t_ok(strpos($html, 'Try again') !== false && strpos($html, 'Retry it in OneUp') === false, 'its page offers Try again — never "retry it in OneUp"');
$n = count($S['calls']);
$act('retry', ['ias_post' => $pid]);
$p = $lp($a);
t_ok(in_array($p['status'], ['planned', 'pushed'], true), 'Try again: back on the plan');
$sent = ias_post_sent($p);
t_ok($sent['mode'] === 'live' && (int) $sent['id'] !== $oid && isset($S['scheduled'][(int) $sent['id']]), '…and a fresh copy is on OneUp at once');
t_eq([(string) $oid], (array) ($p['flags']['failed_copies'] ?? []), '…the failed copy remembered, never taken for this one');
ias_readback_run();
t_ok($lp($a)['status'] !== 'failed', 'the old copy on OneUp\'s failed list does not fail it again');

// ---- 4. Edit it here again ----
$sent = ias_post_sent($lp($a));
$S['scheduled'][(int) $sent['id']]['content'] = "12 Alpha Road sells November 28 — DJ's own words, typed in OneUp.";
ias_readback_run();
$p = $lp($a);
t_ok((int) $p['locked'] === 1, 'a post someone edited in OneUp is locked');
$html = $view((int) $p['id']);
t_ok(strpos($html, 'Edit it here again') !== false, '…and its page offers Edit it here again');
$n = count($S['calls']);
$act('unlock', ['ias_post' => $p['id']]);
$p = $lp($a);
t_eq(0, (int) $p['locked'], 'Edit it here again: unlocked');
t_eq("12 Alpha Road sells November 28 — DJ's own words, typed in OneUp.", (string) $p['content']['text'], '…starting from the words as they are in OneUp');
t_eq([], $writes($n), '…with nothing sent: OneUp already has it as it is');
t_ok(ias_post_editable($p), '…and editable here');
$act('edit', ['ias_post' => $p['id'], 'ias_text' => "12 Alpha Road sells November 28. It is an in-person auction."]);
t_ok(strpos((string) ($S['scheduled'][(int) ias_post_sent($lp($a))['id']]['content'] ?? ''), 'in-person auction') !== false, '…and the next save replaces it on OneUp at once');

// ---- 5. From OneUp, while the calendar is open ----
delete_option('ias_pulse');
$n = count($S['calls']);
$r = ias_oneup_pulse();
t_eq('ok', $r['state'], 'the open calendar asks OneUp');
t_ok($reads($n, 'getscheduledposts') >= 1 && $reads($n, 'getpublishedposts') >= 1, '…a full read-back: scheduled, published and failed');
$n = count($S['calls']);
$r2 = ias_oneup_pulse();
t_ok(!empty($r2['cached']) && count(ias_t_oneup_calls($n)) === 0, 'asked again within the minute (another screen, another person): one read for everyone');
$seq = (int) $r2['seq'];
$bp = $lp($b);
ias_t_clock('2026-11-27 11:00'); // b's time has come
ias_t_oneup_publish((int) ias_post_sent($bp)['id']);
$pulse = get_option('ias_pulse'); $pulse['ts'] = time() - 120; update_option('ias_pulse', $pulse, false); // a minute on
$r3 = ias_oneup_pulse();
t_ok((int) $r3['seq'] > $seq && in_array('1 published', (array) $r3['changes'], true), 'OneUp published a post: the next ask says so (' . implode(', ', (array) $r3['changes']) . ')');
t_eq('published', (string) $lp($b)['status'], '…and the post shows as published');
$pulse = get_option('ias_pulse'); $pulse['ts'] = time() - 5; update_option('ias_pulse', $pulse, false);
t_ok(!empty(ias_oneup_pulse(true)['cached']), 'Check now: not more than every 10 seconds');
$pulse['ts'] = time() - 15; update_option('ias_pulse', $pulse, false);
t_ok(empty(ias_oneup_pulse(true)['cached']), '…after that, it asks');
ias_t_clock('2026-11-02 07:00');
$cal = (function () { $_GET = ['page' => IAS_PAGE_CALENDAR]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = []; return $h; })();
t_ok(strpos($cal, 'id="ias-pulse"') !== false && strpos($cal, 'Check now') !== false, 'the calendar shows when OneUp was last heard from, with Check now');
// OneUp down while the calendar is open: said on the screen, logged once an hour — not once a minute.
$S['broken'] = ['getscheduledposts'];
$before = count(array_filter(ias_log_recent('readback', 200), function ($e) { return strpos((string) ($e['meta']['summary'] ?? ''), 'Read-back from OneUp failed') === 0; }));
foreach ([1, 2, 3] as $i) { $pulse = get_option('ias_pulse'); $pulse['ts'] = time() - 120; update_option('ias_pulse', $pulse, false); $r = ias_oneup_pulse(); }
$after = count(array_filter(ias_log_recent('readback', 200), function ($e) { return strpos((string) ($e['meta']['summary'] ?? ''), 'Read-back from OneUp failed') === 0; }));
t_eq('error', $r['state'], 'OneUp not answering: the calendar says so');
t_eq(1, $after - $before, '…and the log notes it once, not every minute');
$S['broken'] = [];
ias_settings_update(['master' => false]);
$n = count($S['calls']);
t_eq('off', ias_oneup_pulse(true)['state'], 'master switch off: the calendar does not ask OneUp');
t_eq(0, count(ias_t_oneup_calls($n)), '…not one call');
ias_settings_update(['master' => true]);

// ---- 6. Nothing sends anyone into OneUp ----
$_GET = ['page' => IAS_PAGE_SETTINGS]; ob_start(); ias_settings_render(); $set = (string) ob_get_clean(); $_GET = [];
t_ok(strpos($set, 'ias-calurl') === false && strpos($set, 'in OneUp first') === false, 'Settings: no OneUp calendar link, no "change it in OneUp first"');
$src = '';
foreach (glob(IAS_PATH . 'includes/*.php') as $file) $src .= (string) file_get_contents($file);
foreach (['Retry it in OneUp', 'retry there', 'fix it there', 'veto it in OneUp', 'Open the OneUp calendar', 'change it in OneUp', 'Change it in OneUp'] as $phrase) {
  t_ok(strpos($src, $phrase) === false, 'no screen says "' . $phrase . '"');
}

ias_t_oneup_off();
