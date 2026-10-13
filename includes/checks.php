<?php
/**
 * Intel Auctions Social — what every draft is checked for before it can go
 * anywhere. Pure (no WordPress), so tests/unit/ runs it.
 *
 * A "hold" stops the post for a person (status Needs a decision); a "fix" is
 * applied to the text silently because there is only one right answer:
 *
 *   hold  [VERIFY] markers — Claude was missing a fact it needed
 *   hold  bidding — a post that must say how bidding works says it wrongly,
 *         says both (buyers would think they can choose), says neither, or
 *         says "online"; or the listing itself does not say
 *   hold  a dollar figure or a phone number that is not on the listing
 *   hold  a fair-housing phrase (fairhousing.php)
 *   hold  more than three emoji (no emoji walls)
 *   fix   a web address in the text — removed (the link goes in the first comment)
 *   fix   stacked exclamation points — one is plenty
 */

if (!defined('ABSPATH')) exit;

/** Whether $text says bidding is by phone, in person, and/or online. */
if (!function_exists('ias_bidding_mentions')) {
  function ias_bidding_mentions(string $text): array {
    return [
      'phone'     => (bool) preg_match('/\b(?:intel\s+)?phone[\s-]bid(?:s|ding)?\b|\bbid(?:ding)?\s+(?:by|over\s+the)\s+phone\b|\bphone\s+in\s+(?:your\s+)?bids?\b/i', $text),
      'in_person' => (bool) preg_match('/\bin[\s-]person\s+(?:auction|bidding|sale)\b|\bbid(?:ding)?\s+in\s+person\b|\bon[\s-]site\s+(?:auction|bidding|sale)\b|\blive\s+(?:auction|bidding)\b|\bbid(?:ding)?\s+on[\s-]site\b/i', $text),
      'online'    => (bool) preg_match('/\b(?:online|internet|web(?:cast)?)\s+(?:bid|bidding|auction)\b|\bbid(?:ding)?\s+online\b/i', $text),
    ];
  }
}

/** Dollar amounts in $text as numbers ("$425,000" → 425000.0, "$1.2 million" → 1200000.0). */
if (!function_exists('ias_dollar_amounts')) {
  function ias_dollar_amounts(string $text): array {
    $out = [];
    if (preg_match_all('/\$\s?(\d[\d,]*(?:\.\d+)?)\s*(k|m|million|thousand)?\b/i', $text, $m, PREG_SET_ORDER)) {
      foreach ($m as $hit) {
        $v = (float) str_replace(',', '', $hit[1]);
        $unit = strtolower($hit[2] ?? '');
        if ($unit === 'k' || $unit === 'thousand') $v *= 1000;
        if ($unit === 'm' || $unit === 'million') $v *= 1000000;
        $out[] = ['text' => trim($hit[0]), 'value' => $v];
      }
    }
    return $out;
  }
}

/** Phone numbers in $text as their last ten digits. */
if (!function_exists('ias_phone_numbers')) {
  function ias_phone_numbers(string $text): array {
    $out = [];
    if (preg_match_all('/(?:\+?1[\s.-]?)?\(?\b(\d{3})\)?[\s.-]?(\d{3})[\s.-]?(\d{4})\b/', $text, $m, PREG_SET_ORDER)) {
      foreach ($m as $hit) $out[] = ['text' => trim($hit[0]), 'digits' => $hit[1] . $hit[2] . $hit[3]];
    }
    return $out;
  }
}

if (!function_exists('ias_digits10')) {
  function ias_digits10(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone);
    return strlen($d) >= 10 ? substr($d, -10) : $d;
  }
}

/** Emoji (pictographs) in $text. */
if (!function_exists('ias_emoji_count')) {
  function ias_emoji_count(string $text): int {
    return (int) preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F000}-\x{1F2FF}]/u', $text);
  }
}

/**
 * Does this post still carry a [VERIFY: …] marker, in its text or its first
 * comment? Such a post never goes anywhere: Keep as written is refused and
 * push readiness says no, whatever else let it through. The marker is Claude
 * saying a fact is missing; the cure is the fact, typed in or fixed on the
 * listing.
 */
if (!function_exists('ias_has_verify')) {
  function ias_has_verify(array $content): bool {
    return (bool) preg_match('/\[VERIFY[^\]]*\]/i', (string) ($content['text'] ?? '') . "\n" . (string) ($content['first_comment'] ?? ''));
  }
}

/**
 * Check one draft. $ctx:
 *   type        post type
 *   bidding     the bidding type of each listing in the post ('phone'|'in_person'|'')
 *   money       dollar values the listing(s) state (starting bids, sale prices, parcel bids)
 *   phones      phone numbers the post may give (the lead's, the office)
 *   fair_extra  extra fair-housing phrases from Settings
 * Returns ['text' => fixed text, 'holds' => [reason, …], 'fixes' => [what was changed, …]].
 */
if (!function_exists('ias_check_draft')) {
  function ias_check_draft(string $text, array $ctx): array {
    $holds = [];
    $fixes = [];
    $type = (string) ($ctx['type'] ?? '');

    // Fixes first, so the holds read the text as it will go out.
    $no_links = preg_replace('~\s*\(?(?:https?://|www\.)[^\s)]+\)?~i', '', $text);
    if ($no_links !== $text) { $text = trim((string) $no_links); $fixes[] = 'Removed a web address from the text — the link goes in the first comment.'; }
    $calm = preg_replace('/!{2,}/', '!', $text);
    if ($calm !== $text) { $text = (string) $calm; $fixes[] = 'Turned stacked exclamation points into one.'; }

    if (preg_match_all('/\[VERIFY[^\]]*\]/i', $text, $m)) {
      $holds[] = 'Claude marked something to check: ' . implode(' ', array_unique($m[0]));
    }

    $said = ias_bidding_mentions($text);
    if ($said['online']) $holds[] = 'It mentions online bidding. Intel does not take bids online.';
    $types = array_values(array_unique(array_map('strval', (array) ($ctx['bidding'] ?? []))));
    $must_say = in_array($type, ['listing', 'open_house', 'auction_week'], true);
    if ($must_say) {
      if (in_array('', $types, true) || !$types) {
        $holds[] = 'The listing does not say whether bidding is in person or by Intel Phone Bidding, and this post has to.';
      } elseif (count($types) === 1) {
        $want = $types[0];
        $other = $want === 'phone' ? 'in_person' : 'phone';
        if (!$said[$want]) $holds[] = 'It does not say this is ' . ($want === 'phone' ? 'Intel Phone Bidding' : 'an in-person auction') . '.';
        if ($said[$other]) $holds[] = 'It mentions ' . ($other === 'phone' ? 'phone bidding' : 'in-person bidding') . ' too — buyers would think they can choose.';
      } else {
        foreach ($types as $want) {
          if (!$said[$want]) $holds[] = 'One of these sales is ' . ($want === 'phone' ? 'Intel Phone Bidding' : 'an in-person auction') . ' and the post does not say so.';
        }
      }
    }

    $allowed = array_map('floatval', (array) ($ctx['money'] ?? []));
    foreach (ias_dollar_amounts($text) as $amt) {
      $ok = false;
      foreach ($allowed as $v) if (abs($v - $amt['value']) < 0.5) { $ok = true; break; }
      if (!$ok) $holds[] = 'It gives a dollar figure that is not on the listing: ' . $amt['text'] . '.';
    }

    $phones = array_map('ias_digits10', (array) ($ctx['phones'] ?? []));
    foreach (ias_phone_numbers($text) as $ph) {
      if (!in_array($ph['digits'], $phones, true)) $holds[] = 'It gives a phone number that is not the lead\'s or the office\'s: ' . $ph['text'] . '.';
    }

    foreach (ias_fair_housing_check($text, (string) ($ctx['fair_extra'] ?? '')) as $hit) {
      $holds[] = 'Fair housing: "' . $hit['phrase'] . '" ' . $hit['why'] . '.';
    }

    if (ias_emoji_count($text) > 3) $holds[] = 'More than three emoji — no emoji walls.';

    return ['text' => $text, 'holds' => array_values(array_unique($holds)), 'fixes' => $fixes];
  }
}
