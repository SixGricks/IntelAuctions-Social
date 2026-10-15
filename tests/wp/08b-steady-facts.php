<?php
/**
 * 0.23.0, DJ, Oct 15, 2026: "I keep having posts that i personally rewrite and
 * approve or just save and then a few minutes later they go from green to blue
 * with the note: The listing changed since this draft was written".
 *
 * The fingerprint a draft is checked against was a hash of the prompt's facts,
 * whose description came from get_the_excerpt() — the page as every plugin's
 * content filters print it, which differs between a person's request and the
 * hourly run. Now it is the listing's plain values, field by field (the
 * description as stored), so: nothing a filter prints is a change; the update
 * re-stamps every post once instead of calling them all changed; a real change
 * is named; a post a person approved stops for them instead of being rewritten.
 * And: a person's emoji are theirs; an unstated auction type is in-person; a
 * Results post never names the sellers; the log downloads as a CSV.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30, 'approvers' => 'dj@intelauctions.com']);
add_filter('pre_wp_mail', function () { return true; });
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $_POST = [];
};
$photos = function (string $name) { $ids = []; for ($i = 1; $i <= 3; $i++) $ids[] = ias_t_photo($name . ' ' . $i); return $ids; };

$a = ias_t_listing(['title' => '40 Steady Lane', 'address' => '40 Steady Lane, Strasburg, PA 17579, USA', 'town' => 'Strasburg', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000',
  'content' => '<p>A productive farm with a bank barn.</p>[ia_open_house] <!-- wp:paragraph -->', 'gallery' => $photos('Steady'),
  'oh' => [['2026-11-14 13:00', 2]], 'sellers' => 'John and Mary Hershberger']);
ias_job_run();
$lp = ias_post_by_slot('listing:' . $a);
t_ok($lp && !empty($lp['content']['text']) && !empty($lp['flags']['facts_sig']), 'a draft carries its fingerprint field by field (flags.facts_sig)');
t_eq('A productive farm with a bank barn.', ias_listing_facts($a)['excerpt'], 'the description is the listing as stored: markup, comments and shortcodes taken out');

// ---- 1. What a page's filters print is never "the listing changed" ----
$noise = function ($x) { return $x . ' ' . wp_generate_password(8, false) . ' (as printed for ' . get_current_user_id() . ')'; };
add_filter('the_content', $noise);
add_filter('get_the_excerpt', $noise);
add_filter('the_excerpt', $noise);
foreach ([0, 1] as $user) { wp_set_current_user($user); ias_sync(); } // the hourly run, then a person's own request
wp_set_current_user(0);
remove_filter('the_content', $noise); remove_filter('get_the_excerpt', $noise); remove_filter('the_excerpt', $noise);
$lp = ias_post_get((int) $lp['id']);
t_ok(empty($lp['flags']['stale']) && $lp['status'] !== 'held', 'content filters printing something different each time (and for each person): the draft stays as it is — no blue, no rewrite');

// ---- 2. The update re-stamps every post once, rather than calling them all changed ----
$wpdb->update(ias_table('posts'), ['facts_hash' => md5('the old scheme')], ['id' => $lp['id']]);
$old_flags = $lp['flags']; unset($old_flags['facts_sig']);
ias_post_update((int) $lp['id'], ['flags' => $old_flags]);
update_option('ias_facts_sig_v', 1, false);
ias_sync();
$lp = ias_post_get((int) $lp['id']);
t_ok(empty($lp['flags']['stale']) && !empty($lp['flags']['facts_sig']) && (int) get_option('ias_facts_sig_v') === IAS_FACTS_SIG_V, 'a fingerprint from before 0.23.0: re-stamped as it stands on the first run, never taken for a changed listing');

// ---- 3. A real change is named ----
update_post_meta($a, 'open_house', '2026-11-15 13:00:00');
ias_sync();
$lp = ias_post_get((int) $lp['id']);
t_ok(!empty($lp['flags']['stale']) && in_array('open houses', (array) $lp['flags']['stale_what'], true), 'an open house moved: the draft is stale, and says it was the open houses (' . implode(', ', (array) ($lp['flags']['stale_what'] ?? [])) . ')');
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $lp['id']];
ob_start(); ias_calendar_render_post((int) $lp['id']); $page = (string) ob_get_clean(); $_GET = [];
t_ok(strpos($page, 'open houses</strong> changed since this draft was written') !== false, '…and the post page names it');

// ---- 4. A post a person approved stops for them; it is never rewritten under them ----
ias_generate_post((int) $lp['id'], true, 'admin');
$lp = ias_post_get((int) $lp['id']);
ias_post_update((int) $lp['id'], ['status' => 'approved', 'approved_at' => ias_now_sql(), 'approved_by' => 'dj@intelauctions.com']);
$before = (string) $lp['content']['text'];
update_post_meta($a, 'Starting_Bid', '$325,000');
ias_sync();
$lp = ias_post_get((int) $lp['id']);
t_ok($lp['status'] === 'held' && strpos((string) $lp['note'], "The listing's starting bid changed after this post was approved") !== false, 'approved, then the starting bid changed: held for the person, naming what changed (' . $lp['note'] . ')');
ias_job_run();
t_eq($before, (string) ias_post_get((int) $lp['id'])['content']['text'], '…and the hourly run does not rewrite it');

// ---- 5. A person's emoji are their own; Claude's drafts keep the cap ----
$text = "🏡 40 Steady Lane, Strasburg 🌾 sells November 28 📅 at 10:00 am ⏰. Open house November 15 🚪. Call the office 📞.";
$act('edit', ['ias_post' => $lp['id'], 'ias_text' => $text]);
$lp = ias_post_get((int) $lp['id']);
t_ok(!array_filter((array) $lp['flags']['holds'], function ($h) { return stripos($h, 'emoji') !== false; }), 'seven emoji in a person\'s own words: no emoji hold — their choice (holds: ' . implode(' | ', (array) $lp['flags']['holds']) . ')');
t_ok((bool) array_filter(ias_check_draft($text, ['type' => 'listing', 'bidding' => ['in_person'], 'emoji_max' => 3])['holds'], function ($h) { return stripos($h, 'emoji') !== false; }), '…the same words from Claude are still held');

// ---- 6. An auction type nobody set is in-person, unless the listing says phone bidding ----
$b = ias_t_listing(['title' => '41 Plain Road', 'address' => '41 Plain Road, Strasburg, PA 17579, USA', 'town' => 'Strasburg', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-30 10:00', 'terms' => [$T['farm']], 'gallery' => $photos('Plain')]);
t_eq('in_person', ias_listing_facts($b)['bidding'], 'no auction type set: an in-person auction, the usual kind');
t_eq([], array_values(array_filter(ias_check_draft('41 Plain Road sells November 30.', ['type' => 'listing', 'bidding' => [ias_listing_facts($b)['bidding']]])['holds'], function ($h) { return stripos($h, 'bidding') !== false || stripos($h, 'in person') !== false; })), '…so its posts are never held for not saying how bidding works');
$c = ias_t_listing(['title' => '42 Ring Road — Phone Bid Auction', 'address' => '42 Ring Road, Strasburg, PA 17579, USA', 'town' => 'Strasburg', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-12-01 10:00', 'terms' => [$T['farm']], 'gallery' => $photos('Ring')]);
t_eq('phone', ias_listing_facts($c)['bidding'], 'no auction type set, but the listing says phone bid: Intel Phone Bidding');
$d = ias_t_listing(['title' => '44 Floor Road', 'address' => '44 Floor Road, Strasburg, PA 17579, USA', 'town' => 'Strasburg', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-12-02 10:00', 'terms' => [$T['farm']], 'content' => 'Sold on site from the auction floor. No phone bids accepted.']);
t_eq('in_person', ias_listing_facts($d)['bidding'], 'the listing says "No phone bids accepted": in person, never Intel Phone Bidding (0.24.1)');

// ---- 7. A Results post never names the sellers ----
$s = ias_t_listing(['title' => '43 Sold Lane', 'address' => '43 Sold Lane, Strasburg, PA 17579, USA', 'town' => 'Strasburg', 'county' => 'Lancaster',
  'sale' => '2026-10-31 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'price' => '412000', 'featured' => ias_t_photo('Sold 43'),
  'sellers' => 'Amos and Ruth Zimmerman']);
ia_stage_set($s, 'past');
ias_sync();
$rp = ias_post_by_slot('results:2026-10-31:strasburg');
$rf = ias_post_facts($rp);
[, $user] = ias_prompt($rp, $rf, [], 'r_thanks', 'thanks', [], 'paragraphs'); // [system, user]
t_ok(strpos($user, 'Zimmerman') === false && strpos($user, 'never by name') !== false, 'the Results prompt carries no seller, and says to thank them never by name');
$lp2 = ias_post_by_slot('listing:' . $a);
[, $luser] = ias_prompt($lp2, ias_post_facts($lp2), [], 'place', 'call_lead', [], 'paragraphs');
t_ok(strpos($luser, 'Hershberger') !== false, '…a Listing post\'s prompt still has them');
ias_drafts_run(30);
$rp = ias_post_by_slot('results:2026-10-31:strasburg');
$act('edit', ['ias_post' => $rp['id'], 'ias_text' => "43 Sold Lane, Strasburg sold for \$412,000. Thank you, Amos and Ruth Zimmerman, for trusting us."]);
$rp = ias_post_get((int) $rp['id']);
t_ok($rp['status'] === 'held' && (bool) array_filter((array) $rp['flags']['holds'], function ($h) { return strpos($h, 'It names the seller') === 0; }), 'a Results post that names the sellers stops for a person');

// ---- 8. The log downloads as a CSV ----
ias_log('edited', $a, ['post' => (int) $lp['id'], 'type' => 'listing', 'summary' => '=HYPERLINK("x") a summary', 'payload' => ['k' => 'v']], 'admin');
$rows = ias_log_export_rows('');
t_ok(count($rows) > 10 && count($rows) > count(ias_log_recent('', 5)), 'the export reads the whole log, not one page (' . count($rows) . ' entries)');
$row = ias_log_csv_row($rows[0], ias_log_events());
t_eq(count(ias_log_csv_head()), count($row), 'every row has every column');
t_ok($row[2] === (string) $a && $row[3] === '40 Steady Lane' && $row[4] === (string) $lp['id'] && strpos($row[8], '"k":"v"') !== false, 'when, what, the listing, the post, the details, the payload and who');
t_eq("'=HYPERLINK(\"x\") a summary", ias_csv_safe($row[6]), 'a cell that would run as a formula is made plain text');
t_eq('plain words', ias_csv_safe('plain words'), '…other cells are as they are');
t_ok(count(ias_log_export_rows('edited')) < count($rows), 'the filter narrows it as on the page');
