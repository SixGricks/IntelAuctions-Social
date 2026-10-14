<?php
/**
 * No open house set: "Call for a private showing" — but not every time (DJ,
 * Oct 14, 2026: "If no open house date is given then it's 'Call for private
 * showing' but we don't always need to say that.").
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/content.php';

$yes = 0;
for ($i = 1; $i <= 200; $i++) if (ias_says_showing('listing:' . $i)) $yes++;
t_ok($yes >= 70 && $yes <= 130, 'about half the posts with no open house say it (' . $yes . ' of 200)');
t_eq(ias_says_showing('listing:42'), ias_says_showing('listing:42'), 'a post makes the same choice every time it is written');

$f = ['kind' => 'real_estate', 'open_houses' => []];
$say = $skip = '';
for ($i = 1; $i <= 20 && ($say === '' || $skip === ''); $i++) {
  $b = ias_showing_brief(['type' => 'listing', 'slot_key' => 'listing:' . $i], $f);
  if (ias_says_showing('listing:' . $i)) $say = $b; else $skip = $b;
}
t_ok(strpos($say, 'say "Call for a private showing"') !== false && strpos($say, 'Never invent an open house') !== false, 'one that says it is told to, with the lead\'s or office\'s number');
t_ok(strpos($skip, 'leave showings out of this post') !== false && strpos($skip, 'never invent an open house') !== false, 'one that does not is told to leave it out');
t_eq('', ias_showing_brief(['type' => 'listing', 'slot_key' => 'listing:1'], ['kind' => 'real_estate', 'open_houses' => [['start' => new DateTimeImmutable('+5 days')]]]), 'with an open house ahead, nothing about showings');
t_ok(ias_showing_brief(['type' => 'auction_week', 'slot_key' => 'auction_week:1'], ['kind' => 'real_estate', 'open_houses' => [['start' => new DateTimeImmutable('-5 days')]]]) !== '', 'an open house already past counts as none');
t_eq('', ias_showing_brief(['type' => 'listing', 'slot_key' => 'listing:1'], ['kind' => 'liquidation', 'open_houses' => []]), 'a liquidation (contents sale): nothing about showings');
t_eq('', ias_showing_brief(['type' => 'results', 'slot_key' => 'results:1'], $f), 'a Results post: nothing about showings');
t_ok(ias_open_house_ahead(['open_houses' => [['start' => new DateTimeImmutable('+1 day')]]]) && !ias_open_house_ahead(['open_houses' => [['start' => new DateTimeImmutable('-1 day')]]]) && !ias_open_house_ahead([]), 'an open house is ahead only when its start has not passed');
