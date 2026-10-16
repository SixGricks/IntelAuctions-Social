<?php
/**
 * Intel Auctions Social — the networks a post can go to.
 *
 * Facebook and Google Business Profile (gbp.php) are sent. Instagram, X and
 * LinkedIn are provisioned (DJ, Oct 13, 2026: "provision for more social
 * networks now"): each has its rules here, an account id and a preview switch
 * in Settings, a renderer that turns the same post into that network's
 * request, and a preview on the post view. None of them is sent yet — that
 * needs its own Verify and its own record of what was sent, the way Facebook
 * and Google have. Their switch shows a preview only; it is not a posting
 * switch, so it never appears among the switches (there is no dry run, DJ,
 * Oct 14, 2026).
 *
 * The words are Facebook's, adapted without a Claude call: Instagram takes
 * them with "link in our bio" (its captions carry no clickable links) and
 * square crops; LinkedIn takes them with the link at the end; X gets one
 * short line built from the listing's facts — never from Claude's prose — so
 * nothing invented can reach it. A person edits the Facebook text and the
 * others follow. Every network's link is tagged with its own utm_source.
 */

if (!defined('ABSPATH')) exit;

/**
 * Network => rules: label, OneUp's social_network_type, the most characters
 * and pictures a post may carry, where the link goes (comment | bio | text),
 * hashtags kept, the crop it uses, whether a post needs a picture, and
 * whether it can be sent live (only Facebook so far).
 */
if (!function_exists('ias_channels')) {
  function ias_channels(): array {
    return (array) apply_filters('ias_channels', [
      'facebook'  => ['label' => 'Facebook',  'oneup_type' => 'Facebook',  'text_max' => 63206, 'images_max' => 10, 'link' => 'comment', 'hashtags' => 3,  'crop' => '',          'needs_media' => false, 'live' => true],
      'gbp'       => ['label' => 'Google Business Profile', 'short' => 'Google', 'oneup_type' => 'GBP', 'text_max' => 1500, 'images_max' => 1, 'link' => 'button', 'hashtags' => 0, 'crop' => '', 'needs_media' => false, 'live' => true],
      'instagram' => ['label' => 'Instagram', 'oneup_type' => 'Instagram', 'text_max' => 2200,  'images_max' => 10, 'link' => 'bio',     'hashtags' => 10, 'crop' => 'square',    'needs_media' => true,  'live' => false],
      'x'         => ['label' => 'X',         'oneup_type' => 'X',         'text_max' => 280,   'images_max' => 4,  'link' => 'text',    'hashtags' => 2,  'crop' => 'landscape', 'needs_media' => false, 'live' => false],
      'linkedin'  => ['label' => 'LinkedIn',  'oneup_type' => 'LinkedIn',  'text_max' => 3000,  'images_max' => 9,  'link' => 'text',    'hashtags' => 3,  'crop' => 'landscape', 'needs_media' => false, 'live' => false],
    ]);
  }
}

if (!function_exists('ias_channel')) {
  function ias_channel(string $ch): array {
    return ias_channels()[$ch] ?? [];
  }
}

/**
 * A network's settings: ['on' => bool, 'account' => OneUp account id].
 * Facebook's are the page id above. Google's also carry 'name' (the
 * location's name in OneUp's lists) and 'types'; its 'on' posts it. For
 * Instagram, X and LinkedIn 'on' shows their preview only.
 */
if (!function_exists('ias_channel_settings')) {
  function ias_channel_settings(string $ch): array {
    if ($ch === 'facebook') return ['on' => true, 'account' => (string) ias_setting('oneup_account_id', '')];
    $all = (array) ias_setting('networks', []);
    $one = (array) ($all[$ch] ?? []);
    $out = ['on' => !empty($one['on']), 'account' => trim((string) ($one['account'] ?? ''))];
    if ($ch === 'gbp') $out += ['name' => trim((string) ($one['name'] ?? '')), 'types' => array_values(array_map('strval', (array) ($one['types'] ?? [])))];
    return $out;
  }
}

/** The networks besides Facebook that are switched on: Google (posted), and those previewed. */
if (!function_exists('ias_channels_extra_on')) {
  function ias_channels_extra_on(): array {
    $out = [];
    foreach (array_keys(ias_channels()) as $ch) if ($ch !== 'facebook' && ias_channel_settings($ch)['on']) $out[] = $ch;
    return $out;
  }
}

/**
 * One line per network besides Facebook that posts and is switched on, for
 * the folded switches strip: [class, words]. Only networks that can post say
 * anything (Google); a preview is not a switch.
 */
if (!function_exists('ias_channels_summary')) {
  function ias_channels_summary(): array {
    $out = [];
    foreach (ias_channels_extra_on() as $ch) {
      $state = (array) apply_filters('ias_channel_state', [], $ch); // a network that can post says where it stands
      if ($state) $out[] = $state;
    }
    return $out;
  }
}

/** The listing link with this network as its utm_source. */
if (!function_exists('ias_channel_link')) {
  function ias_channel_link(string $link, string $ch): string {
    return $link === '' ? '' : add_query_arg('utm_source', $ch, $link);
  }
}

/** Characters as X counts them: every web address is 23. */
if (!function_exists('ias_x_length')) {
  function ias_x_length(string $text): int {
    $plain = (string) preg_replace('~https?://\S+~i', str_repeat('x', 23), $text);
    return function_exists('mb_strlen') ? mb_strlen($plain) : strlen($plain);
  }
}

/** Cut text at the last paragraph (else sentence, else word) that fits $max characters. */
if (!function_exists('ias_fit_text')) {
  function ias_fit_text(string $text, int $max): string {
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max - 1);
    foreach (["\n\n", '. ', ' '] as $sep) {
      $at = mb_strrpos($cut, $sep);
      if ($at !== false && $at > $max * 0.5) return rtrim(mb_substr($cut, 0, $at + ($sep === '. ' ? 1 : 0))) . ($sep === ' ' ? '…' : '');
    }
    return rtrim($cut) . '…';
  }
}

/**
 * X's one line, from the facts (never Claude's prose): what it is, where,
 * when — and the link. Pure: $facts_list as ias_post_facts() gives it.
 */
if (!function_exists('ias_x_line')) {
  function ias_x_line(string $type, array $facts_list): string {
    $f = $facts_list[0] ?? [];
    $where = trim((string) (($f['street'] ?? '') !== '' ? $f['street'] : ($f['title'] ?? '')) . (($f['town'] ?? '') !== '' ? ', ' . $f['town'] : ''));
    $when = '';
    if (!empty($f['date']) && $f['date'] instanceof DateTimeInterface) {
      $when = $f['date']->format('D, M j') . (!empty($f['has_time']) ? ' at ' . $f['date']->format('g:i a') : '');
    }
    $n = count($facts_list);
    switch ($type) {
      case 'roundup':
        $streets = array_values(array_filter(array_map(function ($x) { return (string) (($x['street'] ?? '') !== '' ? $x['street'] : ($x['title'] ?? '')); }, $facts_list)));
        return 'New this week at Intel Auctions: ' . $n . ' ' . ($n === 1 ? 'sale' : 'sales') . ($streets ? ' — ' . implode(', ', array_slice($streets, 0, 3)) . ($n > 3 ? ' and more' : '') : '') . '.';
      case 'results':
        $price = (string) ($f['price'] ?? '');
        $sold = is_numeric(str_replace([',', '$'], '', $price)) && ($f['kind'] ?? '') !== 'liquidation' ? ' — $' . number_format((float) str_replace([',', '$'], '', $price)) : '';
        return 'Sold at auction: ' . $where . $sold . '. Thank you to everyone who bid.';
      case 'open_house':
        $oh = (array) (($f['open_houses'] ?? [])[0] ?? []);
        $at = $oh && function_exists('ia_open_house_format') ? ia_open_house_format($oh, 'range', 'D, M j,') : '';
        return 'Open house' . ($at !== '' ? ' ' . $at : '') . ': ' . $where . '.' . ($when !== '' ? ' Auction ' . $when . '.' : '');
      case 'auction_week':
        return ($n > 1 ? $n . ' auctions' : 'Auction') . ($when !== '' ? ' ' . $when : ' this week') . ': ' . $where . ($n > 1 ? ' and more' : '') . '.';
      default: // listing
        $bid = trim((string) ($f['starting_bid_raw'] ?? ''));
        return 'Just listed: ' . $where . '.' . ($when !== '' ? ' Auction ' . $when . '.' : '') . ($bid !== '' ? ' Starting bid ' . $bid . '.' : '');
    }
  }
}

/**
 * Text with every sentence that carries a phone number taken out (Google
 * Business Profile refuses those posts), with a short question leading to it
 * ("Questions?"). A line that was only that goes too. Pure.
 */
if (!function_exists('ias_strip_phones')) {
  function ias_strip_phones(string $text): string {
    $phone = '/(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]\d{4}\b/';
    $lines = [];
    foreach (preg_split('/\n/', $text) as $line) {
      if (!preg_match($phone, $line)) { $lines[] = $line; continue; }
      $parts = preg_split('/(?<=[.!?])\s+/', $line);
      $keep = [];
      foreach ($parts as $part) {
        if (!preg_match($phone, $part)) { $keep[] = $part; continue; }
        // "Questions? Call Jacob at …": the short question that led to the number goes with it.
        $prev = end($keep);
        if ($prev !== false && substr(rtrim($prev), -1) === '?' && str_word_count($prev) <= 4) array_pop($keep);
      }
      $line = trim(implode(' ', $keep));
      if ($line !== '') $lines[] = $line;
    }
    return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
  }
}

/** The post's words for one network ('' when it has none yet). */
if (!function_exists('ias_channel_text')) {
  function ias_channel_text(array $post, string $ch, ?array $facts_list = null): string {
    $c = (array) ($post['content'] ?? []);
    $text = trim((string) ($c['text'] ?? ''));
    if ($text === '' || $ch === 'facebook') return $text;
    $rules = ias_channel($ch);
    $link = ias_channel_link((string) ($c['link'] ?? ''), $ch);
    $tags = array_slice(array_values((array) ($c['hashtags'] ?? [])), 0, (int) ($rules['hashtags'] ?? 3));
    // Facebook's text ends with its hashtags (and with "Details: <link>" when links can't go in a comment): set them aside.
    $body = (string) preg_replace('/\n\n(#\S+\s*)+$/u', '', $text);
    $body = trim((string) preg_replace('~\n\nDetails: https?://\S+$~', '', $body));
    if ($ch === 'x') {
      // A person's own post (0.25.0) keeps their words, fitted; every other post's line comes from the facts, never Claude's prose.
      $line = ($post['type'] ?? '') === 'manual' ? ias_fit_text($body, 280) : ias_x_line((string) $post['type'], $facts_list ?? ias_post_facts($post));
      $tail = $link !== '' ? ' ' . $link : '';
      $hash = $tags ? ' ' . implode(' ', $tags) : '';
      $out = $line . $tail . $hash;
      while (ias_x_length($out) > 280 && $hash !== '') { $tags = array_slice($tags, 0, -1); $hash = $tags ? ' ' . implode(' ', $tags) : ''; $out = $line . $tail . $hash; }
      if (ias_x_length($out) > 280) $out = ias_fit_text($line, 280 - ias_x_length($tail)) . $tail;
      return $out;
    }
    if ($ch === 'gbp') {
      // Google refuses a post with a phone number in it (the profile has its own Call button): the sentence goes.
      $body = ias_strip_phones($body);
      return ias_fit_text($body, (int) $rules['text_max']);
    }
    if ($ch === 'instagram') {
      $bio = 'Photos, terms and the full listing are on intelauctions.com — link in our bio.';
      $tags_line = $tags ? "\n\n" . implode(' ', $tags) : '';
      return ias_fit_text($body, (int) $rules['text_max'] - mb_strlen("\n\n" . $bio . $tags_line)) . "\n\n" . $bio . $tags_line;
    }
    // LinkedIn (and any later network that takes a link in the text).
    $more = $link !== '' ? "\n\nPhotos, terms and the full listing: " . $link : '';
    $tags_line = $tags ? "\n\n" . implode(' ', $tags) : '';
    return ias_fit_text($body, (int) ($rules['text_max'] ?? 3000) - mb_strlen($more . $tags_line)) . $more . $tags_line;
  }
}

/**
 * One network's OneUp request for this post (the shape ias_render_facebook()
 * gives, which Facebook keeps using unchanged): the network's own account,
 * words and first pictures — in its crop where it has one.
 */
if (!function_exists('ias_render_channel')) {
  function ias_render_channel(array $post, string $ch, ?array $facts_list = null): array {
    if ($ch === 'facebook') return ias_render_facebook($post);
    if ($ch === 'gbp') return ias_render_gbp($post, $facts_list);
    $rules = ias_channel($ch);
    $c = (array) $post['content'];
    $media = (array) ($c['media'] ?? []);
    $params = [
      'category_id'         => (string) ias_setting('oneup_category_id', ''),
      'social_network_id'   => ias_channel_settings($ch)['account'],
      'scheduled_date_time' => !empty($post['scheduled_at']) ? substr((string) $post['scheduled_at'], 0, 16) : '',
      'content'             => ias_channel_text($post, $ch, $facts_list),
    ];
    $alt = [];
    if (($media['kind'] ?? '') === 'video' && !empty($media['video_url']) && $ch !== 'instagram') {
      $endpoint = 'schedule_video';
      $params['video_url'] = (string) $media['video_url'];
    } elseif (!empty($media['images'])) {
      $endpoint = 'schedule_image';
      $imgs = array_slice((array) $media['images'], 0, (int) $rules['images_max']);
      $crop = (string) ($rules['crop'] ?? '');
      $params['image_url'] = implode('~~', array_map(function ($i) use ($crop) { return (string) ($crop !== '' && !empty($i['crops'][$crop]) ? $i['crops'][$crop] : $i['url']); }, $imgs));
      $alt = array_map(function ($i) { return (string) $i['alt']; }, $imgs);
    } else {
      $endpoint = 'schedule_text';
    }
    $ep = ias_oneup_endpoints()[$endpoint];
    return ['action' => 'create', 'channel' => $ch, 'endpoint' => $endpoint, 'method' => $ep[0], 'url' => IAS_ONEUP_BASE . $ep[1], 'params' => $params, 'alt' => $alt];
  }
}

/**
 * The Google Business Profile request: an "update" post with the post's
 * words for Google, its first picture (a video post takes the listing's best
 * photo instead — Google shows a picture, not a link), and a Learn more
 * button to the listing. It goes a minute after Facebook's, so the two are
 * never one row on OneUp's list (a post is found there by its words and time).
 * The picture is not also added to the profile's Photos.
 */
if (!function_exists('ias_render_gbp')) {
  function ias_render_gbp(array $post, ?array $facts_list = null): array {
    $c = (array) $post['content'];
    $media = (array) ($c['media'] ?? []);
    $when = '';
    if (!empty($post['scheduled_at'])) {
      try { $when = (new DateTimeImmutable((string) $post['scheduled_at'], ia_wp_tz()))->modify('+1 minute')->format('Y-m-d H:i'); } catch (\Exception $e) { $when = ''; }
    }
    $params = [
      'category_id'         => (string) ias_setting('oneup_category_id', ''),
      'social_network_id'   => ias_channel_settings('gbp')['account'],
      'scheduled_date_time' => $when,
      'content'             => ias_channel_text($post, 'gbp', $facts_list),
      'google_post_type'    => 'update',
    ];
    $link = ias_channel_link((string) ($c['link'] ?? ''), 'gbp');
    if ($link !== '') { $params['cta_button'] = 'LEARN_MORE'; $params['cta_button_url'] = $link; }
    $img = null;
    if (!empty($media['images'])) {
      $img = (array) array_values((array) $media['images'])[0];
    } elseif (($media['kind'] ?? '') === 'video') {
      $facts_list = $facts_list ?? ias_post_facts($post);
      $best = $facts_list ? ias_media_best_photo($facts_list[0]) : 0;
      if ($best > 0) $img = ias_media_image($best, $facts_list[0]);
    }
    $alt = [];
    if ($img && !empty($img['url'])) {
      $endpoint = 'schedule_image';
      $params['image_url'] = (string) $img['url'];
      $params['image_to_gbp_photo'] = 'false';
      $alt = [(string) ($img['alt'] ?? '')];
    } else {
      $endpoint = 'schedule_text';
    }
    $ep = ias_oneup_endpoints()[$endpoint];
    return ['action' => 'create', 'channel' => 'gbp', 'endpoint' => $endpoint, 'method' => $ep[0], 'url' => IAS_ONEUP_BASE . $ep[1], 'params' => $params, 'alt' => $alt];
  }
}

/** What stops this request from being right for its network ([] = nothing). */
if (!function_exists('ias_channel_problems')) {
  function ias_channel_problems(array $req, string $ch): array {
    $rules = ias_channel($ch);
    $out = [];
    if ((string) ($req['params']['social_network_id'] ?? '') === '') $out[] = 'no ' . $rules['label'] . ' account id in Settings';
    $text = (string) ($req['params']['content'] ?? '');
    $len = $ch === 'x' ? ias_x_length($text) : mb_strlen($text);
    if ($len > (int) $rules['text_max']) $out[] = $len . ' characters, over ' . $rules['label'] . '\'s ' . number_format((int) $rules['text_max']);
    if (!empty($rules['needs_media']) && ($req['endpoint'] ?? '') === 'schedule_text') $out[] = $rules['label'] . ' needs a picture';
    if (($req['endpoint'] ?? '') === 'schedule_video' && $ch === 'instagram') $out[] = 'Instagram cannot take a video link';
    return $out;
  }
}

