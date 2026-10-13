<?php
/**
 * Intel Auctions Social — the fair-housing screen.
 *
 * Every draft is read for language that describes who should buy or live
 * there rather than the property — "perfect for families", "safe
 * neighborhood", "adults only", a religion or a nationality, ability. A hit
 * stops the post for a person ("Needs a decision"); nothing is rewritten
 * silently. The prompts forbid the same things, so a hit means Claude slipped
 * or a person typed it. Settings add phrases of your own, one per line.
 *
 * The list leans towards asking: describing the property (a ramp, a
 * first-floor bedroom, a white barn, Amish-built) is never flagged; who it
 * suits, who the neighbours are and how safe the area is are.
 */

if (!defined('ABSPATH')) exit;

/** [regex, why] pairs. Case-insensitive, matched against the whole post. */
if (!function_exists('ias_fair_housing_rules')) {
  function ias_fair_housing_rules(): array {
    $who = '(?:a\s+)?(?:young\s+|growing\s+|large\s+|small\s+|new\s+|retired\s+|older\s+)?(?:famil(?:y|ies)|kids|children|couples?|singles?|retirees|seniors|empty[\s-]nesters|newlyweds|bachelors?|students|young\s+professionals|professionals|christians?|catholics?)';
    return (array) apply_filters('ias_fair_housing_rules', [
      ['/\b(?:perfect|ideal|great|wonderful|excellent|made|built|suited|well[\s-]suited)\s+for\s+' . $who . '\b/i', 'says who the property suits (familial status, age or religion)'],
      ['/\bfamily[\s-](?:friendly|oriented|neighbou?rhood|area|community)\b/i', 'describes the neighbourhood by familial status'],
      ['/\b(?:safe|secure|crime[\s-]free|low[\s-]crime|exclusive|restricted|integrated|segregated)\s+(?:neighbou?rhood|community|area|street|town|location|setting)\b/i', 'describes how safe or exclusive the area is'],
      ['/\b(?:no|without)\s+(?:kids|children|families)\b/i', 'excludes families with children'],
      ['/\badults?[\s-]only\b/i', 'excludes families with children'],
      ['/\bmature\s+(?:person|persons|couple|couples|adults?|individuals?|buyers?)\b/i', 'states an age preference'],
      ['/\b(?:single|one)\s+(?:person|professional)\s+only\b/i', 'states a household preference'],
      ['/\b(?:able[\s-]bodied|physically\s+fit|no\s+wheelchairs?|not\s+(?:suitable|suited)\s+for\s+(?:the\s+)?(?:handicapped|disabled))\b/i', 'states a disability preference'],
      ['/\b(?:christian|catholic|jewish|muslim|amish|mennonite|plain|church[\s-]going)\s+(?:famil(?:y|ies)|community|neighbou?rs?|neighbou?rhood|buyers?|owners?)\b/i', 'describes buyers or neighbours by religion'],
      ['/\b(?:walking\s+distance|close|near|steps|minutes)\s+(?:to|from)\s+(?:the\s+|a\s+)?(?:church|churches|synagogue|mosque|temple|meeting\s*house|parochial\s+school)\b/i', 'points to a house of worship (can read as a religious preference — say what it is near some other way, or keep it if it is a landmark)'],
      ['/\b(?:hispanic|latino|latina|asian|caucasian|ethnic|english[\s-]speaking|immigrants?|foreigners?)\b/i', 'mentions race, ethnicity or national origin'],
      ['/\b(?:bachelor\s+pad|man\s+cave|mother[\s-]in[\s-]law\s+(?:suite|quarters|apartment))\b/i', 'describes the space by sex or family role (say "in-law suite" or "second living space")'],
      ['/\b(?:his\s+and\s+hers|perfect\s+for\s+(?:him|her))\b/i', 'states a sex preference'],
    ]);
  }
}

/**
 * The hits in $text: [['phrase' => what matched, 'why' => …], …] ([] = clean).
 * Extra phrases from Settings match as plain words, case-insensitive.
 */
if (!function_exists('ias_fair_housing_check')) {
  function ias_fair_housing_check(string $text, string $extra = ''): array {
    $hits = [];
    foreach (ias_fair_housing_rules() as [$re, $why]) {
      if (preg_match_all($re, $text, $m)) {
        foreach (array_unique($m[0]) as $phrase) $hits[] = ['phrase' => trim($phrase), 'why' => $why];
      }
    }
    foreach (preg_split('/\r\n|\r|\n/', $extra) as $phrase) {
      $phrase = trim($phrase);
      if ($phrase === '') continue;
      if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text, $m)) {
        $hits[] = ['phrase' => $m[0], 'why' => 'on the extra phrase list in Settings'];
      }
    }
    return $hits;
  }
}
