<?php
/**
 * Intel Auctions Social — what every draft is checked for before it can go
 * anywhere. Pure (no WordPress), so tests/unit/ runs it.
 *
 * A "hold" stops the post for a person (status Needs a decision); a "fix" is
 * applied to the text silently because there is only one right answer:
 *
 *   hold  [VERIFY] markers — Claude was missing a fact it needed
 *   hold  bidding — a phone-bid sale's post does not say it is Intel Phone
 *         Bidding, or says in-person too (buyers would think they can
 *         choose); an in-person sale's post mentions phone bidding; any post
 *         says "online"; or the listing itself does not say which it is. An
 *         in-person auction is the usual kind and need not be said (DJ, Oct
 *         14, 2026: "We only need to say when it's a phone bid auction.")
 *   hold  a dollar figure or a phone number that is not on the listing
 *   hold  a fair-housing phrase (fairhousing.php)
 *   hold  more than three emoji (no emoji walls) — eight for an emoji-led post; Claude's drafts only,
 *         never a person's own words (ias_recheck_post())
 *   (a bidding way the sentence denies — "no onsite auction" — is no mention of it)
 *   hold  a Results post that names a seller (DJ, Oct 15, 2026: sellers do not want their name beside
 *         what a place sold for) — the sellers' names, and each surname, unless it is also the place's own name
 *   fix   a web address in the text — removed (the link goes in the first comment)
 *   fix   stacked exclamation points — one is plenty
 */

if (!defined('ABSPATH')) exit;

/**
 * Whether $text says bidding is by phone, in person, and/or online — as what
 * the sale IS. A mention the sentence denies ("no onsite auction", "not an
 * in-person sale", "no online bidding") says nothing about how it works, so it
 * does not count (DJ, Oct 13, 2026: a phone-bid post saying "no onsite
 * auction" was held as mentioning in-person bidding — too aggressive). The
 * prompts ask for positive words anyway.
 */
if (!function_exists('ias_bidding_mentions')) {
  function ias_bidding_mentions(string $text): array {
    $said = function (string $re) use ($text): bool {
      if (!preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE)) return false;
      foreach ($m[0] as [$hit, $at]) if (!ias_negated_at($text, (int) $at, strlen((string) $hit))) return true;
      return false;
    };
    return [
      'phone'     => $said('/\b(?:intel\s+)?phone[\s-]bid(?:s|ding)?\b|\bbid(?:ding)?\s+(?:by|over\s+the)\s+phone\b|\bphone\s+in\s+(?:your\s+)?bids?\b/i'),
      'in_person' => $said('/\bin[\s-]person\s+(?:auction|bidding|sale)\b|\bbid(?:ding)?\s+in\s+person\b|\bon[\s-]?site\s+(?:auction|bidding|sale)\b|\blive\s+(?:auction|bidding)\b|\bbid(?:ding)?\s+on[\s-]?site\b/i'),
      'online'    => $said('/\b(?:online|internet|web(?:cast)?)\s+(?:bid|bidding|auction)\b|\bbid(?:ding)?\s+online\b/i'),
    ];
  }
}

/**
 * Are the words at byte $at ($len bytes) denied by their own clause — "no",
 * "not", "isn't", "won't be", "without", "never", "nor" in the few words
 * before them ("no in-person or online bidding" denies both), or "is not",
 * "isn't", "not available" straight after ("phone bidding is not available")?
 * Within the same sentence only. Pure.
 */
if (!function_exists('ias_negated_at')) {
  function ias_negated_at(string $text, int $at, int $len = 0): bool {
    $before = substr($text, max(0, $at - 60), min(60, $at));
    $parts = preg_split('/[.!?;:\n•]|—/u', $before);
    $clause = (string) end($parts);
    if (preg_match('/\b(?:no|not|never|without|nor|none)\b|n[\'’]t\b/i', $clause)) return true;
    $after = (string) (preg_split('/[.!?;:\n•]|—/u', substr($text, $at + $len, 40))[0] ?? '');
    return (bool) preg_match('/^\s*(?:(?:is|are|was|will\s+be|won[\'’]t\s+be)\s+not\b|(?:isn|aren|wasn|won)[\'’]t\b|(?:is|are)\s+(?:not\s+)?(?:unavailable|not\s+offered|not\s+an\s+option))/i', $after);
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
 * The sellers named in $text: each seller as the listing gives them ("John and
 * Mary Smith", "Smith Family Trust"), and each name word of four letters or more
 * that is not a word of the place itself ($place: its name, street, town,
 * county) or a word like Family, Estate or Trust. Whole words, any case. Pure.
 */
if (!function_exists('ias_seller_names_in')) {
  function ias_seller_names_in(string $text, string $sellers, string $place = ''): array {
    $sellers = trim($sellers);
    if ($sellers === '' || trim($text) === '') return [];
    $common = ['family', 'estate', 'estates', 'trust', 'trustee', 'trustees', 'living', 'revocable', 'irrevocable', 'heirs', 'executor', 'executrix',
      'llc', 'inc', 'corp', 'company', 'farm', 'farms', 'the', 'and', 'of', 'late', 'deceased', 'mr', 'mrs', 'ms', 'dr', 'jr', 'sr', 'partnership', 'properties', 'holdings'];
    $place_words = array_map('mb_strtolower', preg_split('/[^\p{L}\']+/u', $place, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $found = [];
    foreach (preg_split('/\s*(?:,|;|&|\/|\band\b|\n)\s*/iu', $sellers, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $one) {
      $one = trim($one);
      if (mb_strlen($one) >= 4 && preg_match('/(?<![\p{L}])' . preg_quote($one, '/') . '(?![\p{L}])/iu', $text)) $found[$one] = true;
    }
    foreach (preg_split('/[^\p{L}\']+/u', $sellers, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
      $low = mb_strtolower($word);
      if (mb_strlen($word) < 4 || in_array($low, $common, true) || in_array($low, $place_words, true)) continue;
      if (preg_match('/(?<![\p{L}])' . preg_quote($word, '/') . '(?![\p{L}])/iu', $text)) $found[$word] = true;
    }
    return array_keys($found);
  }
}

/**
 * Check one draft. $ctx:
 *   type        post type
 *   bidding     the bidding type of each listing in the post ('phone'|'in_person'|'')
 *   money       dollar values the listing(s) state (starting bids, sale prices, parcel bids)
 *   phones      phone numbers the post may give (the lead's, the office)
 *   fair_extra  extra fair-housing phrases from Settings
 *   emoji_max   the most emoji allowed (3; an emoji-led post's shape gives 8)
 *   sellers     a Results post's sellers, as the listing gives them (never to be named)
 *   place       the place's own words (name, street, town, county), which a surname may share
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
        $holds[] = 'The listing contradicts itself on whether it is Intel Phone Bidding or in person (its auction type and its Auction Type category disagree) — fix the listing: a phone-bid sale has to say so.';
      } elseif (count($types) === 1) {
        if ($types[0] === 'phone') {
          if (!$said['phone']) $holds[] = 'It does not say this is Intel Phone Bidding.';
          if ($said['in_person']) $holds[] = 'It mentions in-person bidding too — buyers would think they can choose.';
        } elseif ($said['phone']) {
          $holds[] = 'It mentions phone bidding, but this is an in-person auction.'; // an in-person auction itself need not be said
        }
      } elseif (in_array('phone', $types, true) && !$said['phone']) {
        $holds[] = 'One of these sales is Intel Phone Bidding and the post does not say so.';
      }
    }

    $allowed = array_map('floatval', (array) ($ctx['money'] ?? []));
    $figures = !array_key_exists('figures', $ctx) || !empty($ctx['figures']); // false: a person's own post about no listing — nothing to check figures against
    foreach ($figures ? ias_dollar_amounts($text) : [] as $amt) {
      $ok = false;
      foreach ($allowed as $v) if (abs($v - $amt['value']) < 0.5) { $ok = true; break; }
      if (!$ok) $holds[] = 'It gives a dollar figure that is not on the listing: ' . $amt['text'] . '.';
    }

    $phones = array_map('ias_digits10', (array) ($ctx['phones'] ?? []));
    foreach ($figures ? ias_phone_numbers($text) : [] as $ph) {
      if (!in_array($ph['digits'], $phones, true)) $holds[] = 'It gives a phone number that is not the lead\'s or the office\'s: ' . $ph['text'] . '.';
    }

    foreach (ias_fair_housing_check($text, (string) ($ctx['fair_extra'] ?? '')) as $hit) {
      $holds[] = 'Fair housing: "' . $hit['phrase'] . '" ' . $hit['why'] . '.';
    }

    if ($type === 'results' && ($named = ias_seller_names_in($text, (string) ($ctx['sellers'] ?? ''), (string) ($ctx['place'] ?? '')))) {
      $holds[] = 'It names the seller ("' . implode('", "', $named) . '") — a Results post never names the sellers.';
    }

    $emoji_max = (int) ($ctx['emoji_max'] ?? 3); // an emoji-led post (its shape) may carry more, one a line
    if (ias_emoji_count($text) > $emoji_max) $holds[] = $emoji_max > 3 ? 'More than ' . $emoji_max . ' emoji, even for an emoji-led post.' : 'More than three emoji — no emoji walls.';

    return ['text' => $text, 'holds' => array_values(array_unique($holds)), 'fixes' => $fixes];
  }
}
