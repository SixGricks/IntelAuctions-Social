<?php
/**
 * Talk it over (DJ, Oct 13: "a little AI chatbot I can discuss the post with
 * and have it edit real time"): a person tells Claude what to change, Claude
 * rewrites the words on their screen — never the saved post — under the same
 * rules as a draft, and Save text is still the only way the words change.
 * Every turn is one metered `social.chat` call, guarded against paying twice.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30]);
$gal = [];
for ($i = 1; $i <= 5; $i++) $gal[] = ias_t_photo('Papa ' . $i);
$l = ias_t_listing(['title' => '4 Papa Pike', 'address' => '4 Papa Pike, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'gallery' => $gal]);
ias_sync();
ias_drafts_run(30);
$p = ias_post_by_slot('listing:' . $l);
$id = (int) $p['id'];
$saved = (string) $p['content']['text'];
t_ok($saved !== '', 'the Listing post has a draft to talk over');
$usage = function (string $feature) use ($wpdb) {
  return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ia_events WHERE event = 'ai.usage' AND meta LIKE %s", '%"feature":"' . $feature . '"%'));
};

// ---- One turn: the words on the screen change, the saved post does not ----
$calls = ias_t_claude_calls();
$metered = $usage('social.chat');
$r = ias_chat_send($id, 'Make it shorter', $saved, 'admin');
t_ok($r['ok'] && strpos((string) $r['text'], 'Shorter, take') === 0, 'Claude answers with new words (' . substr((string) $r['text'], 0, 40) . '…)');
t_ok(preg_match('/#IntelAuctions/', (string) $r['text']) === 1, '…keeping the hashtag line');
t_eq('Cut it down to the sale and the call.', $r['reply'], '…and says what it did');
t_eq([], $r['holds'], '…and nothing in them would stop the post');
t_eq($saved, (string) ias_post_get($id)['content']['text'], 'the saved post is unchanged: only Save text changes it');
t_eq(1, ias_t_claude_calls() - $calls, 'one turn is one Claude call');
t_eq(1, $usage('social.chat') - $metered, '…metered against the monthly cap as social.chat');
t_eq([1, 30], [$r['used'], $r['max']], '…and counted: 1 of 30 answers on this post');
$last = $GLOBALS['ias_t_claude_last'];
t_ok(strpos($last['system'], 'FAIR HOUSING') !== false && strpos($last['system'], 'FACTS: use ONLY') !== false && strpos($last['system'], 'BIDDING') !== false, 'Claude works under the same rules as a draft');
t_ok(strpos($last['user'], "FACTS:\n") !== false && strpos($last['user'], $saved) !== false && strpos($last['user'], 'ADMIN SAYS: Make it shorter') !== false, '…with the facts, the words as they stand and what the person said');
$log = ias_log_recent('chat', 5);
t_ok($log && strpos((string) $log[0]['meta']['summary'], 'Make it shorter') !== false && strpos((string) $log[0]['meta']['summary'], 'not saved') !== false, 'the Log has the turn');

// ---- A double click: the stored answer, no second call ----
$calls = ias_t_claude_calls();
$again = ias_chat_send($id, 'Make it shorter', $saved, 'admin');
t_ok(!empty($again['repeat']) && $again['text'] === $r['text'] && ias_t_claude_calls() === $calls, 'the same message about the same words again: the same answer, nothing paid twice');

// ---- Claude sees the person's unsaved edits, and the conversation ----
$r2 = ias_chat_send($id, 'Even shorter', "My own words about Papa Pike.\n\n#IntelAuctions", 'admin');
$last = $GLOBALS['ias_t_claude_last'];
t_ok(strpos($last['user'], 'My own words about Papa Pike.') !== false, 'Claude reads the words on the screen, unsaved edits and all');
t_ok(strpos($last['user'], "admin: Make it shorter\nYou: Cut it down to the sale and the call. [you changed the text]") !== false, '…and the conversation so far');

// ---- What would stop the post is said before it is saved ----
$r3 = ias_chat_send($id, 'Add that it is great for families', $saved, 'admin');
t_ok($r3['ok'] && strpos((string) $r3['text'], 'Perfect for families') !== false, 'a person can ask for anything…');
t_ok($r3['holds'] && stripos(implode(' ', $r3['holds']), 'fair') !== false, '…but the answer says the words would stop for a decision (' . ($r3['holds'][0] ?? '') . ')');

// ---- A preference for every post: offered as a house rule ----
$r4 = ias_chat_send($id, 'never say nestled', $saved, 'admin');
t_eq('Never say nestled.', $r4['rule'], 'a preference for every post comes back as a house rule to offer');
t_ok(ias_voice_rule_add((string) $r4['rule'], 'admin')['ok'] && in_array('Never say nestled.', ias_voice_notes(), true), '…and one click adds it to the house voice');
$r5 = ias_chat_send($id, 'never say nestled', 'Other words.', 'admin');
t_ok(strpos($GLOBALS['ias_t_claude_last']['system'], "HOUSE VOICE") !== false && strpos($GLOBALS['ias_t_claude_last']['system'], '- Never say nestled.') !== false, 'the next turn follows the house rule');
t_eq(null, $r5['rule'], '…and a rule already kept is not offered again');
[$sys] = ias_prompt(ias_post_get($id), ias_post_facts(ias_post_get($id)), (array) $p['content']['media'], 'place', 'call_lead', []);
t_ok(strpos($sys, '- Never say nestled.') !== false, '…and so does every new draft');

// ---- Not the JSON asked for: shown, nothing changed ----
$r6 = ias_chat_send($id, 'answer me in not json', $saved, 'admin');
t_ok($r6['ok'] && $r6['text'] === null && $r6['reply'] === 'Plain words, no JSON at all.', 'an answer that is not JSON is shown as it came, and changes nothing');

// ---- The guards ----
$calls = ias_t_claude_calls();
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name = 'ias_chat_lock_{$id}'");
$wpdb->insert($wpdb->options, ['option_name' => 'ias_chat_lock_' . $id, 'option_value' => (string) time(), 'autoload' => 'no']);
$busy = ias_chat_send($id, 'Warmer', $saved, 'admin');
t_ok(!$busy['ok'] && strpos($busy['error'], 'still answering') !== false, 'one answer at a time per post: a second request while Claude answers is refused');
ias_chat_unlock($id);
t_ok(!ias_chat_send($id, str_repeat('word ', 400), $saved, 'admin')['ok'], 'a message over 1,500 characters is refused');
ias_settings_update(['chat_turns' => ias_chat_turns_used($id)]);
$cap = ias_chat_send($id, 'Warmer', $saved, 'admin');
t_ok(!$cap['ok'] && $cap['code'] === 429 && strpos($cap['error'], 'answers from Claude') !== false, 'at the cap of answers per post, nothing more is asked');
ias_settings_update(['chat_turns' => 0]);
t_eq(403, ias_chat_send($id, 'Warmer', $saved, 'admin')['code'], 'Settings: 0 answers takes the chat away');
ias_settings_update(['chat_turns' => 30]);
delete_option('ia_anthropic_api_key');
$nokey = ias_chat_send($id, 'Warmer', $saved, 'admin');
t_ok(!$nokey['ok'] && $nokey['code'] === 503 && strpos($nokey['error'], 'no Anthropic API key') !== false, 'with no Claude key, nothing is tried and it says why');
update_option('ia_anthropic_api_key', 'test-key');
ias_post_update($id, ['locked' => 1]);
t_eq(409, ias_chat_send($id, 'Warmer', $saved, 'admin')['code'], 'a post a person edited in OneUp is not talked over here');
ias_post_update($id, ['locked' => 0]);
t_eq($calls, ias_t_claude_calls(), 'none of the refusals cost a Claude call');
t_eq($saved, (string) ias_post_get($id)['content']['text'], 'and through all of it the saved post never changed');

// ---- The site's answers (admin-ajax): the capability, the nonce, the JSON ----
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', function () { return function () { throw new RuntimeException('wp_die'); }; });
$ajax = function (string $action, array $post) {
  $_POST = $_REQUEST = array_map('strval', $post);
  ob_start();
  try { do_action('wp_ajax_' . $action); } catch (RuntimeException $e) {}
  $out = (string) ob_get_clean();
  $_POST = $_REQUEST = [];
  return json_decode($out, true);
};
$nonce = wp_create_nonce('ias_post_' . $id);
$bad = $ajax('ias_chat', ['post' => $id, '_ajax_nonce' => 'nope', 'message' => 'Warmer', 'text' => $saved]);
t_ok(is_array($bad) && $bad['success'] === false && strpos((string) $bad['data']['message'], 'reload') !== false, 'a request without the page\'s nonce is refused');
$other = wp_create_nonce('ias_post_' . ($id + 999));
$bad = $ajax('ias_chat', ['post' => $id, '_ajax_nonce' => $other, 'message' => 'Warmer', 'text' => $saved]);
t_ok($bad['success'] === false, '…and so is another post\'s nonce');
$ok = $ajax('ias_chat', ['post' => $id, '_ajax_nonce' => $nonce, 'message' => 'Shorter again', 'text' => $saved]);
t_ok(!empty($ok['success']) && strpos((string) $ok['data']['text'], 'Shorter, take') === 0, 'with it, the answer comes back as JSON');
$pv = $ajax('ias_preview', ['post' => $id, '_ajax_nonce' => $nonce, 'text' => "Words typed a moment ago.\n\n#IntelAuctions", 'comment' => 'A comment.']);
t_ok(!empty($pv['success']) && strpos($pv['data']['html'], 'Words typed a moment ago.') !== false && strpos($pv['data']['html'], 'ias-fb') !== false, 'the preview follows the words on the screen, unsaved');
t_eq($saved, (string) ias_post_get($id)['content']['text'], '…without saving them');
$uid = get_current_user_id();
$sub = wp_insert_user(['user_login' => 'iast_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
wp_set_current_user($sub);
$no = $ajax('ias_chat', ['post' => $id, '_ajax_nonce' => wp_create_nonce('ias_post_' . $id), 'message' => 'Warmer', 'text' => $saved]);
t_ok($no['success'] === false, 'someone without manage_options gets nothing, nonce or not');
wp_set_current_user($uid);
wp_delete_user($sub);
remove_all_filters('wp_doing_ajax');
remove_all_filters('wp_die_ajax_handler');

// ---- The panel ----
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$view = function () use ($id) { $_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $id]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = []; return $h; };
$html = $view();
t_ok(strpos($html, 'id="ias-chat"') !== false && strpos($html, 'data-nonce="' . esc_attr(wp_create_nonce('ias_post_' . $id)) . '"') !== false && strpos($html, '<h2>Talk it over') !== false, 'the post view has Talk it over, and the text box carries this post\'s nonce');
t_ok(strpos($html, 'class="ias-msg me"') !== false && strpos($html, 'Use this version') !== false, '…with the conversation so far, and each version Claude offered');
t_ok(strpos($html, 'ias-chat') < strpos($html, 'id="ias-pictures"') && strpos($html, 'id="ias-text-form"') < strpos($html, 'id="ias-chat"'), '…between the words and the pictures');
ias_settings_update(['chat_turns' => 0]);
$html = $view();
t_ok(strpos($html, 'id="ias-chat"') === false && strpos($html, 'id="ias-text" name="ias_text"') !== false && strpos($html, 'data-nonce="') !== false, 'Settings: 0 answers — no chat on the post, and the preview still follows the text box');
ias_settings_update(['chat_turns' => 30]);
delete_option('ia_anthropic_api_key');
$html = $view();
t_ok(strpos($html, 'id="ias-chat-in" rows="2" maxlength="1500" placeholder="What should change?" disabled') !== false, 'without Claude, the chat is there but cannot send');
update_option('ia_anthropic_api_key', 'test-key');

// ---- Save text: the person's words, with the chat noted ----
$_POST = ['ias_cal_action' => 'edit', 'ias_post' => (string) $id, 'ias_text' => (string) $r['text']];
do_action('ias_calendar_action', 'edit');
$_POST = [];
$p = ias_post_get($id);
t_eq((string) $r['text'], (string) $p['content']['text'], 'Save text keeps the version from the chat');
t_eq('admin', (string) ($p['flags']['edited_by'] ?? ''), '…as a person\'s edit: it holds through the listing changing');
t_ok(strpos((string) end($p['flags']['trail'])['what'], 'talked over with Claude') !== false, '…and the history says it was talked over');
t_eq($saved, (string) $p['content']['claude_text'], 'Claude\'s own first draft is kept beside it');

// ---- Start over; a rewrite follows what was asked ----
$used = ias_chat_turns_used($id);
ias_chat_start_over($id);
t_eq([], ias_chat_rows($id), 'Start over: the conversation on screen begins again');
t_eq($used, ias_chat_turns_used($id), '…without resetting the cap');
t_ok(in_array('Make it shorter', ias_chat_asks($id), true), '…and what was asked is kept for the brand voice');
[, $user] = ias_prompt(ias_post_get($id), ias_post_facts(ias_post_get($id)), (array) $p['content']['media'], 'place', 'call_lead', []);
t_ok(strpos($user, "THE TEAM ASKED FOR THESE CHANGES TO THIS POST EARLIER") !== false && strpos($user, '- Make it shorter') !== false, 'a rewrite of the post is told what the team asked for');

restore_error_handler();
t_eq([], $errors, 'no PHP notices');
ias_t_claude_off();
