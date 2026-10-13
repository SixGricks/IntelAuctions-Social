<?php
/**
 * Phase 4 acceptance: "A test Results post round-trips: email, approve, then
 * queued. An expired link fails safely." Plus: the first yes wins, an altered
 * link is refused, reminders every morning with fresh links, none on Sunday,
 * an edit voids the old links, Save and approve, and the Monday email.
 * (Facebook Events are out — DJ, Oct 12 2026 — so nothing offers them.)
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30, 'approvers' => 'dj@intelauctions.com, jacob@intelauctions.com', 'oneup_calendar_url' => 'https://www.oneupapp.io/calendar']);

$GLOBALS['ias_t_mail'] = [];
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['ias_t_mail'][] = $atts; return true; }, 10, 2);
$mail = function () { $m = $GLOBALS['ias_t_mail']; $GLOBALS['ias_t_mail'] = []; return $m; };
$token_for = function (array $mails, string $to) {
  foreach ($mails as $m) {
    if ($m['to'] !== $to && (array) $m['to'] !== [$to]) continue;
    if (preg_match('/action=ias_approve(?:&#038;|&amp;|&)p=(\d+)(?:&#038;|&amp;|&)t=([a-f0-9]+)/', (string) $m['message'], $x)) return [(int) $x[1], $x[2]];
  }
  return [0, ''];
};

$sold = ias_t_listing(['title' => '22 Mill Road', 'address' => '22 Mill Road, Paradise, PA 17562, USA', 'town' => 'Paradise', 'county' => 'Lancaster',
  'sale' => '2026-10-31 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$200,000', 'price' => '425000',
  'featured' => ias_t_photo('Mill Road front', [120, 90, 60])]);
ia_stage_set($sold, 'past');

// ---- Email ----
ias_job_run();
$res = ias_post_by_slot('results:2026-10-31:paradise');
t_eq('waiting', $res['status'], 'a clean Results draft waits for approval');
$m = $mail();
t_eq(2, count($m), 'one email to each approver');
t_ok(strpos((string) $m[0]['subject'], 'Approve: Results') === 0, 'subject says what to approve');
t_ok(strpos((string) $m[0]['message'], '$425,000') !== false && strpos((string) $m[0]['message'], '<img') !== false, 'with the full text and the picture');
t_ok(strpos((string) $m[0]['message'], 'Edit in WordPress') !== false, 'and an Edit button');
[$pid, $jacob_token] = $token_for($m, 'jacob@intelauctions.com');
[, $dj_token] = $token_for($m, 'dj@intelauctions.com');
t_ok($pid === (int) $res['id'] && strlen($jacob_token) === 48 && $jacob_token !== $dj_token, 'each approver has their own link');
t_ok(strpos(wp_json_encode($res['approvals']), $jacob_token) === false && strpos(wp_json_encode(ias_post_get($pid)['approvals']), $jacob_token) === false, 'tokens are stored only as hashes');
t_eq(0, count(array_filter(ias_log_recent('dryrun', 100), function ($l) use ($pid) { return (int) ($l['meta']['post'] ?? 0) === $pid; })), 'nothing in Lane A is pushed before a yes');

// ---- Approve: opening the link only shows it; the button approves ----
$page = ias_approval_response($pid, $jacob_token, false);
t_eq('ask', $page['reason'], 'opening the link shows the post and asks');
t_ok(strpos($page['body'], 'Approve this post') !== false && strpos($page['body'], '$425,000') !== false, '…with the post and an Approve button');
t_eq('waiting', ias_post_get($pid)['status'], 'just opening the link approves nothing (email scanners follow links)');
$page = ias_approval_response($pid, $jacob_token, true);
t_eq('approved', $page['reason'], 'pressing Approve approves');
$res = ias_post_get($pid);
t_eq('approved', $res['status'], 'the post is approved');
t_eq('jacob@intelauctions.com', $res['approved_by'], '…by the approver whose link it was');
t_ok(!empty($res['scheduled_at']) && $res['scheduled_at'] >= ias_now()->modify('+60 minutes')->format('Y-m-d H:i:s'), 'and queued at once, at least an hour ahead (' . $res['scheduled_at'] . ')');
t_ok($res['scheduled_at'] < ias_now()->modify('+48 hours')->format('Y-m-d H:i:s'), '…not held to the 48-hour veto window — the approval was the yes');
t_eq(1, count(array_filter(ias_log_recent('dryrun', 100), function ($l) use ($pid) { return (int) ($l['meta']['post'] ?? 0) === $pid; })), '…and goes to the push queue (the dry run logs it)');
$page = ias_approval_response($pid, $dj_token, true);
t_eq('decided', $page['reason'], 'the second approver\'s link: the first yes already won');
t_ok(strpos($page['body'], 'jacob@intelauctions.com') !== false, '…and says who approved it');
$page = ias_approval_response($pid, $jacob_token, true);
t_eq('decided', $page['reason'], 'a used link does nothing');
t_eq('approved', ias_post_get($pid)['status'], 'still approved, once');

// ---- An expired link fails safely; an altered one too ----
$sold2 = ias_t_listing(['title' => '9 Weaver Road', 'address' => '9 Weaver Road, Ephrata, PA 17522, USA', 'town' => 'Ephrata', 'county' => 'Lancaster',
  'sale' => '2026-10-31 13:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'price' => '310000', 'featured' => ias_t_photo('Weaver front')]);
ia_stage_set($sold2, 'past');
ias_job_run();
$m = $mail();
[$pid2, $tok2] = $token_for($m, 'dj@intelauctions.com');
t_ok($pid2 > 0 && $pid2 !== $pid, 'a second Results post (another town) has its own email');
$a = ias_post_get($pid2)['approvals'];
foreach ($a['tokens'] as $i => $t) $a['tokens'][$i]['exp'] = time() - 60;
ias_post_update($pid2, ['approvals' => $a]);
$page = ias_approval_response($pid2, $tok2, true);
t_eq('expired', $page['reason'], 'an expired link is refused');
t_ok(strpos($page['body'], 'still waiting') !== false, '…saying the post is still waiting');
t_eq('waiting', ias_post_get($pid2)['status'], '…and nothing changed');
$page = ias_approval_response($pid2, substr($tok2, 0, -1) . (substr($tok2, -1) === 'a' ? 'b' : 'a'), true);
t_eq(['bad', 403], [$page['reason'], $page['status']], 'an altered link is refused');
t_eq('waiting', ias_post_get($pid2)['status'], '…and nothing changed');
$page = ias_approval_response(999999, $tok2, true);
t_eq('no_post', $page['reason'], 'a link for a post that is gone is refused');

// ---- Reminders: every morning, fresh links; never Sunday ----
ias_t_clock('2026-11-03 05:30'); // Tuesday
ias_job_run();
$m = $mail();
t_eq(2, count($m), 'next morning: a reminder to each approver');
t_ok(strpos((string) $m[0]['subject'], 'Reminder') === 0, '…called a reminder');
[, $tok3] = $token_for($m, 'dj@intelauctions.com');
t_ok($tok3 !== '' && $tok3 !== $tok2, '…with a fresh link');
ias_job_run();
t_eq(0, count($mail()), 'one reminder a morning');
ias_t_clock('2026-11-08 05:30'); // Sunday
ias_job_run();
t_eq(0, count(array_filter($mail(), function ($x) { return strpos((string) $x['subject'], 'Reminder') === 0 || strpos((string) $x['subject'], 'Approve') === 0; })), 'no approval email or reminder on Sunday');
ias_t_clock('2026-11-09 05:30'); // Monday
ias_job_run();
t_ok(count(array_filter($mail(), function ($x) { return strpos((string) $x['subject'], 'Reminder') === 0; })) >= 2, 'Monday morning brings the reminder');

// ---- An edit voids the old links; Save and approve ----
$w = ias_post_get($pid2);
[, $live_tok] = [0, ''];
$a = ias_post_get($pid2)['approvals'];
$_POST = ['ias_cal_action' => 'edit', 'ias_post' => $pid2, 'ias_text' => "9 Weaver Road in Ephrata sold for \$310,000 on October 31.\n\nThank you to everyone who came out."];
do_action('ias_calendar_action', 'edit');
$m = $mail();
$after = ias_post_get($pid2);
t_eq('waiting', $after['status'], 'an edited Lane A post is sent for approval again');
t_eq(2, count($m), '…in fresh emails');
$page = ias_approval_response($pid2, $tok3, true);
t_ok(in_array($page['reason'], ['replaced', 'expired'], true), 'a link from before the edit no longer approves (' . $page['reason'] . ')');
$_POST = ['ias_cal_action' => 'edit', 'ias_post' => $pid2, 'ias_text' => "9 Weaver Road in Ephrata sold for \$310,000.", 'ias_and_approve' => '1'];
do_action('ias_calendar_action', 'edit');
$_POST = [];
t_eq('approved', ias_post_get($pid2)['status'], 'Save and approve approves in one step');

// ---- The Monday email ----
ias_t_clock('2026-11-09 07:10');
delete_option('ias_last_review');
ias_job_run();
$review = array_values(array_filter($mail(), function ($x) { return strpos((string) $x['subject'], 'Facebook this week') === 0; }));
t_eq(2, count($review), 'the Monday email goes to both approvers');
$body = (string) ($review[0]['message'] ?? '');
foreach (['This week', 'Waiting for approval', 'New since last week', 'Dropped this week', 'Open the OneUp calendar'] as $part) {
  t_ok(strpos($body, $part) !== false, "the Monday email has: {$part}");
}
t_ok(stripos($body, 'Facebook Event') === false, 'the Monday email has no Facebook Events section');
ias_job_run();
t_eq(0, count(array_filter($mail(), function ($x) { return strpos((string) $x['subject'], 'Facebook this week') === 0; })), 'it goes once a week');

// ---- The screens ----
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$_GET = ['page' => IAS_PAGE_CALENDAR];
ias_post_update($pid2, ['status' => 'waiting']);
ob_start(); ias_calendar_render(); $html = ob_get_clean();
ias_post_update($pid2, ['status' => 'approved']);
t_ok(strpos($html, '<h3>Waiting for approval <span class="description">(1)</span></h3>') !== false, 'the calendar lists the posts waiting for approval');
ob_start(); ias_calendar_render(); $html2 = ob_get_clean();
t_ok(strpos($html2, '<h3>Waiting for approval') === false, '…and has no such list (nor that key) when none is waiting');
t_ok(stripos($html, 'Facebook Event') === false && strpos($html, 'FB Events') === false, '…and offers no Facebook Events (DJ dropped them)');
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $pid];
ob_start(); ias_calendar_render(); $html = ob_get_clean();
t_ok(strpos($html, 'jacob@intelauctions.com') !== false && strpos($html, 'Approval') !== false, 'a Results post shows who approved it');
$_GET = ['page' => IAS_PAGE_SETTINGS];
ob_start(); ias_settings_render(); $html = ob_get_clean();
t_ok(stripos($html, 'Event card') === false && strpos($html, 'ias[types][events]') === false, 'Settings has no Facebook Events switch');
t_ok(!array_key_exists('events', ias_settings_defaults()['types']), '…and the settings carry none');
$_GET = [];
restore_error_handler();
t_eq([], $errors, 'no PHP notices on the screens');

remove_all_filters('pre_wp_mail');
ias_t_claude_off();

// ---- An approved post whose facts change goes back for a fresh yes ----
ias_t_clock('2026-11-10 05:30');
$GLOBALS['ias_t_mail'] = [];
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['ias_t_mail'][] = $atts; return true; }, 10, 2);
ias_t_claude();
$sold3 = ias_t_listing(['title' => '3 Fix Road', 'address' => '3 Fix Road, Lititz, PA 17543, USA', 'town' => 'Lititz', 'county' => 'Lancaster',
  'sale' => '2026-11-07 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'price' => '500000', 'featured' => ias_t_photo('Fix front')]);
ia_stage_set($sold3, 'past');
ias_job_run();
$r3 = ias_post_by_slot('results:2026-11-07:lititz');
$ok = ias_approve((int) $r3['id'], 'dj@intelauctions.com', 'test');
t_eq('approved', ias_post_get((int) $r3['id'])['status'], 'approved');
update_post_meta($sold3, '_ia_result_price', '505000'); // the price is corrected after the yes
ias_sync();
$r3 = ias_post_get((int) $r3['id']);
t_eq('planned', $r3['status'], 'a corrected price after approval withdraws the approval');
t_eq('the listing changed and the draft has not been rewritten yet', ias_push_ready($r3), '…and it cannot be pushed with the old price');
$GLOBALS['ias_t_mail'] = [];
global $wpdb;
$wpdb->update(ias_table('posts'), ['generated_at' => '2026-11-10 03:00:00'], ['id' => $r3['id']]);
ias_job_run();
$r3 = ias_post_get((int) $r3['id']);
t_ok(strpos((string) $r3['content']['text'], '$505,000') !== false && $r3['status'] === 'waiting', 'it is rewritten with the new price and sent for a fresh yes');
remove_all_filters('pre_wp_mail');
ias_t_claude_off();
