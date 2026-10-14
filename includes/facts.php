<?php
/**
 * Intel Auctions Social — a listing's facts, read once, through the toolkit.
 *
 * Every post is written from this array and nothing else, so nothing is typed
 * twice and nothing is guessed: the address, town and county come from the
 * geocoded listing (`_ia_town` / `_ia_county`, stored by the toolkit — the
 * county is left blank rather than guessed), the date and how bidding works,
 * the starting bid and acreage, the open houses with their lengths, the
 * sellers, the lead and their phone from the team record, and the sale price
 * from the auction results. A few things the site has no field for are kept on
 * the listing by this plugin (`_ias_*`, set in the listing panel): the sale
 * kind, the parcels of a multi-parcel sale and the brochure cover.
 */

if (!defined('ABSPATH')) exit;

/** The ACF field holding the brochure (image or PDF); filterable like the toolkit's field helpers. */
if (!function_exists('ias_acf_field_brochure')) {
  function ias_acf_field_brochure(): string { return (string) apply_filters('ias_acf_field_brochure', 'brochure'); }
}
/** The free-text size field ("85.5 +/- acre farm") the toolkit's results code reads too. */
if (!function_exists('ias_acf_field_size')) {
  function ias_acf_field_size(): string { return (string) apply_filters('ias_acf_field_size', 'property_size'); }
}

/** A raw field: ACF when active, else the meta ACF would have written. */
if (!function_exists('ias_field')) {
  function ias_field(string $name, int $post_id, bool $format = true) {
    if ($name === '') return null;
    return function_exists('get_field') ? get_field($name, $post_id, $format) : get_post_meta($post_id, $name, true);
  }
}

/** Attachment ids from a gallery/image value in any of ACF's return formats. */
if (!function_exists('ias_attachment_ids')) {
  function ias_attachment_ids($value): array {
    $ids = [];
    foreach ((array) $value as $v) {
      if (is_array($v) && isset($v['ID'])) $v = $v['ID'];
      elseif (is_array($v) && isset($v['id'])) $v = $v['id'];
      elseif (is_object($v) && isset($v->ID)) $v = $v->ID;
      elseif (is_string($v) && !is_numeric($v) && $v !== '') $v = attachment_url_to_postid($v);
      if (is_numeric($v) && (int) $v > 0) $ids[] = (int) $v;
    }
    return array_values(array_unique($ids));
  }
}

/** "$250,000" / "250000" / "250k" → 250000.0; null when there is no number. */
if (!function_exists('ias_money_value')) {
  function ias_money_value(string $raw): ?float {
    $raw = strtolower(trim($raw));
    if ($raw === '' || !preg_match('/(\d[\d,]*(?:\.\d+)?)\s*(k|m)?/', $raw, $m)) return null;
    $v = (float) str_replace(',', '', $m[1]);
    if (($m[2] ?? '') === 'k') $v *= 1000;
    if (($m[2] ?? '') === 'm') $v *= 1000000;
    return $v > 0 ? $v : null;
  }
}

/**
 * How bidding works for one listing: 'phone' (Intel Phone Bidding), 'in_person'
 * or '' when the listing does not say — never both. The listing's own
 * `auction_type` field decides (Phone Bid = phone, anything else set = in
 * person); with it blank, the Auction Type category does. When the two
 * disagree the answer is '' with a note, and every post that has to state it
 * stops for a person.
 */
if (!function_exists('ias_bidding_type')) {
  function ias_bidding_type(int $id): array {
    $field = function_exists('ia_get_auction_type_value') ? ia_get_auction_type_value($id) : trim((string) ias_field(ia_acf_field_auction_type(), $id));
    $from_field = '';
    if ($field !== '') $from_field = ia_is_phone_bid($field) || stripos($field, 'phone') !== false ? 'phone' : 'in_person';

    $from_cat = '';
    foreach (ia_auction_group_names($id, 'type') as $name) {
      if (stripos($name, 'phone') !== false) { $from_cat = 'phone'; break; }
      if (stripos($name, 'person') !== false || stripos($name, 'live') !== false) $from_cat = 'in_person';
    }

    if ($from_field !== '' && $from_cat !== '' && $from_field !== $from_cat) {
      return ['', 'The listing\'s auction type says ' . ias_bidding_label($from_field) . ' but its Auction Type category says ' . ias_bidding_label($from_cat) . '.'];
    }
    $type = $from_field !== '' ? $from_field : $from_cat;
    return [$type, $type === '' ? 'The listing does not say whether bidding is in person or by Intel Phone Bidding.' : ''];
  }
}

if (!function_exists('ias_bidding_label')) {
  function ias_bidding_label(string $type): string {
    return $type === 'phone' ? 'Intel Phone Bidding' : ($type === 'in_person' ? 'an in-person auction' : 'not stated');
  }
}

/**
 * The listing's division, for the voice: farm_home | investment_rec | intel.
 * From the Auction Category (Summit Property sits under Investment and
 * Recreation); a listing with no category reads as Farm & Home when it has
 * acreage and as Intel general otherwise.
 */
if (!function_exists('ias_division_of')) {
  function ias_division_of(array $category_names, ?float $acres): string {
    foreach ($category_names as $name) {
      if (stripos($name, 'farm') !== false) return 'farm_home';
    }
    foreach ($category_names as $name) {
      if (preg_match('/investment|recreation|summit/i', $name)) return 'investment_rec';
    }
    foreach ($category_names as $name) {
      if (stripos($name, 'intel') !== false) return 'intel';
    }
    return ($acres !== null && $acres > 0) ? 'farm_home' : 'intel';
  }
}

/**
 * Real estate, or a liquidation / contents sale (no acreage, no single sale
 * price — its Results post is a thank-you). The listing panel's choice wins;
 * otherwise the words of the title decide.
 */
if (!function_exists('ias_sale_kind')) {
  function ias_sale_kind(int $id, string $title, ?float $acres, bool $use_choice = true): string {
    $set = $use_choice ? (string) get_post_meta($id, '_ias_sale_kind', true) : '';
    if (in_array($set, ['real_estate', 'liquidation'], true)) return $set;
    if (($acres === null || $acres <= 0) && preg_match('/\b(liquidation|contents|personal property|equipment|tools|inventory|business assets|household)\b/i', $title)) {
      return 'liquidation';
    }
    return 'real_estate';
  }
}

/**
 * Parcels of a multi-parcel sale from the listing panel, one per line:
 * "Parcel 1 | 45 acres | $250,000". [] when not set.
 */
if (!function_exists('ias_parcels')) {
  function ias_parcels(int $id): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) get_post_meta($id, '_ias_parcels', true)) as $line) {
      $parts = array_map('trim', explode('|', $line));
      if (($parts[0] ?? '') === '') continue;
      $out[] = ['name' => $parts[0], 'size' => $parts[1] ?? '', 'bid' => $parts[2] ?? ''];
    }
    return $out;
  }
}

/** The listing's brochure cover as an attachment id (the panel's choice, then the brochure field), or 0. */
if (!function_exists('ias_brochure_cover_id')) {
  function ias_brochure_cover_id(int $id): int {
    $set = (int) get_post_meta($id, '_ias_brochure_cover', true);
    if ($set > 0 && get_post_type($set) === 'attachment') return $set;
    $ids = ias_attachment_ids(ias_field(ias_acf_field_brochure(), $id));
    return $ids ? $ids[0] : 0;
  }
}

/** The street part of an address ("123 Farm Rd, Narvon, PA 17555, USA" → "123 Farm Rd"). */
if (!function_exists('ias_street_of')) {
  function ias_street_of(string $address, string $town): string {
    $parts = array_map('trim', explode(',', $address));
    if ($town !== '') {
      $i = array_search(strtolower($town), array_map('strtolower', $parts), true);
      if ($i !== false && $i > 0) return implode(', ', array_slice($parts, 0, (int) $i));
    }
    return $parts[0] ?? '';
  }
}

/** A Y-m-d marker kept on the listing ('' when never set). */
if (!function_exists('ias_listing_day')) {
  function ias_listing_day(int $id, string $key): string {
    $v = (string) get_post_meta($id, $key, true);
    return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : '';
  }
}

/**
 * Everything a post about listing $id may say, as plain data (DateTimeImmutable
 * for times, site time zone). See the file comment for where each comes from.
 */
if (!function_exists('ias_listing_facts')) {
  function ias_listing_facts(int $id): array {
    $post  = get_post($id);
    $title = $post ? html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8') : '';
    $tz    = ia_wp_tz();

    $address = ia_get_map_address_string($id);
    $town    = trim((string) get_post_meta($id, '_ia_town', true));
    if ($town === '') $town = ia_auction_town($id);
    $state   = trim((string) get_post_meta($id, '_ia_state', true));
    if ($state === '') $state = ia_auction_state($id);
    $county  = trim((string) get_post_meta($id, '_ia_county', true)); // stored from the map pin; never guessed

    $date = ia_parse_datetime((string) ias_field(ia_acf_field_auction_date(), $id));
    if ($date) $date = $date->setTimezone($tz);

    [$bidding, $bidding_note] = ias_bidding_type($id);

    $bid_raw = trim((string) ias_field(ia_acf_field_starting_bid(), $id));
    $size_raw = trim((string) ias_field(ias_acf_field_size(), $id));
    $size = $size_raw !== '' ? ia_parse_property_size($size_raw, $id) : null;
    $acres = ($size && $size['unit'] === 'ACR') ? (float) $size['value'] : null;
    $sqft  = ($size && $size['unit'] === 'FTK') ? (float) $size['value'] : null;

    $lead = null;
    $lead_id = ia_auction_lead_id($id);
    if ($lead_id > 0) {
      $lead = [
        'id'    => $lead_id,
        'name'  => html_entity_decode(get_the_title($lead_id), ENT_QUOTES, 'UTF-8'),
        'phone' => ia_team_member_field($lead_id, ia_acf_field_team_phone()),
        'email' => function_exists('ia_acf_field_team_email') ? ia_team_member_field($lead_id, ia_acf_field_team_email()) : '',
      ];
    }
    $office = function_exists('ia_business_phone') ? ia_business_phone() : '717-917-5844';

    // Sale price: the figure entered on Post Auction Results, then the listing's own results field.
    $price_raw = trim((string) (ia_results_figures($id)['price'] ?? ''));
    if ($price_raw === '') $price_raw = trim((string) ias_field('auction_results', $id));
    $price = ($price_raw !== '' && ias_money_value($price_raw) !== null) ? ia_results_format_price($price_raw) : '';

    $categories = ia_auction_group_names($id, 'category');
    $division   = ias_division_of($categories, $acres);

    $gallery  = ias_attachment_ids(ias_field(ia_acf_field_gallery(), $id));
    $featured = (int) get_post_thumbnail_id($id);
    $shared   = function_exists('ia_announced_thumb_id') ? ia_announced_thumb_id(false) : 0;
    if ($featured > 0 && $featured === $shared) $featured = 0; // the shared "recently announced" picture is not a photo of this place
    $photos = array_values(array_unique(array_filter(array_merge($featured ? [$featured] : [], $gallery))));

    // The video: the site's field (a YouTube link, say) and the file itself when there is one — uploaded on
    // Property Videos (0.22.0: on OneUp's storage, `_ias_youtube`), else a field that is a direct file.
    $video = trim((string) ias_field(ia_acf_field_video(), $id));
    $video_link = $video !== '' && !preg_match('/\.(mp4|mov|m4v|webm)(\?|$)/i', $video) ? $video : '';
    $video_file_url = function_exists('ias_youtube_file') ? ias_youtube_file($id) : '';
    if ($video_file_url === '' && $video !== '' && $video_link === '') $video_file_url = $video;
    $video_file = $video_file_url !== '';

    $excerpt = function_exists('ia_get_excerpt_plain') ? ia_get_excerpt_plain($id) : '';
    if ($excerpt === '' && $post) $excerpt = trim(wp_strip_all_tags((string) $post->post_content));
    $excerpt = trim((string) preg_replace('/\s+/', ' ', $excerpt));

    // The full listing already went to Facebook through the toolkit's webhook.
    $webhook = '';
    if (function_exists('ia_social_full_pushed') && ia_social_full_pushed($id)) {
      $webhook = ias_listing_day($id, '_ia_social_pushed_upcoming') ?: ias_listing_day($id, '_ia_social_pushed');
    }

    $kind = ias_sale_kind($id, $title, $acres);
    $parcels = ias_parcels($id);

    return [
      'id'          => $id,
      'title'       => $title,
      'status'      => $post ? (string) $post->post_status : '',
      'password'    => $post ? $post->post_password !== '' : false,
      'stage'       => ia_auction_stage($id),
      'url'         => (string) get_permalink($id),
      'address'     => $address,
      'street'      => ias_street_of($address, $town),
      'town'        => $town,
      'state'       => $state,
      'county'      => $county,
      'date'        => $date,
      'has_time'    => $date ? $date->format('H:i') !== '00:00' : false, // a date-only field reads as midnight; no sale starts then
      'sale_day'    => $date ? $date->format('Y-m-d') : '',
      'bidding'     => $bidding,
      'bidding_note'=> $bidding_note,
      'starting_bid_raw' => $bid_raw,
      'starting_bid'     => $bid_raw !== '' ? ias_money_value($bid_raw) : null,
      'size_raw'    => $size_raw,
      'acres'       => $acres,
      'sqft'        => $sqft,
      'open_houses' => ia_open_houses($id),
      'sellers'     => trim(wp_strip_all_tags((string) (is_scalar(ias_field(ia_acf_field_sellers(), $id)) ? ias_field(ia_acf_field_sellers(), $id) : ''))),
      'lead'        => $lead,
      'phone'       => ($lead && $lead['phone'] !== '') ? $lead['phone'] : $office,
      'office_phone'=> $office,
      'price_raw'   => $price_raw,
      'price'       => $price,
      'categories'  => $categories,
      'division'    => $division,
      'kind'        => $kind,
      'parcels'     => $parcels,
      'multi_parcel'=> count($parcels) > 1 || (bool) preg_match('/\b(parcel|tract)\s*#?\s*2\b/i', $title . ' ' . $excerpt),
      'gallery'     => $gallery,
      'featured'    => $featured,
      'photos'      => $photos,
      'has_photos'  => count($photos) > 0,
      'video'       => $video,
      'video_link'  => $video_link,      // the field when it is a link to watch (YouTube, Vimeo), not a file
      'video_file'  => $video_file,      // there is a file a post can carry as its video
      'video_file_url' => $video_file_url,
      'brochure'    => ias_brochure_cover_id($id),
      'excerpt'     => function_exists('mb_substr') ? mb_substr($excerpt, 0, 900) : substr($excerpt, 0, 900),
      'announced_at'=> ias_listing_day($id, '_ias_announced_at'),
      'promoted_at' => ias_listing_day($id, '_ias_promoted_at'),
      'photos_at'   => ias_listing_day($id, '_ias_photos_at'),
      'webhook_pushed' => $webhook,
      'published_at' => $post ? substr((string) $post->post_date, 0, 10) : '',
      'posts_cancelled' => (string) get_post_meta($id, '_ias_posts_cancelled', true),
    ];
  }
}

/**
 * The facts a post's text may use, as the plain JSON Claude is handed — only
 * what is known, with times written out the way a post would say them.
 */
if (!function_exists('ias_facts_for_prompt')) {
  function ias_facts_for_prompt(array $f): array {
    $out = [
      'property'   => $f['title'],
      'address'    => $f['address'],
      'street'     => $f['street'],
      'town'       => $f['town'],
      'county'     => $f['county'] !== '' ? $f['county'] . (stripos($f['county'], 'county') === false ? ' County' : '') : '',
      'state'      => $f['state'],
      'auction_date' => $f['date'] ? $f['date']->format('l, F j, Y') : '',
      'auction_time' => $f['date'] && !empty($f['has_time']) ? $f['date']->format('g:i a') : '', // unknown, not "12:00 am"
      'bidding'    => $f['bidding'] === 'phone' ? 'Intel Phone Bidding — buyers bid by phone with Intel (the post must say so, once)'
                    : ($f['bidding'] === 'in_person' ? 'In-person auction on site — the usual kind, so the post need not say how bidding works' : ''),
      'starting_bid' => $f['starting_bid_raw'],
      'size'       => $f['size_raw'],
      'open_houses' => array_map(function ($oh) {
        return ia_open_house_format($oh, 'range', 'l, F j,') . ' (' . ia_open_house_hours_label((float) $oh['hours']) . ')';
      }, $f['open_houses']),
      'sellers'    => $f['sellers'],
      'lead_name'  => $f['lead']['name'] ?? '',
      'lead_phone' => $f['lead']['phone'] ?? '',
      'office_phone' => $f['office_phone'],
      'sale_type'  => $f['kind'] === 'liquidation' ? 'liquidation / contents sale (no acreage, no single sale price)' : 'real estate',
      'division'   => ias_division_label($f['division']),
      'parcels'    => array_map(function ($p) { return trim($p['name'] . ($p['size'] !== '' ? ', ' . $p['size'] : '') . ($p['bid'] !== '' ? ', starting bid ' . $p['bid'] : '')); }, $f['parcels']),
      'description' => $f['excerpt'],
    ];
    if ($f['price'] !== '') $out['sale_price'] = $f['price'];
    return array_filter($out, function ($v) { return $v !== '' && $v !== []; });
  }
}
