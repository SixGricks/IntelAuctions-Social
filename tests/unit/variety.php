<?php
/**
 * Variety (DJ, Oct 13, 2026): "We need more variety in the Results posts …
 * a few other CTA's … the structure to change sometimes … 1 out of every 5
 * posts (or something like that) being emoji heavy … sometimes paragraphs and
 * sometimes bullets." The shape rotation, the Results openings and the calls
 * to action, without WordPress.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/content.php';

$weights = function (string $type) {
  $w = [];
  foreach (ias_shapes() as $k => $def) if (in_array($type, (array) $def[3], true)) $w[$k] = (float) $def[2];
  return $w;
};
foreach (['results' => 100, 'listing' => 100, 'roundup' => 50] as $type => $n) {
  $w = $weights($type);
  $recent = []; $last = ''; $seq = []; $repeats = 0;
  for ($i = 0; $i < $n; $i++) {
    $k = ias_choose_shape($w, $recent, $type === 'roundup' ? '' : $last); // a roundup is no one property's: no "not last time's" rule
    if ($k === $last && $type !== 'roundup') $repeats++;
    $recent[$k] = ($recent[$k] ?? 0) + 1;
    $last = $k;
    $seq[] = $k;
  }
  $share = ($recent['emoji'] ?? 0) / $n;
  t_ok($share >= 0.15 && $share <= 0.25, "{$type}: emoji-led about one post in five (" . round($share * 100) . '%)');
  if ($type !== 'roundup') t_eq(0, $repeats, "{$type}: never the same shape twice in a row for a property");
  if ($type !== 'roundup') t_ok(($recent['paragraphs'] ?? 0) > 0 && ($recent['bullets'] ?? 0) > 0, "{$type}: sometimes paragraphs, sometimes bullets (" . implode(', ', array_map(function ($k, $c) { return $k . ' ' . $c; }, array_keys($recent), $recent)) . ')');
}
t_ok(!array_key_exists('short', $weights('listing')), 'a Listing post is never cut short: it has the date, bidding, open houses and the lead to give');
t_ok(array_key_exists('short', $weights('results')), 'a Results post can be short');
t_ok(strpos(ias_shapes()['bullets'][1], '• ') !== false && strpos(ias_shapes()['emoji'][1], 'Up to 8 emoji') !== false, 'the shapes tell Claude what to write');
t_eq(8, ias_emoji_max('emoji'), 'an emoji-led post may carry 8 emoji');
t_eq(3, ias_emoji_max('bullets'), '…any other, 3');

// Results: their own openings, and many more calls to action.
t_eq(5, count(ias_templates_results()), 'a Results post rotates through five openings of its own');
t_eq('Thanks first', ias_template_label('r_thanks'), '…with labels for the screens');
t_eq('Place first', ias_template_label('place'), '…and the other posts\' openings keep theirs');
$results_ctas = array_keys(array_filter(ias_cta_kinds(), function ($def) { return in_array('results', (array) $def[2], true); }));
t_ok(count($results_ctas) >= 6, 'a Results post has ' . count($results_ctas) . ' calls to action to rotate through (' . implode(', ', $results_ctas) . ')');
foreach (['selling', 'follow', 'save_date', 'share', 'thanks'] as $k) t_ok(isset(ias_cta_kinds()[$k]), "a new call to action: {$k}");

// The rules: bidding in positive words.
$rules = ias_prompt_rules();
t_ok(strpos($rules, 'positive words') !== false && strpos($rules, 'Never say what it is not') !== false && strpos($rules, 'no onsite auction') !== false, 'Claude is told to say how bidding works in positive words, never what it is not');
t_ok(strpos($rules, 'unless the SHAPE asks for emoji-led lines') !== false, '…and that the emoji-led shape may carry emoji');
