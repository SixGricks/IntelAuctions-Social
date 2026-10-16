<?php
/**
 * 0.25.0, DJ, Oct 15, 2026: "the post planning calendar needs to be date aware. If the auction is in 2 days it
 * can say so in the post. If the open house already passed it should not mention them. These details need
 * planned according to the proposed post date."
 *
 * A post is written as of the day it goes out: its prompt says that day, counts the sale and each open house
 * from it, and leaves out the open houses over by then. When its day moves under words that count days, or
 * past an open house it names, Claude's draft is rewritten for the new day and a person's words stop for them.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30]);
add_filter('pre_wp_mail', function () { return true; });
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $_POST = [];
};
$flash = function () { $k = 'ia_flash_' . get_current_user_id(); $h = (string) get_transient($k); delete_transient($k); return $h; };
$photos = function (string $name) { $ids = []; for ($i = 1; $i <= 3; $i++) $ids[] = ias_t_photo($name . ' ' . $i); return $ids; };
wp_set_current_user(1);

$a = ias_t_listing(['title' => '7 Calendar Road', 'town' => 'Manheim', 'county' => 'Lancaster', 'stage' => 'upcoming', 'sale' => '2026-11-12 10:00',
  'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'gallery' => $photos('Calendar'),
  'oh' => [['2026-11-05 13:00', 2], ['2026-11-09 16:00', 2]]]);
ias_job_run();
$lp = ias_post_by_slot('listing:' . $a);
t_ok($lp && !empty($lp['content']['text']), 'the listing post has a draft');
t_ok(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', (string) ($lp['flags']['written_for'] ?? '')) === 1, 'it records the day its words were written for (flags.written_for)');

// ---- 1. The prompt is written as of the post's day ----
$prompt = function (array $p) { $f = ias_post_facts($p); return ias_prompt($p, $f, ['images' => [], 'kind' => 'image'], 'place', 'ask', [])[1]; };
$p = $lp; $p['scheduled_at'] = '2026-11-03 09:00:00';
$u = $prompt($p);
t_ok(strpos($u, 'POST DATE: this post goes out on Tuesday, November 3, 2026') !== false, 'the prompt names the day the post goes out');
$facts = json_decode(substr($u, strpos($u, "FACTS:\n") + 7), true);
t_eq(2, count($facts['open_houses'] ?? []), 'both open houses are still ahead on Nov 3');
t_ok(strpos($facts['open_houses'][0], '— in 2 days') !== false, 'the first is counted from the post\'s day: ' . $facts['open_houses'][0]);
t_ok(!isset($facts['auction_is']), 'a sale nine days off is named by its date only');
$p['scheduled_at'] = '2026-11-10 09:00:00';
$facts = json_decode(substr($prompt($p), strpos($prompt($p), "FACTS:\n") + 7), true);
t_ok(!isset($facts['open_houses']), 'on Nov 10 both open houses are over: neither is in the facts');
t_eq('in 2 days', $facts['auction_is'] ?? '', 'and the sale is "in 2 days" — from the post\'s day, not today');
$brief = ias_type_brief($p, ias_post_facts($p));
t_ok(strpos($brief, 'open houses still ahead') === false, 'the listing brief asks for no open houses when none is ahead of its day');
$p['scheduled_at'] = null; $p['window_ideal'] = '2026-10-30';
t_ok(strpos($prompt($p), 'POST DATE: this post goes out on Monday, November 2, 2026') !== false, 'a day already past: written as of now');
$aw = ias_post_by_slot('auction_week:' . ias_now()->format('Y') . '-11-12') ?: null;
foreach (ias_posts_query(['type' => 'auction_week', 'limit' => 10]) as $row) $aw = $row;
t_ok($aw !== null && strpos(ias_type_brief($aw, ias_post_facts($aw)), 'how many days away') === false && strpos(ias_type_brief($aw, ias_post_facts($aw)), 'auction_is') !== false, 'Auction Week may say how near the sale is, beside the date');

// ---- 2. Claude's draft, moved under "in 2 days": rewritten for its new day ----
$c = $lp['content']; $c['text'] = "7 Calendar Road sells Thursday, November 12 — in 2 days.\n\nCall the office.";
$fl = $lp['flags']; $fl['written_for'] = '2026-11-10 09:00';
ias_post_update((int) $lp['id'], ['content' => $c, 'flags' => $fl, 'scheduled_at' => '2026-11-10 09:00:00']);
$act('move', ['ias_post' => $lp['id'], 'ias_day' => '2026-11-10', 'ias_time' => '14:00']);
$flash();
$p = ias_post_get((int) $lp['id']);
t_ok(empty($p['flags']['stale']), 'a move within the same day: the words still fit');
$act('move', ['ias_post' => $lp['id'], 'ias_day' => '2026-11-11', 'ias_time' => '09:00']);
$f = $flash();
$p = ias_post_get((int) $lp['id']);
t_ok(!empty($p['flags']['stale']) && strpos((string) ($p['flags']['stale_when'] ?? ''), 'Wed Nov 11') !== false && $p['status'] !== 'held', 'moved a day later under "in 2 days": Claude\'s draft is marked to be rewritten — ' . ($p['flags']['stale_when'] ?? ''));
t_ok(strpos($f, 'written for its old day') !== false, 'the move\'s notice says so');
t_ok(ias_push_ready($p) !== '', 'and it goes nowhere until it is rewritten');
$n = ias_t_claude_calls();
ias_t_clock('2026-11-02 07:00');
ias_drafts_run(10);
$p = ias_post_get((int) $lp['id']);
t_ok(ias_t_claude_calls() > $n && empty($p['flags']['stale']) && (string) $p['flags']['written_for'] === '2026-11-11 09:00', 'the next run rewrites it for Nov 11 (and records that day)');
t_ok(strpos((string) $GLOBALS['ias_t_claude_last']['user'], 'goes out on Wednesday, November 11, 2026') !== false, '…telling Claude the new day');
t_ok(strpos((string) $GLOBALS['ias_t_claude_last']['user'], '"auction_is": "tomorrow"') !== false, '…from which the sale is tomorrow');

// ---- 3. A person's words naming an open house: the plan's check never stops them (0.26.0); their own move warns ----
$c = $p['content']; $c['text'] = "Come to the open house Monday, November 9, 4–6 pm, then bid Thursday, November 12.";
$act('edit', ['ias_post' => $p['id'], 'ias_text' => $c['text']]);
$flash();
$p = ias_post_get((int) $lp['id']);
t_ok(!empty($p['flags']['edited_by']) && (string) $p['flags']['written_for'] === '2026-11-11 09:00' && $p['status'] !== 'held', 'a person\'s words are for the day it goes out then');
$fl = $p['flags']; $fl['written_for'] = '2026-11-06 09:00';
ias_post_update((int) $p['id'], ['flags' => $fl]); // as if they wrote it when it went out on Nov 6, before the open house
ias_timing_run();
$p = ias_post_get((int) $lp['id']);
t_ok($p['status'] === 'planned' && empty($p['flags']['holds']) && empty($p['flags']['stale']), 'the plan\'s check leaves a person\'s words alone — a person\'s yes is never sent back for review (DJ, 0.26.0)');
$act('move', ['ias_post' => $p['id'], 'ias_day' => '2026-11-11', 'ias_time' => '15:30']);
$f = $flash();
$p = ias_post_get((int) $lp['id']);
t_ok($p['status'] === 'planned' && $p['content']['text'] === $c['text'], 'moved by the person: their words stay, and it is not stopped');
t_ok(strpos($f, 'Check its words') !== false && strpos($f, 'open house on Mon Nov 9') !== false, '…the notice names the open house to check (' . strip_tags($f) . ')');

// ---- 4. The plan moves a draft: checked after every plan ----
$c = $p['content']; $c['text'] = "Bid tomorrow on 7 Calendar Road.";
$fl = $p['flags']; unset($fl['pinned'], $fl['pinned_by'], $fl['edited_by'], $fl['released_by'], $fl['timing_why']); $fl['written_for'] = '2026-11-03 09:00'; $fl['holds'] = [];
ias_post_update((int) $p['id'], ['content' => $c, 'flags' => $fl, 'scheduled_at' => '2026-11-03 09:00:00']);
$sum = ias_plan_run();
$p = ias_post_get((int) $lp['id']);
if (substr((string) $p['scheduled_at'], 0, 10) !== '2026-11-03') {
  t_ok(!empty($p['flags']['stale']) && (int) $sum['redated'] >= 1, 'the plan put it on another day: marked for a rewrite, and the plan\'s summary counts it');
} else {
  t_ok(empty($p['flags']['stale']) && (int) $sum['redated'] === 0, 'the plan kept its day: nothing to rewrite');
}
