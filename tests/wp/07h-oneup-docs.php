<?php
/**
 * 0.16.0, Oct 14, 2026: OneUp as docs.oneupapp.io describes it (DJ shared
 * the docs). The fake answers exactly as they show: lists under `data`, up to
 * 50 a page from `start`, no next_start; scheduling answers "1 new Posts
 * Scheduled." with no post id; the published list carries the network's own
 * id ("FB_…"), not OneUp's; a failed row's reason is `fail_reason`.
 *
 * Checked: Verify OneUp passes and every scheduling call is a POST; with more
 * than 50 posts on the list, a post of ours on the second page is found, kept
 * (never taken for "deleted in OneUp by a person") and taken back by its id;
 * a list that does not move past its first page is never read as complete;
 * "0 new Posts Scheduled." is a refusal; OneUp's failure reason is shown; a
 * post that went out is confirmed by its words, page and time, keeps the
 * network's id, and is never taken for a person's post — while a person's own
 * still is.
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
// 49 posts of another page in the same OneUp login, ahead of ours: the first of ours is the 50th row
// (the end of the first page), the next one is on the second page.
for ($i = 0; $i < 49; $i++) {
  $oid = $S['next']++;
  $S['scheduled'][$oid] = ['post_id' => $oid, 'content' => 'Another page, post ' . $i, 'source_url' => 'NA', 'date_time' => '2026-11-20 08:' . sprintf('%02d', $i) . ':00',
    'category_name' => 'Other', 'video_url' => 'NA', 'content_image' => 'NA', 'social_network_username' => 'Another page'];
}
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$mk = function (string $title, string $sale, string $county) use ($T, $lead) {
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing(['title' => $title, 'address' => $title . ', Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => $county,
    'stage' => 'upcoming', 'sale' => $sale, 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
};
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$methods = function (array $calls) { return array_values(array_unique(array_column($calls, 'method'))); };

// ---- 1. Verify OneUp, and POST ----
ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
$c0 = count($S['calls']);
$v = ias_oneup_verify('admin');
t_ok($v['ok'], 'Verify OneUp passes against answers shaped as the docs show (' . implode('; ', array_map(function ($s) { return $s['label'] . ': ' . ($s['ok'] ? 'ok' : $s['detail']); }, array_filter($v['steps'], function ($s) { return !$s['ok']; }))) . ')');
t_eq(['POST'], $methods(ias_t_oneup_calls($c0, 'scheduletextpost')), 'its draft is sent by POST, as documented');
t_eq([], $S['drafts'], '…and deleted, though OneUp gave it no id (found by its words)');

ias_settings_update(['types' => ['listing' => true]]);
$a = $mk('12 Alpha Road', '2026-11-28 10:00', 'Lancaster');
$b = $mk('7 Bravo Lane', '2026-11-27 10:00', 'Berks');
ias_sync(); ias_drafts_run(30); ias_plan_run();
$c1 = count($S['calls']);
ias_push_run();
t_eq(['POST'], $methods(array_merge(ias_t_oneup_calls($c1, 'scheduleimagepost'), ias_t_oneup_calls($c1, 'scheduletextpost'))), 'posts are scheduled by POST');

// ---- 2. More than 50 on the list ----
$ids = [];
foreach ([$a, $b] as $l) {
  $p = $lp($l);
  $ids[$l] = (string) ias_post_sent($p)['id'];
}
t_ok(!in_array('', $ids, true) && isset($S['scheduled'][(int) $ids[$a]], $S['scheduled'][(int) $ids[$b]]), 'OneUp answered with no post id: each post is found on its list by its words and time (#' . implode(', #', $ids) . ')');
$pos = array_flip(array_map('strval', array_keys($S['scheduled'])));
t_ok(max($pos[$ids[$a]], $pos[$ids[$b]]) >= 50, '…one of them past the first page of 50');
$l = ias_oneup_list('scheduled');
t_ok($l['ok'] && $l['complete'] && count($l['rows']) === count($S['scheduled']), 'the scheduled list is read to its end, page by page (' . count($l['rows']) . ' rows, no next_start)');
t_eq(['0', '50'], array_map(function ($c) { return (string) ($c['params']['start'] ?? ''); }, array_slice(ias_t_oneup_calls(count($S['calls']) - 2, 'getscheduledposts'), -2)), '…asking for the second page from where the first ended');
ias_push_run();
ias_t_clock('2026-11-02 06:15');
ias_push_run();
foreach ([$a, $b] as $l) {
  $p = $lp($l);
  t_ok(in_array($p['status'], ['planned', 'approved'], true) && ias_post_sent($p)['id'] === $ids[$l] && empty($p['flags']['missing_since']), 'two read-backs 45 minutes apart: ' . get_the_title($l) . ' is still ours, not "deleted in OneUp" (' . $p['status'] . ')');
}

// A list that does not move on: never complete, nothing concluded.
$S['ignore_start'] = true;
$l = ias_oneup_list('scheduled');
t_ok($l['ok'] && !$l['complete'] && count($l['rows']) === 50, 'a list whose second page repeats the first is never read as complete');
ias_push_run();
ias_t_clock('2026-11-02 07:00');
ias_push_run();
t_ok(!array_filter([$a, $b], function ($l) use ($lp) { return $lp($l)['status'] === 'skipped'; }), '…and nothing is taken for deleted from it');
$S['ignore_start'] = false;

// ---- 3. Off, on, and "0 new Posts Scheduled." ----
// Eleven more of the other page's posts ahead of ours: both of ours are on the second page now, and taking
// one off no longer brings the other back onto the first.
$front = [];
for ($i = 0; $i < 11; $i++) {
  $oid = $S['next']++;
  $front[$oid] = ['post_id' => $oid, 'content' => 'Another page, early post ' . $i, 'source_url' => 'NA', 'date_time' => '2026-11-03 08:' . sprintf('%02d', $i) . ':00',
    'category_name' => 'Other', 'video_url' => 'NA', 'content_image' => 'NA', 'social_network_username' => 'Another page'];
}
$S['scheduled'] = $front + $S['scheduled'];
ias_settings_update(['types' => ['listing' => false]]);
ias_push_run();
t_ok(count($S['scheduled']) === 60 && ias_post_sent($lp($a))['mode'] === '' && ias_post_sent($lp($b))['mode'] === '', 'switched off: both taken back off OneUp by their ids, the second page\'s too');
ias_settings_update(['types' => ['listing' => true]]);
$S['zero_new'] = 1;
$n = count($S['scheduled']);
ias_push_run();
$refused = array_values(array_filter([$a, $b], function ($l) use ($lp) { return strpos((string) ($lp($l)['flags']['push_error'] ?? ''), '0 new Posts Scheduled') !== false; }));
t_eq(1, count($refused), '"0 new Posts Scheduled." is a refusal, not a success');
t_ok(ias_post_sent($lp($refused[0] ?? 0) ?: ['flags' => []])['mode'] === '' && empty($lp($refused[0] ?? 0)['flags']['sending']), '…nothing is recorded as sent, and nothing is left waiting to be looked for');
t_eq($n + 1, count($S['scheduled']), '…while the other post went');
ias_push_run();
foreach ([$a, $b] as $l) $ids[$l] = (string) ias_post_sent($lp($l))['id'];
t_ok(!in_array('', $ids, true), 'the next run sends it (#' . implode(', #', $ids) . ')');

// ---- 4. OneUp's reason for a failure ----
$bid = (int) $ids[$b];
$row = $S['scheduled'][$bid];
unset($S['scheduled'][$bid]);
$row['fail_reason'] = 'The image size exceeds the platform limit.';
$S['failed'][$bid] = $row;
ias_push_run();
$bp = $lp($b);
t_eq('failed', $bp['status'], 'a post OneUp could not publish stops');
t_ok(strpos((string) $bp['note'], 'The image size exceeds the platform limit.') !== false, '…with OneUp\'s fail_reason (' . $bp['note'] . ')');

// ---- 5. Published: the network's own id on the list ----
$ap = $lp($a);
$aid = (int) $ids[$a];
ias_t_clock(date('Y-m-d H:i', strtotime((string) $ap['scheduled_at']) + 2 * HOUR_IN_SECONDS));
$hid = ias_t_oneup_human(date('Y-m-d H:i', strtotime((string) $ap['scheduled_at']) + HOUR_IN_SECONDS), 'DJ: a word about Saturday');
ias_t_oneup_publish($aid);
ias_t_oneup_publish($hid);
ias_job_run();
$ap = $lp($a);
t_eq('published', $ap['status'], 'its time came: published');
t_ok(empty($ap['flags']['unconfirmed']), '…confirmed by the read-back, though the published list gives Facebook\'s own id — matched by its words, page and time');
t_eq('FB_1336677789520495_' . $aid, (string) ($ap['flags']['oneup']['published_id'] ?? ''), '…and that id is kept with it');
$humans = ias_posts_query(['status' => 'human', 'limit' => 50]);
t_eq(['FB_1336677789520495_' . $hid], array_values(array_map(function ($h) { return (string) $h['oneup_post_id']; }, array_filter($humans, function ($h) { return strpos((string) $h['oneup_post_id'], 'FB_') === 0; }))), 'it is never taken for a person\'s post — while DJ\'s own, published beside it, is');
ias_job_run();
t_eq(1, count(array_filter(ias_posts_query(['status' => 'human', 'limit' => 50]), function ($h) { return strpos((string) $h['oneup_post_id'], 'FB_') === 0; })), '…and the next read-back changes nothing');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'types' => ['listing' => false]]);
delete_option('ias_oneup_verify');
