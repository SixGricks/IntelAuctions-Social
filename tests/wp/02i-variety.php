<?php
/**
 * 0.11.0, DJ, Oct 13, 2026: "We need more variety in the Results posts. They
 * read almost identical. We need a few other CTA's. I also just want the
 * structure to change sometimes. I am fine with 1 out of every 5 posts (or
 * something like that) being emoji heavy … sometimes paragraphs and
 * sometimes bullets." And: the check flagged a phone-bid post for saying "no
 * onsite auction" — too aggressive; positive statements are better.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';
global $wpdb;

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30]);
$GLOBALS['ias_t_claude_shapes'] = [];

// Ten sales from the week before, each with its price: ten Results posts.
$towns = ['Paradise', 'Narvon', 'Gap', 'Lititz', 'Ephrata', 'Strasburg', 'Christiana', 'Kinzers', 'Leola', 'Bird-in-Hand'];
$ids = [];
foreach ($towns as $i => $town) {
  $ids[] = $id = ias_t_listing(['title' => ($i + 10) . ' Mill Road', 'address' => ($i + 10) . ' Mill Road, ' . $town . ', PA 17562, USA', 'town' => $town, 'county' => 'Lancaster',
    'sale' => '2026-10-31 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$200,000', 'price' => (string) (300000 + $i * 1000),
    'featured' => ias_t_photo($town . ' front', [100 + $i * 10, 90, 60])]);
  ia_stage_set($id, 'past');
}
ias_sync();
$results = array_values(array_filter(ias_posts_query(['type' => 'results', 'limit' => 50]), function ($p) { return true; }));
// Each written in turn, as the hourly runs would.
foreach ($results as $p) ias_generate_post((int) $p['id']);
$results = ias_posts_query(['type' => 'results', 'limit' => 50]);
$by = function (string $key) use ($results) { return array_count_values(array_map(function ($p) use ($key) { return (string) ($p['content'][$key] ?? ''); }, $results)); };
$shapes = $by('shape');
$ctas = $by('cta_kind');
$tpls = $by('template');
t_ok(count($results) >= 8, count($results) . ' Results posts written');
t_ok(count($shapes) >= 3 && isset($shapes['paragraphs'], $shapes['bullets']), 'they take different shapes — paragraphs and bullets among them (' . implode(', ', array_map(function ($k, $n) { return "$k $n"; }, array_keys($shapes), $shapes)) . ')');
t_ok(($shapes['emoji'] ?? 0) >= 1 && ($shapes['emoji'] ?? 0) <= (int) ceil(count($results) / 4), '…the emoji-led shape about one in five');
t_ok(count($ctas) >= 5, 'their calls to action vary: ' . implode(', ', array_keys($ctas)));
t_ok((bool) array_intersect(array_keys($ctas), ['selling', 'follow', 'share', 'thanks']), '…the new ones among them');
t_ok(count($tpls) >= 4 && !array_diff(array_keys($tpls), array_keys(ias_templates_for('results'))), 'they open in different ways, from the Results openings (' . implode(', ', array_keys($tpls)) . ')');
$prompt = (string) ($GLOBALS['ias_t_claude_last']['user'] ?? '');
t_ok(strpos($prompt, 'SHAPE: ') !== false, 'the shape is in what Claude is asked');
$emoji = array_values(array_filter($results, function ($p) { return ($p['content']['shape'] ?? '') === 'emoji'; }));
if ($emoji) {
  $e = $emoji[0];
  t_ok(ias_emoji_count((string) $e['content']['text']) > 3, 'an emoji-led post carries more than three emoji');
  t_ok(!array_filter((array) ($e['flags']['holds'] ?? []), function ($h) { return stripos($h, 'emoji') !== false; }), '…and is not held for them');
  t_eq([], array_filter(ias_recheck_post($e, (string) $e['content']['text'])['holds'], function ($h) { return stripos($h, 'emoji') !== false; }), '…nor when a person saves it');
}
$bul = array_values(array_filter($results, function ($p) { return ($p['content']['shape'] ?? '') === 'bullets'; }));
if ($bul) t_ok(strpos((string) $bul[0]['content']['text'], '• ') !== false, 'a bullets post has its bullet lines');
$written = array_values(array_filter((array) ($results[0]['flags']['trail'] ?? []), function ($t) { return strpos((string) $t['what'], 'Draft written') === 0; }));
t_ok($written && strpos((string) end($written)['what'], ias_shapes()[(string) $results[0]['content']['shape']][0]) !== false, 'the history says which shape it was written in (' . (string) (end($written)['what'] ?? '') . ')');
$shape_hist = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . ias_table('history') . " WHERE kind = 'shape' AND value LIKE 'results:%'");
t_eq(count($results), $shape_hist, 'each post\'s shape is remembered, by post type, for the next one');

// A phone-bid post that says "no onsite auction" is not held for it.
$ph = ias_t_listing(['title' => '5 Phone Lane', 'address' => '5 Phone Lane, Gap, PA 17527, USA', 'town' => 'Gap', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['phone']], 'auction_type' => 'Phone Bid', 'bid' => '$200,000', 'gallery' => [ias_t_photo('Phone 1'), ias_t_photo('Phone 2')]]);
ias_sync(); ias_drafts_run(30);
$pp = ias_post_by_slot('listing:' . $ph);
$chk = ias_recheck_post($pp, "5 Phone Lane in Gap sells November 28 by Intel Phone Bidding — no onsite auction. Starting bid \$200,000.");
t_eq([], array_values(array_filter($chk['holds'], function ($h) { return stripos($h, 'in-person') !== false || stripos($h, 'choose') !== false; })), 'a phone-bid post saying "no onsite auction" is not held as mentioning in-person bidding');
t_ok(strpos((string) ($GLOBALS['ias_t_claude_last']['system'] ?? ''), 'Never say what a sale is not') !== false, 'and Claude is told to say how bidding works in positive words');

// Talk it over keeps the shape the post was written in.
if ($bul) {
  $chat_fl = ias_post_facts($bul[0]);
  [$sys] = ias_chat_prompt($bul[0], $chat_fl, null, [], 'Make it warmer.', 'Jacob');
  t_ok(strpos($sys, 'SHAPE: this post is written as "Bullets"') !== false && strpos($sys, 'Keep that shape unless Jacob asks for another') !== false, 'Talk it over is told the post is written in bullets, and to keep them');
}
$old = $results[0]; unset($old['content']['shape']);
[$sys_old] = ias_chat_prompt($old, ias_post_facts($old), null, [], 'Shorter.', 'Jacob');
t_ok(strpos($sys_old, 'SHAPE:') === false, '…and a post written before shapes is given none');

// ---- 0.15.0: only a phone-bid sale must say how bidding works; no open house: sometimes "Call for a private showing" ----
$lpost = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$prompt_for = function (array $p) { [$sys, $user] = ias_prompt($p, ias_post_facts($p), (array) ($p['content']['media'] ?? []), 'place', 'call_lead', [], 'paragraphs'); return [$sys, $user]; };
$inp = ias_t_listing(['title' => '9 Field Lane', 'address' => '9 Field Lane, Gap, PA 17527, USA', 'town' => 'Gap', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-27 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'gallery' => [ias_t_photo('Field 1'), ias_t_photo('Field 2'), ias_t_photo('Field 3')]]);
ias_sync(); ias_drafts_run(30);
$ip = $lpost($inp);
[$sys, $user] = $prompt_for($ip);
t_ok(strpos($sys, 'An in-person auction is the usual kind: it need not be said at all') !== false, 'Claude is told an in-person auction need not be said');
$brief = ias_type_brief($ip, ias_post_facts($ip));
t_ok(strpos($brief, 'an in-person auction need not be said') !== false && strpos($brief, 'how bidding works') === false, '…and the Listing brief asks only whether it is Intel Phone Bidding');
t_ok(strpos($user, 'need not say how bidding works') !== false, '…the facts say so too');
$chk = ias_recheck_post($ip, "9 Field Lane in Gap sells Friday, November 27 at 10:00 am. Starting bid \$300,000.\n\nCall the office.");
t_eq([], array_values(array_filter($chk['holds'], function ($h) { return stripos($h, 'bidding') !== false || stripos($h, 'in-person') !== false; })), 'an in-person post that never says "in-person" is not held');
$chk = ias_recheck_post($ip, "9 Field Lane in Gap sells November 27. Bid by phone through Intel Phone Bidding.\n\nCall the office.");
t_ok((bool) array_filter($chk['holds'], function ($h) { return strpos($h, 'but this is an in-person auction') !== false; }), '…but one that says phone bidding is');
$pp = $lpost($ph);
[, $puser] = $prompt_for($pp);
t_ok(strpos($puser, 'Intel Phone Bidding — buyers bid by phone with Intel (the post must say so, once)') !== false, 'a phone-bid sale\'s facts say the post must say so');
// No open house: about half the posts say "Call for a private showing", each the same way every time.
t_ok(strpos($user, ias_says_showing((string) $ip['slot_key']) ? 'say "Call for a private showing"' : 'leave showings out of this post') !== false, 'no open house set: the Listing brief says whether this post mentions a private showing (' . (ias_says_showing((string) $ip['slot_key']) ? 'it does' : 'it does not') . ')');
$withoh = ias_t_listing(['title' => '2 Open Way', 'address' => '2 Open Way, Gap, PA 17527, USA', 'town' => 'Gap', 'county' => 'Chester',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000',
  'gallery' => [ias_t_photo('Open 1'), ias_t_photo('Open 2'), ias_t_photo('Open 3')], 'oh' => [['2026-11-21 13:00', 2]]]);
ias_sync(); ias_drafts_run(30);
[, $ouser] = $prompt_for($lpost($withoh));
t_ok(strpos($ouser, 'private showing') === false && strpos($ouser, 'leave showings out') === false && strpos($ouser, 'the open houses still ahead with their times') !== false, 'with an open house set: the open house, and nothing about showings');
$pastoh = ias_t_listing(['title' => '4 Past Way', 'address' => '4 Past Way, Gap, PA 17527, USA', 'town' => 'Gap', 'county' => 'Chester',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000',
  'gallery' => [ias_t_photo('Past 1'), ias_t_photo('Past 2'), ias_t_photo('Past 3')], 'oh' => [['2026-10-31 13:00', 2]]]);
ias_sync(); ias_drafts_run(30);
$pb = ias_type_brief($lpost($pastoh), ias_post_facts($lpost($pastoh)));
t_ok(count((array) (ias_post_facts($lpost($pastoh))[0]['open_houses'] ?? [])) === 1 && strpos($pb, 'the open houses with their times') === false && (strpos($pb, 'private showing') !== false || strpos($pb, 'leave showings out') !== false), 'an open house already past: no open houses asked for, and the no-open-house line instead');
