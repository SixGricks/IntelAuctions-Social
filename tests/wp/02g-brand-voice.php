<?php
/**
 * The brand voice (DJ, Oct 13: "keep a log of final posts to learn the brand
 * voice over time"): a log row for every post a person saved or approved and
 * every post that went out, Claude's draft beside the final words; examples
 * and house rules in each new draft, learned only from what a person wrote,
 * approved or starred; and Suggest updates, which changes nothing until a
 * person saves it.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
add_filter('pre_wp_mail', '__return_true');
ias_settings_update(['generate_per_run' => 30]);
$mk = function (string $street, string $town) use ($T) {
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($street . ' ' . $i);
  return ias_t_listing(['title' => $street, 'address' => $street . ', ' . $town . ', PA 17555, USA', 'town' => $town, 'county' => 'Lancaster',
    'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'gallery' => $gal]);
};
$la = $mk('1 Quebec Road', 'Gap');
$lb = $mk('2 Romeo Road', 'Paradise');
$lc = $mk('3 Sierra Road', 'Kinzers');
$ld = $mk('4 Tango Road', 'Narvon');
ias_sync();
ias_drafts_run(30);
$post = function (int $l) { return ias_post_by_slot('listing:' . $l); };
[$a, $b, $c, $d] = [$post($la), $post($lb), $post($lc), $post($ld)];
t_ok($a && $b && $c && $d && $a['content']['text'] !== '', 'four Listing posts with drafts');
t_eq((string) $a['content']['text'], (string) $a['content']['claude_text'], 'a draft keeps Claude\'s words as written');
t_eq(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . ias_table('voice')), 'nothing is in the log before a person or a publish touches a post');

$edit = function (int $id, string $text) {
  $_POST = ['ias_cal_action' => 'edit', 'ias_post' => (string) $id, 'ias_text' => $text];
  do_action('ias_calendar_action', 'edit');
  $_POST = [];
};

// ---- A person's edit: Claude's draft beside their words ----
$a_claude = (string) $a['content']['text'];
$a_final = "Forty acres outside Gap, with a bank barn.\n\nIt is an in-person auction. Call the office.\n\n#IntelAuctions #Gap";
$edit((int) $a['id'], $a_final);
$row = ias_voice_get((int) $a['id']);
t_ok($row && $row['edited'] === 1 && $row['touched'] === 1 && $row['state'] === 'kept', 'a saved edit puts the post in the log, as a person\'s words, kept but not out yet');
t_eq([$a_claude, $a_final, 'admin'], [$row['claude_text'], $row['final_text'], $row['person']], '…Claude\'s draft beside the final words, and who wrote them');

// ---- A rewrite throws the person's words away: the row goes with them ----
$flags = ias_post_get((int) $a['id'])['flags'];
unset($flags['regen_at']);
ias_post_update((int) $a['id'], ['flags' => $flags]);
ias_generate_post((int) $a['id'], true, 'admin');
t_eq(null, ias_voice_get((int) $a['id']), 'a rewrite replaces the person\'s words, so the log forgets them');
$a_claude = (string) ias_post_get((int) $a['id'])['content']['text'];
$edit((int) $a['id'], $a_final);
t_eq($a_claude, ias_voice_get((int) $a['id'])['claude_text'], '…and an edit of the new draft logs it against the new draft');

// ---- An approval: words a person said yes to ----
ias_post_update((int) $b['id'], ['lane' => 'A', 'status' => 'waiting']);
$r = ias_approve((int) $b['id'], 'dj@intelauctions.com', 'email link');
$row = ias_voice_get((int) $b['id']);
t_ok($r['ok'] && $row && $row['edited'] === 0 && $row['touched'] === 1 && $row['approved_by'] === 'dj@intelauctions.com', 'an approved post is in the log, approved as written');

// ---- A post that went out as Claude wrote it: logged, teaches nothing ----
$flags = ias_post_get((int) $c['id'])['flags'];
$flags['oneup'] = ['id' => '777', 'hash' => 'sent', 'when' => '2026-11-02 05:00:00']; // on OneUp
ias_post_update((int) $c['id'], ['flags' => $flags, 'status' => 'planned', 'scheduled_at' => '2026-11-02 05:00:00']);
ias_push_run();
$row = ias_voice_get((int) $c['id']);
t_ok(ias_post_get((int) $c['id'])['status'] === 'published' && $row && $row['state'] === 'out' && $row['touched'] === 0, 'a post that went out is in the log of final posts, as written by Claude');
t_ok(!empty($row['out_at']), '…with when it went out');

// ---- Examples in the next draft: only what a person wrote or approved ----
$pd = ias_post_get((int) $d['id']);
$ex = array_column(ias_voice_examples($pd, 5), 'post_id');
sort($ex);
$want = [(int) $a['id'], (int) $b['id']];
sort($want);
t_eq($want, $ex, 'the examples are the posts a person wrote or approved — not the one that went out unread');
t_ok(!in_array((int) $a['id'], array_column(ias_voice_examples(ias_post_get((int) $a['id']), 5), 'post_id'), true), 'a post is never its own example');
[$sys, $user] = ias_prompt($pd, ias_post_facts($pd), (array) $pd['content']['media'], 'place', 'call_lead', []);
t_ok(strpos($user, 'HOW THE TEAM SOUNDS') !== false && strpos($user, "DRAFT:\n") !== false && strpos($user, "CHANGED TO:\nForty acres outside Gap, with a bank barn.") !== false, 'the next draft is shown how the team sounds, with one draft and what the team changed it to');
t_ok(strpos($user, '#IntelAuctions #Gap') === false, '…without the examples\' hashtags');
t_ok(strpos($user, 'HOW THE TEAM SOUNDS') < strpos($user, "FACTS:\n"), '…before the facts');
ias_settings_update(['voice_examples' => 0]);
[, $user0] = ias_prompt($pd, ias_post_facts($pd), (array) $pd['content']['media'], 'place', 'call_lead', []);
t_ok(strpos($user0, 'HOW THE TEAM SOUNDS') === false, 'Settings: 0 examples — none');
ias_settings_update(['voice_examples' => 3]);
$flags = $pd['flags'];
unset($flags['regen_at']);
ias_post_update((int) $d['id'], ['flags' => $flags]);
$gen = ias_generate_post((int) $d['id'], true, 'admin');
t_ok($gen['ok'] && strpos($GLOBALS['ias_t_claude_last']['user'], 'HOW THE TEAM SOUNDS') !== false, 'a real draft goes out with the examples and still comes back usable');

// ---- Star and Learn from this ----
ias_voice_flag((int) $c['id'], 'star', true);
t_eq((int) $c['id'], (int) ias_voice_examples($pd, 3)[0]['post_id'], 'a starred post teaches, ahead of the rest — even one that went out unread');
ias_voice_flag((int) $a['id'], 'learn', false);
t_ok(!in_array((int) $a['id'], array_column(ias_voice_examples($pd, 5), 'post_id'), true), 'Don\'t learn from this takes a post out of the examples');
ias_voice_flag((int) $a['id'], 'learn', true);
$f = ias_post_get((int) $b['id'])['flags'];
$f['holds'] = ['Fair housing: "perfect for families"'];
ias_post_update((int) $b['id'], ['flags' => $f]);
ias_voice_record((int) $b['id'], 'kept');
t_ok(!in_array((int) $b['id'], array_column(ias_voice_examples($pd, 5), 'post_id'), true), 'a post that stopped for a decision is never an example');
$f['holds'] = [];
ias_post_update((int) $b['id'], ['flags' => $f]);
ias_voice_record((int) $b['id'], 'kept');

// ---- House rules: a person's, never a fair-housing phrase ----
$saved = ias_voice_notes_save(['Never use the word nestled.', '- Lead with the place.', 'Say it is perfect for families.'], 'admin');
t_eq(['Never use the word nestled.', 'Lead with the place.'], ias_voice_notes(), 'the house rules are saved, cleaned up');
t_eq(1, count($saved['refused']), '…and a rule that would put a fair-housing phrase in posts is refused');
[$sys] = ias_prompt($pd, ias_post_facts($pd), (array) $pd['content']['media'], 'place', 'call_lead', []);
t_ok(strpos($sys, "HOUSE VOICE") !== false && strpos($sys, '- Lead with the place.') !== false && strpos($sys, 'FAIR HOUSING:') < strpos($sys, 'HOUSE VOICE'), 'the rules go into every draft, after the facts, bidding and fair-housing rules');
ias_voice_notes_save([], 'admin');
[$sys] = ias_prompt($pd, ias_post_facts($pd), (array) $pd['content']['media'], 'place', 'call_lead', []);
t_ok(strpos($sys, 'HOUSE VOICE') === false, 'no rules, no block');

// ---- Suggest updates: Claude reads the log, a person decides ----
$calls = ias_t_claude_calls();
$r = ias_voice_learn('admin');
$s = ias_voice_suggestion();
t_ok($r['ok'] && $s && $s['rules'] === ['Never use the word nestled.', 'Lead with the place.'], 'Claude suggests house rules from the log (' . implode(' | ', (array) ($s['rules'] ?? [])) . ')');
t_eq(1, count($s['refused']), '…its fair-housing one left out');
t_eq([], ias_voice_notes(), '…and nothing changes until a person saves them');
t_ok(strpos($GLOBALS['ias_t_claude_last']['user'], "CLAUDE WROTE:\n") !== false && strpos($GLOBALS['ias_t_claude_last']['user'], "THE TEAM CHANGED IT TO:\nForty acres outside Gap") !== false, '…having read what the team changed');
t_eq(1, ias_t_claude_calls() - $calls, '…in one Claude call');
$again = ias_voice_learn('admin');
t_ok(!$again['ok'] && strpos($again['error'], '10 minutes') !== false && ias_t_claude_calls() - $calls === 1, 'pressed again with nothing new: refused, not paid for twice');
delete_option('ia_anthropic_api_key');
delete_option('ias_voice_learned');
t_ok(!ias_voice_learn('admin')['ok'] && ias_t_claude_calls() - $calls === 1, 'with no Claude key, nothing is tried');
update_option('ia_anthropic_api_key', 'test-key');

// ---- Claude reads the log on its own: weekly at most, after enough new posts, a person still decides ----
delete_option('ias_voice_suggest');
update_option('ias_voice_learned', ['ts' => ias_now()->getTimestamp() - 8 * DAY_IN_SECONDS, 'at' => '2026-10-01 00:00:00'], false);
add_filter('ias_voice_auto_after', function () { return 2; });
$calls = ias_t_claude_calls();
ias_settings_update(['voice_auto' => false]);
t_eq('', ias_voice_auto_learn(), 'Learn on its own off: nothing');
ias_settings_update(['voice_auto' => true]);
$line = ias_voice_auto_learn();
t_ok(strpos($line, 'suggested house rules') !== false && ias_voice_suggestion() && ias_t_claude_calls() - $calls === 1, 'on: with enough new posts and a week gone, Claude reads the log once (' . $line . ')');
t_eq([], ias_voice_notes(), '…and the rules wait for a person');
t_eq('', ias_voice_auto_learn(), 'not again while its suggestion waits for a person');
delete_option('ias_voice_suggest');
t_eq('', ias_voice_auto_learn(), '…nor within a week of the last read');
t_eq(1, ias_t_claude_calls() - $calls, '…so one call in all');
update_option('ias_voice_learned', ['ts' => ias_now()->getTimestamp() - DAY_IN_SECONDS, 'at' => '2026-10-01 00:00:00'], false);
ias_voice_learn('admin'); // a suggestion waiting, for the calendar, the email and the screen
ob_start(); do_action('ias_calendar_attention'); $attn = (string) ob_get_clean();
t_ok(strpos($attn, 'House rules: Claude suggested changes') !== false, 'the calendar says a suggestion is waiting');
[, $mail] = ias_review_email();
t_ok(strpos($mail, 'House rules: Claude suggested changes') !== false && strpos($mail, '+ Never use the word nestled.') !== false, '…and so does the weekly email, with the changes');
remove_all_filters('ias_voice_auto_after');

// ---- The screen ----
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$_GET = ['page' => IAS_PAGE_VOICE];
ob_start(); ias_voice_render(); $html = (string) ob_get_clean();
t_ok(strpos($html, 'House rules') !== false && strpos($html, 'The log of final posts') !== false && strpos($html, 'Claude\'s suggestion') !== false, 'Brand voice has the rules, the log and Claude\'s suggestion');
t_ok(strpos($html, '<ins>+ Never use the word nestled.</ins>') !== false, '…the suggestion shows what it would add');
t_ok(strpos($html, '>Use these rules</button>') !== false && strpos($html, 'Adds 2 rules.') !== false, '…and says how many rules it adds');
t_ok(strpos($html, 'Edited by admin') !== false && strpos($html, 'Approved as written by DJ') !== false && strpos($html, 'As Claude wrote it') !== false, '…whose words each post is, by name');
t_ok(strpos($html, '<del>') !== false && strpos($html, '<ins>Forty') !== false, '…and what the person changed, word by word');
$_GET = ['page' => IAS_PAGE_VOICE, 'show' => 'written'];
ob_start(); ias_voice_render(); $html = (string) ob_get_clean();
t_eq(1, substr_count($html, '<tr><td>'), 'the filter "Went out as Claude wrote it" shows that one post');
$_GET = ['page' => IAS_PAGE_SETTINGS];
ob_start(); ias_settings_render(); $html = (string) ob_get_clean();
t_ok(strpos($html, 'name="ias[chat_turns]"') !== false && strpos($html, 'name="ias[voice_examples]"') !== false, 'Settings has Talk it over and the examples per draft');
$_GET = [];
restore_error_handler();
t_eq([], $errors, 'no PHP notices');

// ---- The log is a window, the rules are what lasts ----
$voice = ias_table('voice');
$wpdb->query($wpdb->prepare("UPDATE {$voice} SET updated_at = %s WHERE post_id IN (%d, %d)", '2026-06-01 00:00:00', (int) $a['id'], (int) $c['id']));
ias_voice_flag((int) $c['id'], 'star', true);
$wpdb->insert(ias_table('chat'), ['post_id' => (int) $a['id'], 'who' => 'person', 'actor' => 'admin', 'message' => 'Old ask', 'created_at' => '2026-08-01 00:00:00']);
$wpdb->insert(ias_table('chat'), ['post_id' => (int) $a['id'], 'who' => 'person', 'actor' => 'admin', 'message' => 'Fresh ask', 'created_at' => '2026-11-01 00:00:00']);
$pr = ias_voice_prune(true);
t_eq(null, ias_voice_get((int) $a['id']), 'a post older than 120 days leaves the log');
t_ok(ias_voice_get((int) $c['id']) !== null, '…a starred one stays');
t_ok(ias_voice_get((int) $b['id']) !== null, '…and a recent one stays');
t_eq(['Fresh ask'], ias_chat_asks((int) $a['id']), 'chat messages older than 45 days go');
t_eq(['voice' => 1, 'chat' => 1], $pr, '…counted for the run summary');
t_eq(['voice' => 0, 'chat' => 0], ias_voice_prune(), 'once a day: the next hourly run does nothing');
add_filter('ias_voice_keep_rows', function () { return 50; });
for ($i = 0; $i < 52; $i++) $wpdb->insert($voice, ['post_id' => 900000 + $i, 'type' => 'listing', 'final_text' => 'x', 'touched' => 1, 'created_at' => ias_now_sql(), 'updated_at' => date('Y-m-d H:i:s', strtotime('2026-10-20') + $i)]);
ias_voice_prune(true);
t_eq(50, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$voice} WHERE star = 0"), 'past its cap, the oldest posts go first');
t_ok(ias_voice_get(900000) === null && ias_voice_get(900051) !== null && ias_voice_get((int) $c['id']) !== null, '…never a starred one');
$wpdb->query("DELETE FROM {$voice} WHERE post_id >= 900000");
remove_all_filters('ias_voice_keep_rows');

remove_all_filters('pre_wp_mail');
ias_t_claude_off();
