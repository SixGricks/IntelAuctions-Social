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
t_ok(count($tpls) >= 4 && !array_diff(array_keys($tpls), array_keys(ias_templates_results())), 'they open in different ways, from the Results openings (' . implode(', ', array_keys($tpls)) . ')');
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
t_ok(strpos((string) ($GLOBALS['ias_t_claude_last']['system'] ?? ''), 'Never say what it is not') !== false, 'and Claude is told to say how bidding works in positive words');

// Talk it over keeps the shape the post was written in.
if ($bul) {
  $chat_fl = ias_post_facts($bul[0]);
  [$sys] = ias_chat_prompt($bul[0], $chat_fl, null, [], 'Make it warmer.', 'Jacob');
  t_ok(strpos($sys, 'SHAPE: this post is written as "Bullets"') !== false && strpos($sys, 'Keep that shape unless Jacob asks for another') !== false, 'Talk it over is told the post is written in bullets, and to keep them');
}
$old = $results[0]; unset($old['content']['shape']);
[$sys_old] = ias_chat_prompt($old, ias_post_facts($old), null, [], 'Shorter.', 'Jacob');
t_ok(strpos($sys_old, 'SHAPE:') === false, '…and a post written before shapes is given none');
