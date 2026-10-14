<?php
/**
 * Talk it over edits the whole post (DJ, Oct 13: "give the AI agent a way to
 * edit first comments and anything else about the post") and Save changes
 * saves it without approving ("this needs a way to save changes without
 * approving"): the first comment, which of the property's pictures and in
 * what order, and their alt text — all on the screen until one Save changes,
 * which never approves, sends an approve-first post for its yes once, and
 * checks the comment (fair housing, [VERIFY], the listing link). The first
 * comment's lead-in is a setting; Results says "View the full listing".
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
$mails = [];
add_filter('pre_wp_mail', function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; }, 10, 2);
ias_settings_update(['generate_per_run' => 30]);
$gal = [];
for ($i = 1; $i <= 8; $i++) $gal[] = ias_t_photo('Uniform ' . $i);
$l = ias_t_listing(['title' => '5 Uniform Road', 'address' => '5 Uniform Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'gallery' => $gal]);
ias_sync();
ias_drafts_run(30);
$p = ias_post_by_slot('listing:' . $l);
$id = (int) $p['id'];
$text = (string) $p['content']['text'];
$comment = (string) $p['content']['first_comment'];
$keys = ias_media_keys($p);
t_ok($comment !== '' && count($keys) >= 3, 'a Listing post with a first comment and ' . count($keys) . ' pictures');
preg_match('~https?://\S+~', $comment, $m);
$link = $m[0] ?? '';
$screen = ['text' => $text, 'comment' => $comment, 'media' => $keys, 'alt' => []];
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });

// ---- The first comment ----
$r = ias_chat_send($id, 'Change the comment, never say How it sold', $screen, 'admin');
t_ok($r['ok'] && strpos((string) $r['comment'], 'View full listing: ') === 0 && strpos((string) $r['comment'], $link) !== false, 'Claude changes the first comment, keeping the listing link (' . substr((string) $r['comment'], 0, 40) . '…)');
t_eq(['the first comment'], $r['changed'], '…and says that is what it changed');
t_eq([], $r['holds'], '…with nothing that would stop it');
t_eq(null, $r['text'], '…leaving the text');
$seen = $GLOBALS['ias_t_claude_chat'];
t_eq([$comment, $keys], [$seen['comment'], $seen['pictures']], 'Claude was shown the first comment and the pictures on the screen');
t_ok(strpos($GLOBALS['ias_t_claude_last']['system'], 'FIRST COMMENT: it carries the listing link') !== false && strpos($GLOBALS['ias_t_claude_last']['system'], 'You cannot change when the post goes out') !== false, '…told to keep the link, and what it cannot change');
t_eq($comment, (string) ias_post_get($id)['content']['first_comment'], 'nothing is saved yet');
$r2 = ias_chat_send($id, 'drop the link', $screen, 'admin');
t_ok($r2['comment'] === 'View full listing on our website.' && strpos(implode(' ', $r2['holds']), 'no longer carries the listing link') !== false, 'a comment without the listing link is flagged before it is saved');

// ---- Pictures and alt text ----
$r3 = ias_chat_send($id, 'Put the aerial first', $screen, 'admin');
$want = array_reverse($keys);
$want[] = $GLOBALS['ias_t_claude_chat']['others'][0] ?? '';
t_ok($r3['ok'] && $r3['changed'] === ['the pictures', 'the alt text'], 'Claude changes the pictures and their alt text (' . implode(', ', $r3['changed']) . ')');
t_eq(array_values(array_filter($want)), array_column((array) $r3['pics'], 'key'), '…in its order, with one of the property\'s other photos — an unknown picture and a repeat dropped');
t_eq('Aerial view of the farm and its barns.', $r3['pics'][0]['alt'], '…the new lead with Claude\'s alt text');
t_ok($r3['pics'][0]['alt0'] !== $r3['pics'][0]['alt'] && $r3['pics'][1]['alt0'] === $r3['pics'][1]['alt'], '…each tile knowing its saved alt text, so only a change is saved');
t_ok(strpos($r3['pics'][0]['thumb'], 'http') === 0, '…with a picture to show');
t_ok(strpos($GLOBALS['ias_t_claude_last']['user'], "THE PROPERTY'S OTHER PHOTOS") !== false, 'Claude was offered the property\'s other photos, by key');
t_eq($keys, ias_media_keys(ias_post_get($id)), 'the saved pictures have not changed');
$r4 = ias_chat_send($id, 'alt only for the lead', $screen, 'admin');
t_ok($r4['changed'] === ['the alt text'] && array_column($r4['pics'], 'key') === $keys && $r4['pics'][0]['alt'] === 'The farmhouse from the lane, with the bank barn behind it.', 'alt text alone: the same pictures, a new description');

// ---- The preview follows the screen ----
$order = array_column($r3['pics'], 'key');
$html = ias_preview_html(ias_post_get($id), ['text' => $text, 'comment' => (string) $r['comment'], 'media' => $order, 'alt' => [$order[0] => 'Aerial view of the farm and its barns.']]);
t_ok(strpos($html, 'View full listing: ') !== false && strpos($html, 'alt="Aerial view of the farm and its barns."') !== false, 'the preview shows the unsaved comment and pictures, with their alt text');

// ---- Save changes: pictures and alt text alone leave the words as they were ----
$save = function (array $post) use ($id) {
  $_POST = ['ias_cal_action' => 'edit', 'ias_post' => (string) $id] + array_map('strval', $post);
  do_action('ias_calendar_action', 'edit');
  $_POST = [];
  return ias_post_get($id);
};
$p = $save(['ias_text' => $text, 'ias_comment' => $comment, 'ias_media' => implode(',', $order), 'ias_alt' => wp_json_encode([$order[0] => 'Aerial view of the farm and its barns.'])]);
t_eq($order, ias_media_keys($p), 'Save changes saves the pictures in the new order');
t_eq('Aerial view of the farm and its barns.', (string) $p['content']['media']['images'][0]['alt'], '…and the alt text');
t_ok(empty($p['flags']['edited_by']) && ($p['content']['generated_by'] ?? '') === 'claude', '…without making the unchanged words a person\'s edit');
t_eq('admin', (string) ($p['flags']['media_by'] ?? ''), '…the pictures now chosen by hand');
$att = (int) substr($order[0], 1);
t_eq('Aerial view of the farm and its barns.', (string) ($p['flags']['alt'][$att] ?? ''), '…and the alt text kept on the post');
$flags = $p['flags'];
unset($flags['regen_at']);
ias_post_update($id, ['flags' => $flags]);
ias_generate_post($id, true, 'admin');
t_eq('Aerial view of the farm and its barns.', (string) ias_post_get($id)['content']['media']['images'][0]['alt'], 'a rewrite keeps a person\'s alt text');
$p = ias_post_get($id);
$k0 = ias_media_keys($p)[0];
$att0 = (int) substr($k0, 1);
$p = $save(['ias_text' => (string) $p['content']['text'], 'ias_comment' => (string) $p['content']['first_comment'], 'ias_media' => implode(',', ias_media_keys($p)), 'ias_alt' => wp_json_encode([$k0 => ''])]);
t_ok(!isset($p['flags']['alt'][$att0]) && (string) $p['content']['media']['images'][0]['alt'] === ias_photo_alt($att0, ias_post_facts($p)[0]), 'blank alt text takes a person\'s alt text away again: back to the plugin\'s (Codex, PR #3)');
$p = ias_post_get($id);
$text = (string) $p['content']['text'];
$comment = (string) $p['content']['first_comment'];

// ---- The comment, saved and checked ----
$p = $save(['ias_text' => $text, 'ias_comment' => 'View full listing: ' . $link, 'ias_media' => implode(',', ias_media_keys($p))]);
t_eq('View full listing: ' . $link, (string) $p['content']['first_comment'], 'Save changes saves the first comment');
t_ok($p['status'] === 'planned' && strpos((string) end($p['flags']['trail'])['what'], 'Text and first comment edited') === 0, '…as a person\'s edit, with nothing to stop it');
$p = $save(['ias_text' => $text, 'ias_comment' => 'View full listing on our website.']);
t_ok($p['status'] === 'held' && strpos(implode(' ', $p['flags']['holds']), 'no longer carries the listing link') !== false, 'a comment that lost the listing link stops for a decision');
$p = $save(['ias_text' => $text, 'ias_comment' => 'Perfect for families: ' . $link]);
t_ok($p['status'] === 'held' && strpos(implode(' ', $p['flags']['holds']), 'Fair housing in the first comment') !== false, '…and so does a fair-housing phrase in it');
$p = $save(['ias_text' => $text, 'ias_comment' => 'View full listing: ' . $link]);
t_eq('planned', $p['status'], 'put right, it goes back on the plan');

// ---- An approve-first post: saving never approves, and asks for the yes once ----
ias_settings_update(['approvers' => 'dj@intelauctions.com, jacob@intelauctions.com']);
ias_post_update($id, ['lane' => 'A', 'status' => 'waiting']);
$mails = [];
$keys = ias_media_keys(ias_post_get($id));
$p = $save(['ias_text' => $text . "\n\nSee you there.", 'ias_comment' => 'View full listing: ' . $link, 'ias_media' => implode(',', array_reverse($keys))]);
t_ok($p['status'] === 'waiting' && (string) $p['approved_by'] === '', 'Save changes on a post waiting for a yes: saved, not approved, and back out for its yes');
t_eq(2, count($mails), '…one email to each approver for the words and pictures together, not one per change (' . count($mails) . ')');
$p = ias_post_get($id);
$_POST = ['ias_cal_action' => 'edit', 'ias_post' => (string) $id, 'ias_text' => (string) $p['content']['text'], 'ias_comment' => (string) $p['content']['first_comment'], 'ias_media' => implode(',', $keys), 'ias_and_approve' => '1'];
do_action('ias_calendar_action', 'edit');
$_POST = [];
$p = ias_post_get($id);
t_ok($p['status'] === 'approved' && $keys === ias_media_keys($p), 'Save and approve still saves and approves');
ias_post_update($id, ['lane' => 'B', 'status' => 'planned', 'approved_by' => '', 'approved_at' => null]);

// ---- The first comment's lead-in is a setting ----
$res = ['type' => 'results', 'id' => 1];
$f = ias_post_facts(ias_post_get($id));
t_eq('View the full listing: ', substr(ias_first_comment($res, $f)[0], 0, 23), 'Results posts say "View the full listing", not "how it sold"');
ias_settings_update(['comment_leads' => ['results' => 'Photos and the sale price:']]);
t_eq('Photos and the sale price: ', substr(ias_first_comment($res, $f)[0], 0, 27), 'the lead-in is a setting');
ias_settings_update(['comment_leads' => ['results' => '']]);
t_eq('View the full listing: ', substr(ias_first_comment($res, $f)[0], 0, 23), '…and blank is the default');
t_eq('Photos, terms and the full listing: ', substr(ias_first_comment(ias_post_get($id), $f)[0], 0, 36), 'the others are unchanged');

// ---- The screen ----
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $id];
ob_start(); ias_calendar_render(); $html = (string) ob_get_clean();
t_ok(substr_count($html, '>Save changes</button>') === 3 && strpos($html, 'Save text') === false, 'Save changes in the Edit card, the chat and Pictures — one name, one form');
t_ok(strpos($html, 'name="ias_media" id="ias-media-order"') !== false && strpos($html, 'form="ias-text-form"') !== false && strpos($html, 'id="ias-pic-form"') === false, '…the pictures saved with the words');
t_ok(strpos($html, 'class="ias-pic-alt-edit"') !== false, 'each picture\'s alt text can be changed by hand');
t_ok(strpos($html, 'Saving never approves the post.') !== false && strpos($html, 'and never approves') !== false, 'the screen says saving never approves');
t_ok(strpos($html, 'Changed the first comment') !== false && strpos($html, 'Changed the pictures and the alt text') !== false, 'the chat says what each answer changed, and offers each version back');
$_GET = ['page' => IAS_PAGE_SETTINGS];
ob_start(); ias_settings_render(); $html = (string) ob_get_clean();
t_ok(strpos($html, 'name="ias[comment_leads][results]"') !== false && strpos($html, 'placeholder="View the full listing:"') !== false, 'Settings has the first comment\'s lead-ins');

// ---- After the second UI/UX review ----
$p = ias_post_get($id);
$before = $p['flags']['edited_by'] ?? null;
ias_post_update($id, ['flags' => array_diff_key($p['flags'], ['edited_by' => 1]), 'content' => array_merge($p['content'], ['generated_by' => 'claude'])]);
$p = $save(['ias_text' => (string) $p['content']['text'], 'ias_comment' => (string) $p['content']['first_comment'], 'ias_media' => implode(',', ias_media_keys($p))]);
t_ok(empty($p['flags']['edited_by']) && $p['content']['generated_by'] === 'claude', 'Save changes with nothing changed saves nothing: the words do not become a person\'s edit');
$r5 = ias_chat_send($id, 'Make it shorter', ['text' => (string) $p['content']['text'], 'comment' => (string) $p['content']['first_comment'], 'media' => ias_media_keys($p), 'alt' => []], 'admin');
t_ok(strpos((string) $r5['html'], '<div class="ias-msg-what">Changed the text</div>') !== false && strpos((string) $r5['html'], '<del>') !== false && strpos((string) $r5['html'], '<ins>Shorter,') !== false, 'the answer shows what it changed, word by word, where the person is looking');
t_ok(strpos((string) $r5['html'], 'class="button-link ias-show" data-field="text"') !== false, '…with Show me, to the field it changed');
$r6 = ias_chat_send($id, 'Put the aerial first', ['text' => (string) $p['content']['text'], 'comment' => (string) $p['content']['first_comment'], 'media' => ias_media_keys($p), 'alt' => []], 'admin');
t_ok(substr_count((string) $r6['html'], '<img ') === count($r6['pics']) && strpos((string) $r6['html'], 'alt="Lead: ') !== false, 'a picture change shows the new pictures in the answer, the lead first');
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $id];
ob_start(); ias_calendar_render(); $html = (string) ob_get_clean();
t_ok(strpos($html, 'Not saved — not on the post now') !== false, 'a reload says which of Claude\'s versions were never saved');
t_ok(strpos($html, 'data-recheck=""') !== false, 'a planned post: nothing changed, nothing to save');
$f0 = ias_post_get($id)['flags'];
ias_post_update($id, ['status' => 'held', 'flags' => array_merge($f0, ['holds' => ['Fair housing: an old extra phrase']])]);
ob_start(); ias_calendar_render(); $held_html = (string) ob_get_clean();
t_ok(strpos($held_html, 'data-recheck="1"') !== false, 'a held post can be saved as it is, to be checked again (Codex, PR #3)');
ias_post_update($id, ['status' => 'planned', 'flags' => $f0]);
t_ok(strpos($html, '<details class="ias-switches-fold"><summary><span class="ias-switch off">Off: nothing goes to Facebook — the master switch is off.</span>') !== false, 'a post\'s page says in one line whether anything can reach Facebook, the switches folded under it');
t_ok(strpos($html, 'Throw away the words admin edited') !== false || strpos($html, 'Throw away this draft') !== false, 'Rewrite says whose words it throws away');
ias_post_update($id, ['lane' => 'A', 'status' => 'planned', 'approvals' => []]);
ob_start(); ias_calendar_render(); $html = (string) ob_get_clean();
t_ok(strpos($html, 'Lane A:') === false && strpos($html, 'This post waits for a yes from DJ or Jacob') !== false, 'the Approval card names people, not a lane and email addresses');
ias_post_update($id, ['lane' => 'B']);
$_GET = [];
$_GET = [];
restore_error_handler();
t_eq([], $errors, 'no PHP notices');

remove_all_filters('pre_wp_mail');
ias_t_claude_off();
