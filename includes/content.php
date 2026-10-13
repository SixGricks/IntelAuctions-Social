<?php
/**
 * Intel Auctions Social — writing the posts.
 *
 * Claude writes every post through the toolkit's Claude engine
 * (`ia_ai_generate()`, feature `social.<type>`, metered against the monthly
 * cap) from the listing's facts and nothing else. On top of the post type's
 * brief go one of five rotating templates and the division's voice (Farm &
 * Home, Investment & Recreation, Intel general); the plugin picks the call to
 * action and the hashtags itself so it can keep the no-repeat rules: no
 * opening line, call to action or hashtag set used twice in 30 days.
 *
 * The post is assembled as opener, body, call to action and hashtags; the
 * listing link (tagged with utm_source/medium/campaign/content) goes in the
 * first comment. Then the draft is checked (checks.php) — a hold makes it
 * "Needs a decision" rather than letting it near Facebook.
 *
 * Paying twice is guarded: a draft is written once, and again only when the
 * listing's facts changed (at most once an hour, and never over a person's
 * edit) or a person presses Rewrite (at most once an hour); a failed call
 * waits 6 hours. A draft whose facts changed is never pushed until rewritten,
 * and an approved post whose facts changed goes back for a fresh yes.
 */

if (!defined('ABSPATH')) exit;

/** The five rotating templates: key => [label, brief]. */
if (!function_exists('ias_templates')) {
  function ias_templates(): array {
    return (array) apply_filters('ias_templates', [
      'place'    => ['Place first',    'Open with the place itself — what you would see standing on it, from the facts only.'],
      'date'     => ['Date first',     'Open like a sale bill: what is selling and when, in one plain line.'],
      'person'   => ['Person first',   'Open with who is handling the sale (the lead, by name) and why they are the one to call.'],
      'question' => ['Question first', 'Open with one plain question a buyer would really ask, then answer it with the facts.'],
      'numbers'  => ['Numbers first',  'Open with the key numbers — acres, starting bid, parcels — in one plain line, then the rest.'],
    ]);
  }
}

/** The division voices that sit on top of the templates. */
if (!function_exists('ias_voices')) {
  function ias_voices(): array {
    return (array) apply_filters('ias_voices', [
      'farm_home'      => 'Farm & Home: written for farm families and local buyers in Lancaster County and central Pennsylvania. Practical — the land, the barns, tillable acres, the house as a home place. Down to earth and respectful; no city real-estate talk.',
      'investment_rec' => 'Investment & Recreation: written for hunters, investors and weekend buyers. Matter-of-fact about ground, timber, access, cabins, wildlife and income. No hype.',
      'intel'          => 'Intel general (liquidations, equipment, business assets): written for contractors, tradespeople and bargain hunters. Brisk and plain about what is selling and how the sale works.',
      'mixed'          => 'Several kinds of sale in one post: keep each one in its own plain words.',
    ]);
  }
}

/**
 * Calls to action: key => [label, brief, post types it suits]. The brief tells
 * Claude what to ask; Claude words it, and the wording may not repeat.
 */
if (!function_exists('ias_cta_kinds')) {
  function ias_cta_kinds(): array {
    return (array) apply_filters('ias_cta_kinds', [
      'call_lead'     => ['Call the lead', 'Ask them to call the lead by name at the lead\'s phone number (from the facts).', ['listing', 'open_house', 'auction_week']],
      'join_list'     => ['Join the list', 'Invite them onto our email or call list for sale notices — they can call the office at the office phone number to be added. Do not invent a website or sign-up form.', ['listing', 'auction_week', 'results', 'roundup']],
      'ask'           => ['Comment with a question', 'Invite questions in the comments; we answer them.', ['listing', 'open_house', 'auction_week', 'results', 'roundup']],
      'tag'           => ['Tag someone', 'Ask them to tag someone who would want this.', ['listing', 'open_house', 'auction_week', 'roundup']],
      'brochure'      => ['Reply for the brochure', 'Tell them to comment or message us for the brochure.', ['listing', 'open_house', 'auction_week', 'roundup']],
    ]);
  }
}

/** The facts arrays for every listing a post covers (skipping any that no longer exist). */
if (!function_exists('ias_post_facts')) {
  function ias_post_facts(array $post): array {
    $out = [];
    foreach ((array) $post['listing_ids'] as $id) {
      if (get_post((int) $id)) $out[] = ias_listing_facts((int) $id);
    }
    return $out;
  }
}

/** The post's extra planning data (open house number, sale day, Friday…), kept in flags. */
if (!function_exists('ias_post_meta')) {
  function ias_post_meta(array $post): array {
    return isset($post['flags']['meta']) && is_array($post['flags']['meta']) ? $post['flags']['meta'] : [];
  }
}

/**
 * A fingerprint of everything the draft is written from. When it changes, a
 * draft nobody has touched is rewritten; the photos are left out (a new
 * gallery picture is not a reason to pay for new words).
 */
if (!function_exists('ias_facts_hash')) {
  function ias_facts_hash(array $post, array $facts_list): string {
    $data = [(string) $post['type'], ias_post_meta($post)];
    foreach ($facts_list as $f) $data[] = ias_facts_for_prompt($f);
    return md5((string) wp_json_encode($data));
  }
}

/** "Mount Joy" → "#MountJoy"; "" for nothing usable. */
if (!function_exists('ias_hashtag')) {
  function ias_hashtag(string $words): string {
    $words = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', remove_accents($words));
    $tag = str_replace(' ', '', ucwords(strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $words)))));
    return $tag !== '' ? '#' . $tag : '';
  }
}

/**
 * Three hashtags whose set has not been used in 30 days: #IntelAuctions plus
 * two of the place (town, county), the post type and the division.
 */
if (!function_exists('ias_pick_hashtags')) {
  function ias_pick_hashtags(array $post, array $facts_list, int $except_post = 0): array {
    $f = $facts_list[0] ?? [];
    $place = [];
    if (!empty($f['town'])) $place[] = ias_hashtag((string) $f['town']);
    if (!empty($f['county'])) $place[] = ias_hashtag(stripos((string) $f['county'], 'county') === false ? $f['county'] . ' County' : (string) $f['county']);
    $type_tags = [
      'listing' => ['#JustListed', '#NewListing'], 'open_house' => ['#OpenHouse', '#OpenHouseThisWeek'],
      'auction_week' => ['#AuctionWeek', '#AuctionDay'], 'results' => ['#SoldAtAuction', '#AuctionResults'],
      'roundup' => ['#NewThisWeek', '#ComingUp'],
    ][$post['type']] ?? ['#Auction'];
    $division = (string) ($post['division'] ?? ($f['division'] ?? ''));
    $div_tags = [
      'farm_home' => ['#FarmAuction', '#PAFarmland', '#FarmAndHome', '#RealEstateAuction'],
      'investment_rec' => ['#HuntingLand', '#LandAuction', '#PALand', '#RecreationalLand'],
      'intel' => ['#Liquidation', '#EquipmentAuction', '#PAAuction', '#AuctionTime'],
    ][$division] ?? ['#PAAuction', '#RealEstateAuction', '#LancasterCountyAuction'];
    if ($post['type'] === 'roundup') $place = [];

    $pairs = [];
    foreach ($place as $p) foreach ($type_tags as $t) $pairs[] = [$p, $t];
    foreach ($place as $p) foreach ($div_tags as $d) $pairs[] = [$p, $d];
    foreach ($type_tags as $t) foreach ($div_tags as $d) $pairs[] = [$t, $d];
    foreach ($div_tags as $i => $d) foreach (array_slice($div_tags, $i + 1) as $e) $pairs[] = [$d, $e];
    foreach ($pairs as $pair) {
      $set = array_values(array_unique(array_filter(array_merge(['#IntelAuctions'], $pair))));
      if (count($set) < 3) continue;
      $key = implode(' ', array_map('strtolower', $set));
      if (!ias_history_used('hashtags', $key, 30, $except_post)) return $set;
    }
    // Every combination used this month (a very busy month): the least recently used wins.
    return array_values(array_unique(array_merge(['#IntelAuctions'], $pairs[0] ?? $type_tags)));
  }
}

/** The listing link tagged so Analytics can tell which post type sent the visit. */
if (!function_exists('ias_tracked_link')) {
  function ias_tracked_link(string $url, string $type, int $post_id): string {
    if ($url === '') return '';
    return add_query_arg([
      'utm_source'   => (string) ias_setting('utm_source', 'facebook'),
      'utm_medium'   => (string) ias_setting('utm_medium', 'social'),
      'utm_campaign' => 'social_' . $type,
      'utm_content'  => 'p' . $post_id,
    ], $url);
  }
}

/** The Recently Announced page (the roundup's link): the stage term's archive. */
if (!function_exists('ias_announced_page_url')) {
  function ias_announced_page_url(): string {
    $url = (string) apply_filters('ias_announced_page_url', '');
    if ($url !== '') return $url;
    $term = get_term_by('slug', ia_announced_term_slug(), ia_upcoming_taxonomy());
    $link = ($term && !is_wp_error($term)) ? get_term_link($term) : '';
    return is_string($link) ? $link : home_url('/');
  }
}

/** The first comment and the link it carries. */
if (!function_exists('ias_first_comment')) {
  function ias_first_comment(array $post, array $facts_list): array {
    $id = (int) $post['id'];
    if ($post['type'] === 'roundup') {
      $link = ias_tracked_link(ias_announced_page_url(), 'roundup', $id);
      return ['Every new sale, with dates as they are set: ' . $link, $link];
    }
    if (count($facts_list) > 1) {
      $lines = [];
      foreach ($facts_list as $f) $lines[] = ($f['street'] ?: $f['title']) . ': ' . ias_tracked_link((string) $f['url'], (string) $post['type'], $id);
      return ["Photos, terms and details:\n" . implode("\n", $lines), ias_tracked_link((string) $facts_list[0]['url'], (string) $post['type'], $id)];
    }
    $f = $facts_list[0];
    $link = ias_tracked_link((string) $f['url'], (string) $post['type'], $id);
    $lead = [
      'results' => 'The listing and how it sold: ',
      'open_house' => 'Photos, directions and the full listing: ',
    ][$post['type']] ?? 'Photos, terms and the full listing: ';
    $comment = $lead . $link;
    if ($f['video'] !== '' && !$f['video_file'] && in_array($post['type'], ['listing', 'open_house'], true)) {
      $comment .= "\nVideo walkthrough: " . $f['video'];
    }
    return [$comment, $link];
  }
}

/** One image entry for a post: the picture, its alt text and its three crops. */
if (!function_exists('ias_media_image')) {
  function ias_media_image(int $att, array $f): array {
    $g = ias_photo_grade($att);
    return [
      'id'    => $att,
      'url'   => ias_attachment_url($att, '2048x2048'),
      'alt'   => ias_photo_alt($att, $f),
      'kind'  => $g['kind'] ?? '',
      'crops' => ias_photo_crops($att),
    ];
  }
}

/** A listing's one best photo: its featured image, else the best-graded wide shot (0 = none). */
if (!function_exists('ias_media_best_photo')) {
  function ias_media_best_photo(array $f): int {
    if ((int) $f['featured'] > 0) return (int) $f['featured'];
    return (int) (ias_pick_photos(ias_photo_grades((array) $f['photos']), 'auction_week', [])[0] ?? 0);
  }
}

/** A generated graphic as an image entry. */
if (!function_exists('ias_media_graphic')) {
  function ias_media_graphic(array $g): array {
    return ['id' => 0, 'url' => $g['url'], 'alt' => $g['alt'], 'kind' => 'graphic', 'crops' => []];
  }
}

/** A listing's cover: its brochure cover, else a branded card. */
if (!function_exists('ias_media_cover')) {
  function ias_media_cover(array $f, string $name): ?array {
    if ((int) $f['brochure'] > 0) return ias_media_image((int) $f['brochure'], $f) + ['cover' => true];
    $card = ias_graphic_card($f, $name);
    return $card ? ias_media_graphic($card) : null;
  }
}

/**
 * The pictures (or video) for a post: ['kind' => images|video|none, 'images' => […], 'video_url' => ''].
 * Grades the listing's photos first when they are needed and not graded yet.
 */
if (!function_exists('ias_media_for_post')) {
  function ias_media_for_post(array $post, array $facts_list): array {
    $type = (string) $post['type'];
    $id   = (int) $post['id'];
    $meta = ias_post_meta($post);
    $media = ['kind' => 'images', 'images' => [], 'video_url' => ''];
    $slug = 'ias-' . str_replace(':', '-', (string) $post['slot_key']);

    if ($type === 'roundup') {
      $g = ias_graphic_collage($facts_list, $slug . '-' . substr(md5((string) wp_json_encode(array_column($facts_list, 'id')) . wp_json_encode(array_column($facts_list, 'brochure'))), 0, 8));
      if ($g) {
        $media['images'][] = ias_media_graphic($g);
      } else {
        // No collage (no GD on this server): the brochure covers themselves, else each listing's best photo.
        foreach ($facts_list as $f) {
          $pick = (int) $f['brochure'] > 0 ? (int) $f['brochure'] : ias_media_best_photo($f);
          if ($pick > 0 && count($media['images']) < 10) $media['images'][] = ias_media_image($pick, $f);
        }
      }
      return $media;
    }

    if ($type === 'results') {
      $items = [];
      foreach ($facts_list as $f) {
        if ($f['price'] === '' || $f['kind'] === 'liquidation') continue;
        $photo = (int) $f['featured'];
        if ($photo <= 0) {
          $grades = ias_photo_grades($f['photos']);
          $photo = (int) (ias_pick_photos($grades, 'auction_week', [])[0] ?? 0);
        }
        $items[] = ['photo' => $photo, 'price' => $f['price'], 'street' => $f['street'] ?: $f['title'], 'place' => trim($f['town'] . ($f['state'] !== '' ? ', ' . $f['state'] : ''))];
      }
      $sold_on = !empty($facts_list[0]['date']) ? $facts_list[0]['date']->format('F j, Y') : '';
      if ($items) {
        $g = ias_graphic_results($items, $sold_on, $slug . '-' . substr(md5((string) wp_json_encode($items)), 0, 8));
        if ($g) {
          $media['images'][] = ias_media_graphic($g);
        } else {
          // No graphic (no GD on this server): each sold property's plain photo — the price is in the words.
          foreach ($facts_list as $f) {
            if ($f['price'] === '' || $f['kind'] === 'liquidation') continue;
            $pick = ias_media_best_photo($f);
            if ($pick > 0 && count($media['images']) < 10) $media['images'][] = ias_media_image($pick, $f);
          }
        }
      } else {
        // A liquidation's thank-you: a plain photo, never a price.
        $f = $facts_list[0];
        $pick = ias_media_best_photo($f);
        if ($pick > 0) $media['images'][] = ias_media_image($pick, $f);
      }
      return $media;
    }

    $uses_cover = array_map('intval', (array) ($meta['uses_cover'] ?? []));
    foreach ($facts_list as $f) {
      if (in_array((int) $f['id'], $uses_cover, true) || !$f['has_photos']) {
        $cover = ias_media_cover($f, $slug . '-cover-' . (int) $f['id']);
        if ($cover) $media['images'][] = $cover;
        continue;
      }
      if ($type === 'open_house' && $f['video_file'] && count($facts_list) === 1) {
        return ['kind' => 'video', 'images' => [], 'video_url' => $f['video']];
      }
      ias_photos_grade($f, 2);
      $used  = ias_listing_used_images((int) $f['id'], $id);
      $avoid = [];
      if ($type === 'open_house') {
        $lp = ias_post_by_slot('listing:' . (int) $f['id']);
        if ($lp) $avoid = array_map('intval', (array) $lp['images_used']);
      }
      $grades = ias_photo_grades($f['photos']);
      $max = ias_listing_photo_budget(count($grades), count($f['open_houses']));
      if ($type === 'open_house') {
        // Leave two fresh photos for the Auction Week post.
        $fresh = count(array_diff(array_keys($grades), $used, $avoid));
        $max = max(3, min(5, $fresh - 2));
      }
      $ids = ias_pick_photos($grades, $type, $used, $avoid, ['max' => $max, 'avoid_hashes' => array_map('ias_photo_dhash', $avoid)]);
      if ($type === 'open_house' && count($ids) < 3 && $avoid) {
        // A small gallery: fill out with the listing post's photos rather than post one picture.
        $more = ias_pick_photos($grades, $type, array_unique(array_merge($used, $avoid)));
        $ids = array_values(array_unique(array_merge($ids, $more)));
        $ids = array_slice($ids, 0, max(3, count($ids) >= 5 ? 5 : count($ids)));
      }
      if (count($facts_list) > 1) $ids = array_slice($ids, 0, count($facts_list) > 3 ? 1 : 2); // shared posts: one or two each
      foreach ($ids as $att) $media['images'][] = ias_media_image((int) $att, $f);
    }
    if (!$media['images']) $media['kind'] = 'none';
    return $media;
  }
}

/** What the post is for, for Claude. */
if (!function_exists('ias_type_brief')) {
  function ias_type_brief(array $post, array $facts_list): string {
    $type = (string) $post['type'];
    $meta = ias_post_meta($post);
    $multi = count($facts_list) > 1;
    $f = $facts_list[0];
    switch ($type) {
      case 'listing':
        $b = 'LISTING POSTED — the first post this property gets, now that it has photos. Introduce it: what it is and where (town and county as given), the auction date and time, how bidding works, the starting bid and size if given, the open houses with their times, and who to call. About 80–150 words before the call to action.';
        if ($f['multi_parcel']) $b .= ' This is a MULTI-PARCEL sale: lead with the parcel breakdown and starting bids, one short line per parcel, exactly as the facts give them (if the facts do not list them, write [VERIFY: parcel breakdown]).';
        if ($f['kind'] === 'liquidation') $b .= ' This is a liquidation / contents sale: talk about what is selling, from the description; there is no land or house to describe.';
        return $b;
      case 'open_house':
        // Every session on this post's day (two on one day share the post), the first with its date.
        $ns = array_map('intval', (array) ($meta['ohs'] ?? [$meta['oh'] ?? 1]));
        $sessions = array_values(array_filter($f['open_houses'], function ($row) use ($ns) { return in_array((int) $row['n'], $ns, true); }));
        usort($sessions, function ($a, $b) { return $a['start'] <=> $b['start']; }); // in time order, whichever was entered first
        $times = [];
        foreach ($sessions as $row) {
          $times[] = ia_open_house_format($row, 'range', $times ? '' : 'l, F j,') . ' (' . ia_open_house_hours_label((float) $row['hours']) . ')';
        }
        $when = $times ? implode(' and again ', $times) : '[VERIFY: open house time]';
        return 'OPEN HOUSE — invite people to walk the property at the open house on ' . $when . '. Give the day, '
          . (count($times) > 1 ? 'both sessions\' time ranges and how long each runs' : 'the time range and how long it runs')
          . ', what they will be able to see (from the facts only), and the auction date and how bidding works. About 50–100 words before the call to action.';
      case 'auction_week':
        $b = 'AUCTION WEEK — the sale is coming up. An urgency angle without hype: the auction date and start time, the place, how bidding works, the starting bid if given, and who to call with questions. Name the date; do not say how many days away it is (the post\'s day can move). About 50–100 words before the call to action.';
        if ($multi) $b .= ' SEVERAL PROPERTIES SELL THE SAME DAY: name each one on its own short line with its start time and how bidding works for it.';
        if (!empty($meta['uses_cover'])) $b .= ' This is the first post for a listing whose photos never arrived; the picture is its brochure cover.';
        return $b;
      case 'results':
        $priced = array_filter($facts_list, function ($x) { return $x['price'] !== '' && $x['kind'] !== 'liquidation'; });
        if (!$priced) return 'RESULTS (a liquidation / contents sale): a short thank-you — thank everyone who came out for a great turnout and the sellers for trusting us. No prices, no totals, no numbers about the sale. About 40–70 words before the call to action.';
        return 'RESULTS — report the sale plainly: the property, the address, the auction date and the sale price exactly as given' . ($multi ? ', one short line per property (a liquidation among them gets a thank-you line, no price)' : '') . '. Thank the sellers, by name only as the facts give them. No other figures, no "record" or comparison claims. About 40–80 words before the call to action.';
      case 'roundup':
        return 'NEW THIS WEEK — one post naming every new sale announced this week, one short line each: street, town and auction date (or "date to be announced"). One plain line before the list and none after it; the call to action follows. Do not describe the properties beyond that.';
    }
    return '';
  }
}

/** Template for this post: the least used this month, never the one this property's last post used. */
if (!function_exists('ias_pick_template')) {
  function ias_pick_template(array $post, array $facts_list): string {
    if ($post['type'] === 'roundup') return 'list';
    $keys = array_keys(ias_templates());
    $last = ias_history_recent('template', 365, (int) ($facts_list[0]['id'] ?? 0), 1)[0] ?? '';
    $recent = array_count_values(ias_history_recent('template', 30, 0, 200));
    $best = null; $best_n = PHP_INT_MAX;
    foreach ($keys as $k) {
      if ($k === $last && count($keys) > 1) continue;
      if ($k === 'numbers' && empty($facts_list[0]['acres']) && empty($facts_list[0]['starting_bid']) && empty($facts_list[0]['parcels'])) continue;
      $n = $recent[$k] ?? 0;
      if ($n < $best_n) { $best = $k; $best_n = $n; }
    }
    return $best ?? $keys[0];
  }
}

/** Call to action kind: the least used this month among those the type suits, not this property's last one. */
if (!function_exists('ias_pick_cta_kind')) {
  function ias_pick_cta_kind(array $post, array $facts_list): string {
    $type = (string) $post['type'];
    $kinds = [];
    foreach (ias_cta_kinds() as $k => $def) if (in_array($type, (array) $def[2], true)) $kinds[] = $k;
    if ($type === 'results' && !array_filter($facts_list, function ($x) { return $x['price'] !== ''; })) $kinds = ['join_list'];
    if (empty($facts_list[0]['lead']['phone'])) $kinds = array_values(array_diff($kinds, ['call_lead']));
    if (!$kinds) return 'ask';
    $last = ias_history_recent('cta_kind', 365, (int) ($facts_list[0]['id'] ?? 0), 1)[0] ?? '';
    $recent = array_count_values(ias_history_recent('cta_kind', 30, 0, 300));
    $best = $kinds[0]; $best_n = PHP_INT_MAX;
    foreach ($kinds as $k) {
      if ($k === $last && count($kinds) > 1) continue;
      $n = $recent[$k] ?? 0;
      if ($n < $best_n) { $best = $k; $best_n = $n; }
    }
    return $best;
  }
}

/** [system, user] for one post. $avoid: recent openers and CTA wordings not to repeat. */
if (!function_exists('ias_prompt')) {
  function ias_prompt(array $post, array $facts_list, array $media, string $template, string $cta_kind, array $avoid): array {
    $system = ia_ai_brand_context() . "\n\n"
      . "You now write Facebook posts for Intel Auctions' own Facebook page.\n"
      . "VOICE: plainspoken and local, the way Intel talks at a sale. Short sentences. No stock marketing gloss (never \"don't miss out\", \"dream home\", \"nestled\", \"boasts\", \"stunning\", \"won't last\", \"opportunity of a lifetime\"). At most one emoji, and none is fine. Never two exclamation points in a row. No words in all caps except abbreviations.\n"
      . "FACTS: use ONLY the facts in the FACTS block. Never invent or round acreage, prices, features, dates, times, names, phone numbers or history. If the post needs something the facts do not have, write [VERIFY: what is missing] in its place.\n"
      . "BIDDING: when the facts say how bidding works, state it once, exactly as given — an in-person auction, or Intel Phone Bidding. Never imply buyers can choose between them, and never mention online or internet bidding.\n"
      . "FAIR HOUSING: describe the property, never who should buy it or who the neighbours are. Never write things like \"perfect for families\", \"family-friendly\", \"safe neighborhood\", \"great for retirees\", or anything about race, religion, national origin, sex, disability or familial status.\n"
      . "LINKS AND TAGS: no URL or web address anywhere (the link goes in a comment), and no hashtags (they are added separately).\n"
      . "OUTPUT: JSON only, no code fences: {\"opener\": \"the first line, one sentence\", \"body\": \"the rest of the post before the call to action\", \"cta\": \"the call to action, one or two sentences\"}";

    $templates = ias_templates();
    $voices = ias_voices();
    $ctas = ias_cta_kinds();
    $division = count($facts_list) > 1 ? (string) ($post['division'] ?? 'mixed') : (string) $facts_list[0]['division'];
    $facts = count($facts_list) > 1 ? array_map('ias_facts_for_prompt', $facts_list) : ias_facts_for_prompt($facts_list[0]);

    $user = 'POST TYPE: ' . ias_type_brief($post, $facts_list) . "\n\n";
    if (isset($templates[$template])) $user .= 'TEMPLATE: ' . $templates[$template][1] . "\n\n";
    $user .= 'DIVISION VOICE: ' . ($voices[$division] ?? $voices['farm_home']) . "\n\n";
    $user .= 'CALL TO ACTION: ' . ($ctas[$cta_kind][1] ?? 'Invite questions in the comments.') . "\n";
    if (!empty($avoid['cta'])) $user .= 'Do not reuse any of these recent calls to action, even reworded slightly: ' . implode(' | ', array_slice($avoid['cta'], 0, 12)) . "\n";
    $user .= "\n";
    if (!empty($avoid['opener'])) $user .= 'OPENING LINE: must not repeat or closely echo any of these recent opening lines: ' . implode(' | ', array_slice($avoid['opener'], 0, 25)) . "\n\n";
    $pics = [];
    foreach ((array) $media['images'] as $img) $pics[] = $img['kind'] === 'graphic' ? 'a graphic' : ($img['kind'] !== '' ? $img['kind'] : 'photo');
    if ($media['kind'] === 'video') $user .= "MEDIA: the property's video tour is attached.\n\n";
    elseif ($pics) $user .= 'MEDIA: ' . count($pics) . ' picture(s) attached (' . implode(', ', $pics) . "). Do not describe them as \"see the photos below\".\n\n";
    $user .= "FACTS:\n" . wp_json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return [$system, $user];
  }
}

/** Opener, body, call to action and hashtags as the post's text (with the link at the end when it cannot go in a comment). */
if (!function_exists('ias_assemble_text')) {
  function ias_assemble_text(array $parts, array $hashtags, string $link, bool $link_in_comment): string {
    $blocks = [trim((string) ($parts['opener'] ?? '')), trim((string) ($parts['body'] ?? '')), trim((string) ($parts['cta'] ?? ''))];
    if (!$link_in_comment && $link !== '') $blocks[] = 'Details: ' . $link;
    if ($hashtags) $blocks[] = implode(' ', $hashtags);
    return implode("\n\n", array_filter($blocks, function ($b) { return $b !== ''; }));
  }
}

/** Dollar values and phone numbers the post may mention. */
if (!function_exists('ias_allowed_figures')) {
  function ias_allowed_figures(array $facts_list): array {
    $money = []; $phones = [];
    foreach ($facts_list as $f) {
      if ($f['starting_bid'] !== null) $money[] = (float) $f['starting_bid'];
      foreach (ias_dollar_amounts((string) $f['starting_bid_raw']) as $a) $money[] = $a['value'];
      foreach (ias_dollar_amounts((string) $f['price']) as $a) $money[] = $a['value'];
      foreach ($f['parcels'] as $p) foreach (ias_dollar_amounts((string) $p['bid']) as $a) $money[] = $a['value'];
      foreach (ias_dollar_amounts((string) $f['excerpt']) as $a) $money[] = $a['value']; // a figure the listing's own description states
      if (!empty($f['lead']['phone'])) $phones[] = $f['lead']['phone'];
      $phones[] = $f['office_phone'];
    }
    return [array_values(array_unique($money)), array_values(array_unique($phones))];
  }
}

/** The first line, normalised, for the opener history. */
if (!function_exists('ias_first_line')) {
  function ias_first_line(string $text): string {
    $line = trim((string) strtok(trim($text), "\n"));
    return mb_substr($line, 0, 200);
  }
}

/** The most pictures a post carries (Facebook's 10); each network's renderer takes its own first few. */
if (!function_exists('ias_media_max')) {
  function ias_media_max(): int {
    return max(1, (int) apply_filters('ias_media_max', 10));
  }
}

/**
 * The pictures a person chose for this post (`flags.media_by`), with each
 * library picture's alt text and crops read afresh — or null when the plugin
 * chooses. A rewrite, or the listing changing, keeps a person's pictures.
 */
if (!function_exists('ias_media_person')) {
  function ias_media_person(array $post, array $facts_list): ?array {
    if (empty($post['flags']['media_by'])) return null;
    $media = (array) ($post['content']['media'] ?? []);
    $out = ['kind' => 'images', 'images' => [], 'video_url' => ''];
    foreach ((array) ($media['images'] ?? []) as $img) {
      $att = (int) ($img['id'] ?? 0);
      if ($att <= 0) { $out['images'][] = $img; continue; } // a drawn graphic (Results, roundup, card) stays as it was
      if (!wp_attachment_is_image($att)) continue;          // deleted from the Media Library since
      $f = $facts_list[0] ?? [];
      foreach ($facts_list as $one) if (in_array($att, array_map('intval', (array) $one['photos']), true)) { $f = $one; break; }
      $out['images'][] = ias_media_image($att, $f);
    }
    if (!$out['images']) $out['kind'] = 'none';
    return $out;
  }
}

/**
 * A person chooses a post's pictures: $keys in order, each `a<attachment id>`
 * (any image in the Media Library) or `g<n>` (the post's own drawn graphic n,
 * so no address can be typed in). Returns ['ok', 'error', 'count'].
 * Approved or waiting Lane A posts need a fresh yes, as for changed words.
 */
if (!function_exists('ias_media_save')) {
  function ias_media_save(int $post_id, array $keys, string $actor): array {
    $p = ias_post_get($post_id);
    if (!$p) return ['ok' => false, 'error' => 'No such post.', 'count' => 0];
    $facts_list = ias_post_facts($p);
    $current = (array) ($p['content']['media']['images'] ?? []);
    $images = [];
    $seen = [];
    foreach ($keys as $key) {
      $key = trim((string) $key);
      if (isset($seen[$key]) || !preg_match('/^([ag])(\d+)$/', $key, $m)) continue;
      $seen[$key] = true;
      if ($m[1] === 'g') {
        $g = $current[(int) $m[2]] ?? null;
        if (is_array($g) && (int) ($g['id'] ?? 0) === 0) $images[] = $g;
        continue;
      }
      $att = (int) $m[2];
      if (!wp_attachment_is_image($att)) continue;
      $f = $facts_list[0] ?? ['title' => get_the_title((int) $p['listing_id'])];
      foreach ($facts_list as $one) if (in_array($att, array_map('intval', (array) $one['photos']), true)) { $f = $one; break; }
      $images[] = ias_media_image($att, $f);
    }
    $max = ias_media_max();
    if (count($images) > $max) return ['ok' => false, 'error' => 'A post carries at most ' . $max . ' pictures — remove ' . (count($images) - $max) . '.', 'count' => count($images)];

    $content = (array) $p['content'];
    $content['media'] = ['kind' => $images ? 'images' : 'none', 'images' => $images, 'video_url' => ''];
    $flags = $p['flags'];
    $flags['media_by'] = $actor;
    $flags['media_at'] = ias_now_sql();
    $status = (string) $p['status'];
    $update = ['content' => $content, 'flags' => $flags, 'images_used' => array_values(array_filter(array_map(function ($i) { return (int) $i['id']; }, $images)))];
    if (in_array($status, ['waiting', 'approved'], true)) { // new pictures need a fresh yes, like new words
      if (function_exists('ias_approval_void')) ias_approval_void($post_id);
      $update += ['status' => 'planned', 'approved_at' => null, 'approved_by' => ''];
    }
    ias_post_update($post_id, $update);
    ias_history_forget_post($post_id, ['image']);
    foreach ($images as $img) {
      $att = (int) $img['id'];
      if ($att <= 0) continue;
      foreach ($facts_list as $f) if (in_array($att, array_map('intval', (array) $f['photos']), true) || $att === (int) $f['brochure']) ias_history_add('image', (string) $att, (int) $f['id'], $post_id);
    }
    $what = count($images) ? count($images) . ' picture' . (count($images) === 1 ? '' : 's') : 'no pictures';
    ias_post_trail($post_id, 'Pictures chosen by hand: ' . $what, $actor);
    ias_log('edited', (int) $p['listing_id'], ['post' => $post_id, 'summary' => 'Pictures chosen by hand: ' . $what], $actor);
    if (in_array($status, ['waiting', 'approved'], true)) do_action('ias_post_edited', $post_id, (array) ($flags['holds'] ?? []));
    return ['ok' => true, 'error' => '', 'count' => count($images)];
  }
}

/** Hand the choice of pictures back to the plugin: they are picked again now (the words stay). */
if (!function_exists('ias_media_reset')) {
  function ias_media_reset(int $post_id, string $actor): bool {
    $p = ias_post_get($post_id);
    if (!$p || empty($p['flags']['media_by'])) return false;
    $flags = $p['flags'];
    unset($flags['media_by'], $flags['media_at']);
    $p['flags'] = $flags;
    $facts_list = ias_post_facts($p);
    $media = $facts_list ? ias_media_for_post($p, $facts_list) : ['kind' => 'none', 'images' => [], 'video_url' => ''];
    $content = (array) $p['content'];
    $content['media'] = $media;
    $update = ['content' => $content, 'flags' => $flags, 'images_used' => array_values(array_filter(array_map(function ($i) { return (int) $i['id']; }, (array) $media['images'])))];
    if (in_array($p['status'], ['waiting', 'approved'], true)) { // other pictures need a fresh yes
      if (function_exists('ias_approval_void')) ias_approval_void($post_id);
      $update += ['status' => 'planned', 'approved_at' => null, 'approved_by' => ''];
    }
    ias_post_update($post_id, $update);
    ias_post_trail($post_id, 'Pictures handed back to the plugin', $actor);
    ias_log('edited', (int) $p['listing_id'], ['post' => $post_id, 'summary' => 'Pictures handed back to the plugin'], $actor);
    return true;
  }
}

/**
 * Why Claude cannot be asked for anything now ('' = it can): the toolkit has
 * no Anthropic key, or this month's token budget is spent. The hourly drafts
 * and photo grading check it first, so a missing key costs nothing, stamps no
 * post with an error and writes no log line per listing — every Social screen
 * says it once instead (the switches strip). The toolkit's helpers are
 * optional here: without them, ia_ai_generate() reports the same thing.
 */
if (!function_exists('ias_ai_blocker')) {
  function ias_ai_blocker(): string {
    if (function_exists('ia_ai_api_key') && ia_ai_api_key() === '') {
      return 'The toolkit has no Anthropic API key, so no draft can be written and no photo graded. Add it under ' . (function_exists('ia_ai_key_settings_url') ? 'Intel SEO → Settings & Data → Services & Budgets' : 'Auctions → AI Content') . ' (or as INTEL_ANTHROPIC_API_KEY in wp-config.php).';
    }
    if (function_exists('ia_ai_token_cap') && function_exists('ia_ai_tokens_month')) {
      $cap = (int) ia_ai_token_cap();
      $used = (int) (ia_ai_tokens_month()['total'] ?? 0);
      if ($cap > 0 && $used >= $cap) {
        return 'This month\'s Claude budget in the toolkit is spent (' . number_format($used) . ' of ' . number_format($cap) . ' tokens), so no draft can be written until next month or until the cap is raised under Intel SEO → Settings & Data.';
      }
    }
    return '';
  }
}

/**
 * Write (or rewrite) one post's draft. Returns ['ok' => bool, 'status' => …, 'error' => '…'].
 * $forced = a person pressed Regenerate.
 */
if (!function_exists('ias_generate_post')) {
  function ias_generate_post(int $post_id, bool $forced = false, string $actor = 'system'): array {
    $post = ias_post_get($post_id);
    if (!$post) return ['ok' => false, 'error' => 'No such post.'];
    if (in_array($post['status'], ['pushed', 'published', 'human', 'cancelled'], true)) {
      return ['ok' => false, 'error' => 'A post that is ' . $post['status'] . ' is not rewritten.'];
    }
    $facts_list = ias_post_facts($post);
    if (!$facts_list) return ['ok' => false, 'error' => 'The listing this post was for is gone.'];

    $flags = $post['flags'];
    $media    = ias_media_person($post, $facts_list) ?? ias_media_for_post($post, $facts_list); // a person's pictures hold through a rewrite
    $template = ias_pick_template($post, $facts_list);
    $cta_kind = ias_pick_cta_kind($post, $facts_list);
    $avoid = [
      'opener' => ias_history_recent('opener', 30, 0, 40),
      'cta'    => ias_history_recent('cta', 30, 0, 40),
    ];

    $parts = null;
    $error = '';
    for ($try = 1; $try <= 2; $try++) {
      [$system, $user] = ias_prompt($post, $facts_list, $media, $template, $cta_kind, $avoid);
      if ($try === 2) $user .= "\n\nYOUR LAST ANSWER WAS NOT USABLE: " . $error . ' Answer again, JSON only.';
      $raw = ia_ai_generate($system, $user, 1200, 'social.' . $post['type']);
      if (is_wp_error($raw)) {
        $flags['gen_error_at'] = time();
        $flags['gen_error'] = $raw->get_error_message();
        ias_post_update($post_id, ['flags' => $flags, 'note' => 'No draft yet: ' . $raw->get_error_message()]);
        ias_log('gen_failed', (int) $post['listing_id'], ['post' => $post_id, 'summary' => $raw->get_error_message()]);
        return ['ok' => false, 'error' => $raw->get_error_message()];
      }
      $json = ias_json_from_text((string) $raw);
      if (!is_array($json) || trim((string) ($json['opener'] ?? '')) === '' || trim((string) ($json['body'] ?? '')) === '') {
        $error = 'It was not the JSON object asked for.';
        continue;
      }
      $parts = ['opener' => trim((string) $json['opener']), 'body' => trim((string) $json['body']), 'cta' => trim((string) ($json['cta'] ?? ''))];
      $repeat = [];
      if (ias_history_used('opener', $parts['opener'], 30, $post_id)) $repeat[] = 'the opening line was used in the last 30 days';
      if ($parts['cta'] !== '' && ias_history_used('cta', $parts['cta'], 30, $post_id)) $repeat[] = 'the call to action was used in the last 30 days';
      if (!$repeat || $try === 2) {
        if ($repeat) $parts['_repeat'] = $repeat;
        break;
      }
      $error = ucfirst(implode(' and ', $repeat)) . ' — write a different one.';
      $avoid['opener'][] = $parts['opener'];
      if ($parts['cta'] !== '') $avoid['cta'][] = $parts['cta'];
    }
    if (!$parts) {
      $flags['gen_error_at'] = time();
      $flags['gen_error'] = 'Claude did not return a usable draft.';
      ias_post_update($post_id, ['flags' => $flags, 'note' => 'No draft yet: Claude did not return a usable draft.']);
      ias_log('gen_failed', (int) $post['listing_id'], ['post' => $post_id, 'summary' => 'Claude did not return a usable draft']);
      return ['ok' => false, 'error' => 'Claude did not return a usable draft.'];
    }

    $hashtags = ias_pick_hashtags($post, $facts_list, $post_id);
    [$comment, $link] = ias_first_comment($post, $facts_list);
    $in_comment = (bool) ias_setting('link_in_comment', true);
    $text = ias_assemble_text($parts, $hashtags, $link, $in_comment);

    [$money, $phones] = ias_allowed_figures($facts_list);
    $check = ias_check_draft($text, [
      'type'       => (string) $post['type'],
      'bidding'    => array_map(function ($f) { return (string) $f['bidding']; }, $facts_list),
      'money'      => $money,
      'phones'     => $phones,
      'fair_extra' => (string) ias_setting('fair_housing_extra', ''),
    ]);
    $holds = $check['holds'];
    foreach ((array) ($parts['_repeat'] ?? []) as $r) $holds[] = 'Still repeating after a second try: ' . $r . '.';
    foreach ($facts_list as $f) {
      if ($f['bidding_note'] !== '' && in_array($post['type'], ['listing', 'open_house', 'auction_week'], true) && $f['bidding'] === '') {
        $holds[] = $f['bidding_note'];
      }
    }
    $holds = array_values(array_unique($holds));

    $images_used = array_values(array_filter(array_map(function ($img) { return (int) $img['id']; }, (array) $media['images'])));
    $content = [
      'text'          => $check['text'],
      'opener'        => $parts['opener'],
      'body'          => $parts['body'],
      'cta'           => $parts['cta'],
      'cta_kind'      => $cta_kind,
      'hashtags'      => $hashtags,
      'template'      => $template,
      'voice'         => count($facts_list) > 1 ? (string) $post['division'] : (string) $facts_list[0]['division'],
      'first_comment' => $in_comment ? $comment : '',
      'link'          => $link,
      'media'         => $media,
      'sale_day'      => (string) (ias_post_meta($post)['sale_day'] ?? ''),
      'generated_by'  => 'claude',
    ];

    unset($flags['gen_error_at'], $flags['gen_error'], $flags['stale'], $flags['edited_by'], $flags['released_by']);
    $flags['holds'] = $holds;
    $flags['fixes'] = $check['fixes'];
    $status = $holds ? 'held' : ($post['status'] === 'held' ? 'planned' : (string) $post['status']);
    if (in_array($post['status'], ['waiting', 'approved'], true)) $status = $holds ? 'held' : 'planned'; // a rewritten Lane A post needs a fresh yes

    ias_post_update($post_id, [
      'content'      => $content,
      'images_used'  => $images_used,
      'facts_hash'   => ias_facts_hash($post, $facts_list),
      'generated_at' => ias_now_sql(),
      'flags'        => $flags,
      'status'       => $status,
      'note'         => $holds ? 'Needs a decision: ' . $holds[0] : '',
      'approvals'    => in_array($post['status'], ['waiting', 'approved'], true) ? [] : $post['approvals'],
      'approved_at'  => in_array($post['status'], ['waiting', 'approved'], true) ? null : $post['approved_at'],
      'approved_by'  => in_array($post['status'], ['waiting', 'approved'], true) ? '' : $post['approved_by'],
    ]);

    ias_history_forget_post($post_id);
    $first = (int) ($facts_list[0]['id'] ?? 0);
    ias_history_add('opener', $parts['opener'], $first, $post_id);
    if ($parts['cta'] !== '') ias_history_add('cta', $parts['cta'], $first, $post_id);
    ias_history_add('cta_kind', $cta_kind, $first, $post_id);
    ias_history_add('template', $template, $first, $post_id);
    ias_history_add('hashtags', implode(' ', array_map('strtolower', $hashtags)), $first, $post_id);
    foreach ((array) $media['images'] as $img) {
      if ((int) $img['id'] <= 0) continue;
      foreach ($facts_list as $f) {
        if (in_array((int) $img['id'], (array) $f['photos'], true) || (int) $img['id'] === (int) $f['brochure']) ias_history_add('image', (string) (int) $img['id'], (int) $f['id'], $post_id);
      }
    }

    $tpl_label = ias_templates()[$template][0] ?? 'List';
    $cta_label = ias_cta_kinds()[$cta_kind][0] ?? $cta_kind;
    ias_post_trail($post_id, ($forced ? 'Rewritten on request' : 'Draft written') . ' (' . $tpl_label . ', ' . $cta_label . ')' . ($holds ? ' — held: ' . $holds[0] : ''), $actor);
    ias_log($holds ? 'held' : 'generated', (int) $post['listing_id'], [
      'post' => $post_id, 'type' => $post['type'],
      'summary' => ($holds ? 'Held: ' . implode(' ', $holds) : 'Draft written') . ' (' . $template . ', ' . $cta_kind . ')',
    ], $actor);
    /** A draft was written (Phase 4 sends Lane A posts for approval from here). */
    do_action('ias_post_generated', $post_id, $holds);
    return ['ok' => true, 'status' => $status, 'holds' => $holds];
  }
}

/** Re-check a draft a person edited; returns the holds (and stores the checked text). */
if (!function_exists('ias_recheck_post')) {
  function ias_recheck_post(array $post, string $text): array {
    $facts_list = ias_post_facts($post);
    [$money, $phones] = ias_allowed_figures($facts_list);
    return ias_check_draft($text, [
      'type'       => (string) $post['type'],
      'bidding'    => array_map(function ($f) { return (string) $f['bidding']; }, $facts_list),
      'money'      => $money,
      'phones'     => $phones,
      'fair_extra' => (string) ias_setting('fair_housing_extra', ''),
    ]);
  }
}
