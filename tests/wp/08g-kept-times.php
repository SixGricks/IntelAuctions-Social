<?php
/**
 * 0.26.0, DJ, Oct 15, 2026: "When a post has been reviewed and approved the plugin should not move it and trigger
 * a review again." A post a person approved, edited or kept as written keeps the time it had while that time is
 * more than an hour ahead: the plan works around it like a Move, and nothing the plan does sends it back for
 * review. Only a person moving it changes its time — and then they are told what to check, nothing more.
 * And: the Sold pictures take turns between three layouts.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30, 'approvers' => 'dj@intelauctions.com']);
add_filter('pre_wp_mail', function () { return true; });
wp_set_current_user(1);
$flash = function () { $k = 'ia_flash_' . get_current_user_id(); $h = (string) get_transient($k); delete_transient($k); return $h; };

// ---- 1. Approved: the plan never moves it, and it is never sent back for a yes ----
$sold = ias_t_listing(['title' => '4 Kept Lane', 'address' => '4 Kept Lane, Paradise, PA 17562, USA', 'town' => 'Paradise', 'county' => 'Lancaster',
  'sale' => '2026-10-31 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'price' => '425000', 'featured' => ias_t_photo('Kept front')]);
ia_stage_set($sold, 'past');
ias_job_run();
$res = ias_post_by_slot('results:2026-10-31:paradise');
t_eq('waiting', $res['status'], 'a Results post waits for its yes');
ias_approve((int) $res['id'], 'dj@intelauctions.com', 'test');
$res = ias_post_get((int) $res['id']);
t_ok($res['status'] === 'approved' && !empty($res['scheduled_at']), 'approved, and given a time');
// Its time, later in the week than the plan would ever choose for a Results post that can go today.
ias_post_update((int) $res['id'], ['scheduled_at' => '2026-11-05 18:40:00']);
$tokens = (array) ($res['approvals']['tokens'] ?? []);
foreach (['2026-11-02 06:00', '2026-11-03 05:30', '2026-11-04 05:30'] as $day) {
  ias_t_clock($day);
  ias_sync();
  ias_plan_run();
}
$res = ias_post_get((int) $res['id']);
t_eq('2026-11-05 18:40:00', (string) $res['scheduled_at'], 'three morning plans later it is where it was approved — never moved');
t_ok($res['status'] === 'approved' && !empty($res['approved_at']), '…still approved: never sent back for a yes');
t_eq(count($tokens), count((array) ($res['approvals']['tokens'] ?? [])), '…and no new approval email went out');

// Its time gone without it going out: the plan places it again — a time, not a review.
ias_t_clock('2026-11-05 19:30');
ias_plan_run();
$res = ias_post_get((int) $res['id']);
t_ok($res['status'] === 'approved' && (string) $res['scheduled_at'] > '2026-11-05 19:30:00', 'its time passed without going out: the plan gives it a new one, still approved (' . $res['scheduled_at'] . ')');

// ---- 2. Edited (a Lane B post a person saved): the same ----
ias_t_clock('2026-11-02 05:30');
$gal = []; for ($i = 1; $i <= 3; $i++) $gal[] = ias_t_photo('Kept ' . $i);
$up = ias_t_listing(['title' => '6 Saved Road', 'address' => '6 Saved Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'gallery' => $gal]);
ias_job_run();
$lp = ias_post_by_slot('listing:' . $up);
$_POST = ['ias_cal_action' => 'edit', 'ias_post' => (string) $lp['id'], 'ias_text' => "6 Saved Road in Narvon sells November 28.\n\nCall the office with questions."];
do_action('ias_calendar_action', 'edit');
$_POST = [];
$flash();
ias_post_update((int) $lp['id'], ['scheduled_at' => '2026-11-12 13:10:00']);
ias_t_clock('2026-11-03 05:30');
ias_sync(); ias_plan_run();
$lp = ias_post_get((int) $lp['id']);
t_ok((string) $lp['scheduled_at'] === '2026-11-12 13:10:00' && $lp['status'] === 'planned', 'a post a person edited keeps its time through the plan, and is not stopped');

// ---- 3. Their words count days: a person's own move tells them what to check — nothing changes ----
$c = $lp['content']; $c['text'] = "6 Saved Road sells November 28 — just days away.\n\nCall the office.";
$fl = $lp['flags']; $fl['written_for'] = '2026-11-12 13:10';
ias_post_update((int) $lp['id'], ['content' => $c, 'flags' => $fl]);
ias_timing_run();
t_eq('planned', ias_post_get((int) $lp['id'])['status'], 'the day check run by the plan never stops a person\'s words');
$_POST = ['ias_cal_action' => 'move', 'ias_post' => (string) $lp['id'], 'ias_day' => '2026-11-20', 'ias_time' => '12:10'];
do_action('ias_calendar_action', 'move');
$_POST = [];
$f = $flash();
$lp = ias_post_get((int) $lp['id']);
t_ok($lp['status'] === 'planned' && empty($lp['flags']['stale']) && empty($lp['flags']['holds']), 'moved by the person: not stopped, not rewritten');
t_ok(strpos($f, 'Check its words') !== false && strpos($f, 'Your yes still stands') !== false, '…the move\'s notice says what to check (' . strip_tags($f) . ')');

// ---- 4. The Sold pictures take turns ----
$layouts = [];
foreach (['Gap', 'Lititz', 'Ephrata', 'Leola'] as $i => $town) {
  $id = ias_t_listing(['title' => ($i + 1) . ' Turn Road', 'address' => ($i + 1) . ' Turn Road, ' . $town . ', PA 17562, USA', 'town' => $town, 'county' => 'Lancaster',
    'sale' => '2026-10-2' . (5 + $i) . ' 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'price' => (string) (300000 + $i), 'featured' => ias_t_photo($town . ' front')]);
  ia_stage_set($id, 'past');
}
ias_sync(); ias_drafts_run(30);
foreach (ias_posts_query(['type' => 'results', 'limit' => 20, 'order' => 'id']) as $p) {
  foreach ((array) ($p['content']['media']['images'] ?? []) as $img) if (($img['kind'] ?? '') === 'graphic') $layouts[] = (string) ($img['layout'] ?? '');
}
t_ok(count($layouts) >= 4 && !array_diff(['photo', 'panel', 'stamp'], $layouts), 'the Results pictures use all three Sold layouts (' . implode(', ', $layouts) . ')');
$prev = '';
$twice = false;
foreach ($layouts as $l) { if ($l === $prev) $twice = true; $prev = $l; }
t_ok(!$twice, '…never the same look twice in a row');
$one = ias_posts_query(['type' => 'results', 'limit' => 1, 'order' => 'id'])[0];
$before = (string) ($one['content']['media']['images'][0]['layout'] ?? '');
ias_generate_post((int) $one['id'], true);
t_eq($before, (string) (ias_post_get((int) $one['id'])['content']['media']['images'][0]['layout'] ?? ''), 'a rewrite keeps the look its picture had');
