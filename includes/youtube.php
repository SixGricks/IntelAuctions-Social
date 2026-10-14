<?php
/**
 * Intel Auctions Social — property videos to YouTube, one upload (0.22.0).
 *
 * DJ, Oct 14, 2026: "The goal is to cut down on human workload. I want one
 * time upload, title, description and not to think about it again. I would
 * love to just give you the file and then have you upload to Youtube with
 * description as part of a single property workflow."
 *
 * One step for a person: **Upload video file** on the listing's row of the
 * toolkit's Property Videos (its `ia_missing_video_row` action, 4.69.0). The
 * browser sends the file straight to OneUp's storage — GET uploadmedia hands
 * the site a presigned address good for 20 minutes, and the file never passes
 * through the website, so the host's upload limit and disk play no part.
 * Then, with nobody touching it again:
 *
 *   To YouTube   schedulevideopost to the channel (`networks.youtube`) a few
 *                minutes ahead, into the chosen playlist. The title and the
 *                description are built from the listing's facts
 *                (ias_youtube_words()) — never Claude's prose, so nothing
 *                invented can reach it and nobody has to approve it — with
 *                the listing link, a Subscribe line and the business's name,
 *                address and phone. Never the starting bid (it changes, and
 *                a YouTube description is not corrected from here).
 *   The link     once OneUp has published it, YouTube's link goes on the
 *                listing through the toolkit's ia_video_save() (the site's
 *                video field, Skip video cleared, the Next Step card closed)
 *                — never over a link a person put there. The video's id
 *                comes from OneUp's published list, else its YouTube
 *                analytics, else the channel's public feed, matched by title.
 *   Facebook     the same file is the property video Facebook posts carry
 *                natively (an open house's video: facts `video_file_url`),
 *                and the Listing post's first comment carries the YouTube
 *                link, so Facebook sends people to the channel too.
 *
 * The rules are Facebook's and Google's. Its own switch (**YouTube** on, the
 * master switch on, not paused), its own Verify (`ias_youtube_verify`), its
 * own record on the listing (`_ias_youtube`, never a post's flags), never on
 * a Sunday, and the listing must be public (the description links to it). An
 * attempt is recorded before it is sent (`sending`) and looked for on
 * OneUp's lists before anything else happens; a lost one is never sent again
 * by the plugin — a person is asked, after checking the channel. Off means
 * off: a copy on OneUp that has not gone out yet is taken back (by its id)
 * and goes again when YouTube is on. Taking back needs only the key.
 *
 * The record's `state`: uploading (an address was handed out, the file not
 * confirmed) → ready (the file is on OneUp's storage) → sent (on OneUp's
 * schedule) → live (on YouTube; `url` once its link is known). Off the path:
 * failed (OneUp could not publish it, or refused it three times: Try again)
 * and lost (its answer was lost and it was not found: Send it again).
 */

if (!defined('ABSPATH')) exit;

if (!defined('IAS_YOUTUBE_TOOLKIT')) define('IAS_YOUTUBE_TOOLKIT', '4.69.0'); // ia_video_save(), the Property Videos row hook
if (!defined('IAS_YOUTUBE_META'))    define('IAS_YOUTUBE_META', '_ias_youtube');
if (!defined('IAS_YOUTUBE_MAX'))     define('IAS_YOUTUBE_MAX', 4 * 1024 * 1024 * 1024); // 4 GB: Facebook's limit for a video, well inside YouTube's

/* ---------------- Settings and switches ---------------- */

/** The channel's settings: ['on', 'account', 'name', 'playlist', 'playlist_name']. */
if (!function_exists('ias_youtube_settings')) {
  function ias_youtube_settings(): array {
    $one = (array) (((array) ias_setting('networks', []))['youtube'] ?? []);
    return [
      'on'            => !empty($one['on']),
      'account'       => trim((string) ($one['account'] ?? '')),
      'name'          => trim((string) ($one['name'] ?? '')),
      'playlist'      => trim((string) ($one['playlist'] ?? '')),
      'playlist_name' => trim((string) ($one['playlist_name'] ?? '')),
    ];
  }
}

/** What stops the plugin talking to OneUp about the channel at all ([] = nothing). */
if (!function_exists('ias_youtube_blockers_read')) {
  function ias_youtube_blockers_read(): array {
    $out = [];
    if (!ias_setting('master', false)) $out[] = 'the master switch is off';
    if (ias_oneup_key() === '') $out[] = 'no OneUp key (Settings → OneUp)';
    if ((string) ias_setting('oneup_category_id', '') === '') $out[] = 'no OneUp category chosen';
    if (ias_youtube_settings()['account'] === '') $out[] = 'no YouTube channel chosen';
    return $out;
  }
}

/** Is the toolkit new enough (ia_video_save() and the Property Videos row hook)? */
if (!function_exists('ias_youtube_toolkit_ok')) {
  function ias_youtube_toolkit_ok(): bool {
    return defined('IA_TOOLKIT_VERSION') && version_compare((string) IA_TOOLKIT_VERSION, IAS_YOUTUBE_TOOLKIT, '>=') && function_exists('ia_video_save');
  }
}

/** What stops a file being uploaded for the channel ([] = it can be): the channel set up and verified. */
if (!function_exists('ias_youtube_upload_blockers')) {
  function ias_youtube_upload_blockers(): array {
    $out = ias_youtube_blockers_read();
    if (!ias_youtube_toolkit_ok()) $out[] = 'the Intel Auctions Toolkit needs updating to ' . IAS_YOUTUBE_TOOLKIT . ' (it puts the link on the listing)';
    if (!$out && !ias_youtube_verified()) $out[] = 'Verify for YouTube has not passed for this channel';
    return $out;
  }
}

/** What stops a video being sent to YouTube for real ([] = it goes). */
if (!function_exists('ias_youtube_blockers')) {
  function ias_youtube_blockers(): array {
    $out = [];
    if (!ias_youtube_settings()['on']) $out[] = 'YouTube is off';
    if (ias_setting('paused', false)) $out[] = 'posting is paused';
    $out = array_merge($out, ias_youtube_upload_blockers());
    if (!apply_filters('ias_live_pushing_built', true)) $out[] = 'live sending is turned off on this site';
    return array_values(array_unique($out));
  }
}

/** The switches that are off for YouTube ([] = none): any of them, and a copy not yet out comes back off. */
if (!function_exists('ias_youtube_switched_off')) {
  function ias_youtube_switched_off(): array {
    $out = [];
    if (!ias_setting('master', false)) $out[] = 'the master switch is off';
    if (ias_setting('paused', false)) $out[] = 'posting is paused';
    if (!ias_youtube_settings()['on']) $out[] = 'YouTube is off';
    return $out;
  }
}

/** Has Verify for YouTube passed for the channel set now (same key, category and channel; OneUp's time the site's)? */
if (!function_exists('ias_youtube_verified')) {
  function ias_youtube_verified(): bool {
    $v = get_option('ias_youtube_verify', []);
    return is_array($v) && !empty($v['ok']) && ($v['tz_offset'] ?? null) === 0
      && (string) ($v['key'] ?? '') === ias_oneup_key_print()
      && (string) ($v['category'] ?? '') === (string) ias_setting('oneup_category_id', '')
      && (string) ($v['account'] ?? '') !== '' && (string) ($v['account'] ?? '') === ias_youtube_settings()['account'];
  }
}

/** The channel's name on OneUp's lists ('' = not known yet). */
if (!function_exists('ias_youtube_name')) {
  function ias_youtube_name(): string {
    $y = ias_youtube_settings();
    if ($y['name'] !== '') return $y['name'];
    $a = ias_oneup_directory_account($y['account']);
    return $a ? (string) $a['name'] : '';
  }
}

/** Is this OneUp list row the channel's (by its account name, when the row and the name are known)? */
if (!function_exists('ias_youtube_row_ok')) {
  function ias_youtube_row_ok(array $row): bool {
    $name = ias_youtube_name();
    $user = trim((string) ($row['social_network_username'] ?? ''));
    return $name === '' || $user === '' || $user === $name;
  }
}

/** The channel's address, and its Subscribe link ('' when the channel id is not a UC… id). */
if (!function_exists('ias_youtube_channel_url')) {
  function ias_youtube_channel_url(bool $subscribe = false): string {
    $id = ias_youtube_settings()['account'];
    if (!preg_match('/^UC[A-Za-z0-9_-]{22}$/', $id)) return '';
    return 'https://www.youtube.com/channel/' . $id . ($subscribe ? '?sub_confirmation=1' : '');
  }
}

/* ---------------- The words (pure) ---------------- */

/**
 * The video's title and description from the listing's facts — nothing a
 * person did not enter, so nothing needs approving. $f as
 * ias_listing_facts() gives it (date a DateTimeImmutable or null); $o:
 * 'link' (the listing, tagged for YouTube), 'subscribe' (the channel's
 * Subscribe link, or ''), 'business' (name, street, city, region, postal,
 * phone — the canonical NAP), 'extra' (fair-housing phrases from Settings).
 * YouTube takes a title of 100 characters and a description of 5,000, with no
 * angle brackets in either. Returns ['title', 'text']. Pure.
 */
if (!function_exists('ias_youtube_words')) {
  function ias_youtube_words(array $f, array $o): array {
    $clean = function (string $s): string { return trim((string) preg_replace('/[ \t]+/', ' ', str_replace(['<', '>'], '', $s))); };
    $name = (string) (($f['street'] ?? '') !== '' ? $f['street'] : ($f['title'] ?? ''));
    $town = (string) ($f['town'] ?? '');
    $state = (string) ($f['state'] ?? '');
    $place = $town !== '' && stripos($name, $town) === false ? $town . ($state !== '' ? ', ' . $state : '') : '';
    $head = $clean($name . ($place !== '' ? ', ' . $place : ''));
    $date = (!empty($f['date']) && $f['date'] instanceof DateTimeInterface) ? $f['date'] : null;
    $tail = $date ? 'Auction ' . $date->format('M j, Y') : 'Property Walkthrough';
    $title = $head . ' | ' . $tail;
    if (mb_strlen($title . ' | Intel Auctions') <= 100) $title .= ' | Intel Auctions';
    if (mb_strlen($title) > 100) $title = rtrim(mb_substr($head, 0, 97 - mb_strlen(' | ' . $tail))) . '… | ' . $tail;
    $title = mb_substr($clean($title), 0, 100);

    $lines = [];
    $address = trim((string) ($f['address'] ?? ''));
    $lines[] = 'A walkthrough of ' . ($address !== '' ? $address : $head) . '.';
    if (!empty($f['size_raw'])) $lines[] = 'The property: ' . $f['size_raw'] . '.';
    if ($date) {
      $lines[] = 'Auction: ' . $date->format('l, F j, Y') . (!empty($f['has_time']) ? ' at ' . $date->format('g:i a') : '') . '.';
    }
    if (($f['bidding'] ?? '') === 'phone') $lines[] = 'Bidding is by Intel Phone Bidding: buyers bid by phone with Intel.'; // only a phone-bid sale must say how bidding works (DJ, Oct 14, 2026)
    $ohs = [];
    foreach ((array) ($f['open_houses'] ?? []) as $oh) {
      if (is_array($oh) && !empty($oh['label'])) $ohs[] = (string) $oh['label'];
      elseif (is_string($oh) && $oh !== '') $ohs[] = $oh;
    }
    if ($ohs) $lines[] = (count($ohs) === 1 ? 'Open house: ' : 'Open houses: ') . implode('; ', $ohs) . '.';
    if (!empty($o['link'])) $lines[] = 'Photos, terms and the full listing: ' . $o['link'];
    $out = implode("\n", $lines);

    $about = trim((string) ($f['excerpt'] ?? ''));
    if ($about !== '' && function_exists('ias_fair_housing_check') && ias_fair_housing_check($about, (string) ($o['extra'] ?? ''))) $about = ''; // a flagged phrase: left out, never sent for a person to approve
    if ($about !== '') $out .= "\n\n" . (function_exists('ias_fit_text') ? ias_fit_text($about, 1500) : mb_substr($about, 0, 1500));

    $lead = (array) ($f['lead'] ?? []);
    $phone = (string) ($f['phone'] ?? '');
    $ask = !empty($lead['name']) && !empty($lead['phone']) ? 'Questions? Call ' . $lead['name'] . ' at ' . $lead['phone'] . '.' : ($phone !== '' ? 'Questions? Call ' . $phone . '.' : '');
    $tail_lines = array_values(array_filter([$ask, !empty($o['subscribe']) ? 'Subscribe for every property we bring to auction: ' . $o['subscribe'] : 'Subscribe for every property we bring to auction.']));
    $out .= "\n\n" . implode("\n", $tail_lines);

    $b = (array) ($o['business'] ?? []);
    if (!empty($b['name'])) {
      $addr = trim((string) ($b['street'] ?? '') . ', ' . (string) ($b['city'] ?? '') . ', ' . trim((string) ($b['region'] ?? '') . ' ' . (string) ($b['postal'] ?? '')), ', ');
      $out .= "\n\n" . $b['name'] . ' | ' . $addr . ' | ' . (string) ($b['phone'] ?? '');
    }
    $tags = ['#IntelAuctions'];
    if ($town !== '') { $t = '#' . preg_replace('/[^A-Za-z0-9]/', '', ucwords(strtolower($town))); if ($t !== '#') $tags[] = $t; }
    $tags[] = ($f['kind'] ?? '') === 'liquidation' ? '#AuctionTime' : (($f['division'] ?? '') === 'investment_rec' ? '#LandAuction' : '#RealEstateAuction');
    $out .= "\n\n" . implode(' ', array_unique($tags));

    $out = str_replace(['<', '>'], '', $out);
    if (mb_strlen($out) > 5000) $out = mb_substr($out, 0, 4999) . '…';
    return ['title' => $title, 'text' => $out];
  }
}

/** The business's name, address and phone, as the toolkit keeps them (the canonical NAP). */
if (!function_exists('ias_youtube_business')) {
  function ias_youtube_business(): array {
    return [
      'name'   => (string) apply_filters('ias_business_name', 'Intel Auctions LLC'),
      'street' => function_exists('ia_business_street') ? ia_business_street() : '5940 Plank Road',
      'city'   => function_exists('ia_business_city') ? ia_business_city() : 'Narvon',
      'region' => function_exists('ia_business_region') ? ia_business_region() : 'PA',
      'postal' => function_exists('ia_business_postal') ? ia_business_postal() : '17555',
      'phone'  => function_exists('ia_business_phone') ? ia_business_phone() : '717-917-5844',
    ];
  }
}

/** The words for listing $lid now. */
if (!function_exists('ias_youtube_words_for')) {
  function ias_youtube_words_for(int $lid): array {
    $f = ias_listing_facts($lid);
    $now = ias_now();
    $f['open_houses'] = array_values(array_map(function ($oh) {
      return ['label' => ia_open_house_format($oh, 'range', 'l, F j,')];
    }, array_filter((array) $f['open_houses'], function ($oh) use ($now) { return ($oh['end'] ?? null) instanceof DateTimeInterface && $oh['end'] > $now; }))); // only the ones still ahead
    return ias_youtube_words($f, [
      'link' => ias_channel_link((string) $f['url'], 'youtube'),
      'subscribe' => ias_youtube_channel_url(true),
      'business' => ias_youtube_business(),
      'extra' => (string) ias_setting('fair_housing_extra', ''),
    ]);
  }
}

/**
 * A YouTube video id out of whatever OneUp or YouTube hands back: a watch,
 * youtu.be, shorts or embed address, or an id on its own (also as
 * "YT_<id>"). '' when there is none — a bare number is OneUp's own id, never
 * a video's. Pure.
 */
if (!function_exists('ias_youtube_video_id')) {
  function ias_youtube_video_id(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~i', $s, $m)) return $m[1];
    if (preg_match('~^(?:(?:yt|youtube)[_:-])?([A-Za-z0-9_-]{11})$~i', $s, $m) && !ctype_digit($m[1])) return $m[1];
    return '';
  }
}

/**
 * When a video sent now goes out (site time, "Y-m-d H:i"): five minutes
 * ahead, and never on a Sunday — a Sunday's waits for Monday's first posting
 * window. $slots as the settings hold them. Pure.
 */
if (!function_exists('ias_youtube_when')) {
  function ias_youtube_when(DateTimeImmutable $now, array $slots): string {
    $t = $now->modify('+5 minutes');
    if ($t->format('N') === '7') {
      $first = (string) (($slots[0][0] ?? '') ?: '06:00');
      [$h, $i] = array_map('intval', explode(':', $first) + [0, 0]);
      $t = $t->modify('+1 day')->setTime($h, $i);
    }
    return $t->format('Y-m-d H:i');
  }
}

/* ---------------- The record on the listing ---------------- */

if (!function_exists('ias_youtube_get')) {
  function ias_youtube_get(int $lid): array {
    $r = $lid > 0 ? get_post_meta($lid, IAS_YOUTUBE_META, true) : [];
    return is_array($r) ? $r : [];
  }
}

/** Save the record, with a line for its history (and the plugin's log when $log). */
if (!function_exists('ias_youtube_put')) {
  function ias_youtube_put(int $lid, array $rec, string $line = '', string $actor = 'system', bool $log = true): array {
    if ($line !== '') {
      $trail = (array) ($rec['trail'] ?? []);
      $trail[] = ['at' => ias_now_sql(), 'what' => $line, 'by' => $actor];
      $rec['trail'] = array_slice($trail, -20);
      if ($log) ias_log('video', $lid, ['summary' => 'YouTube: ' . $line], $actor);
    }
    if ($rec) update_post_meta($lid, IAS_YOUTUBE_META, $rec);
    else delete_post_meta($lid, IAS_YOUTUBE_META);
    unset($GLOBALS['ias_youtube_index']);
    return $rec;
  }
}

/** Every listing with a YouTube record: [listing id => record]. */
if (!function_exists('ias_youtube_all')) {
  function ias_youtube_all(): array {
    if (isset($GLOBALS['ias_youtube_index'])) return $GLOBALS['ias_youtube_index'];
    global $wpdb;
    $out = [];
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", IAS_YOUTUBE_META)) as $lid) {
      $rec = ias_youtube_get((int) $lid);
      if ($rec) $out[(int) $lid] = $rec;
    }
    return $GLOBALS['ias_youtube_index'] = $out;
  }
}

/** An upload address handed out more than two hours ago and never confirmed is nothing. */
if (!function_exists('ias_youtube_stale_upload')) {
  function ias_youtube_stale_upload(array $rec): bool {
    return ($rec['state'] ?? '') === 'uploading' && strtotime((string) ($rec['up_at'] ?? '')) < strtotime(ias_now_sql()) - 2 * HOUR_IN_SECONDS;
  }
}

/** The uploaded file's address, when one is on OneUp's storage ('' = none). Facebook's posts use it too. */
if (!function_exists('ias_youtube_file')) {
  function ias_youtube_file(int $lid): string {
    $rec = ias_youtube_get($lid);
    return in_array((string) ($rec['state'] ?? ''), ['ready', 'sent', 'live', 'failed', 'lost'], true) ? (string) ($rec['file'] ?? '') : '';
  }
}

/** Is OneUp's row this video (by its words, its time, and the channel)? */
if (!function_exists('ias_youtube_row_is')) {
  function ias_youtube_row_is(array $row, array $sent, bool $published): bool {
    if (!ias_youtube_row_ok($row)) return false;
    if (ias_norm_text((string) ($row['content'] ?? '')) !== ias_norm_text((string) ($sent['text'] ?? ''))) return false;
    if (!$published) return substr((string) ($row['date_time'] ?? ''), 0, 16) === substr((string) ($sent['when'] ?? ''), 0, 16);
    return ias_published_pick((string) ($row['content'] ?? ''), ias_oneup_time((string) ($row['created_at'] ?? $row['date_time'] ?? ''), true), ['x' => ['text' => (string) $sent['text'], 'when' => (string) $sent['when']]]) !== null;
  }
}

/**
 * Is a row of OneUp's lists one of these videos? Facebook's read-back asks,
 * so a channel named like the page is never taken for a person's post there.
 */
if (!function_exists('ias_youtube_row_ours')) {
  function ias_youtube_row_ours(array $row): bool {
    $text = ias_norm_text((string) ($row['content'] ?? ''));
    if ($text === '') return false;
    foreach (ias_youtube_all() as $rec) {
      foreach ([(array) ($rec['oneup'] ?? []), (array) ($rec['sending'] ?? [])] as $s) {
        if (!empty($s['text']) && ias_norm_text((string) $s['text']) === $text) return true;
      }
    }
    return false;
  }
}

/* ---------------- Uploading the file ---------------- */

/** OneUp's answer to uploadmedia: ['upload_url', 'file_path'] or ['error']. */
if (!function_exists('ias_youtube_upload_address')) {
  function ias_youtube_upload_address(): array {
    $r = ias_oneup_call('upload');
    if (!$r['ok']) return ['error' => $r['error']];
    $find = function ($data, string $key) use (&$find) {
      if (!is_array($data)) return '';
      if (isset($data[$key]) && is_string($data[$key])) return $data[$key];
      foreach ($data as $v) if (is_array($v) && ($x = $find($v, $key)) !== '') return $x;
      return '';
    };
    $up = (string) $find($r['data'], 'upload_url');
    $path = (string) $find($r['data'], 'file_path');
    if (!preg_match('#^https://#', $up) || !preg_match('#^https://#', $path)) return ['error' => 'OneUp did not hand over an upload address (its plan may not include uploads — the Basic plan does not).'];
    return ['upload_url' => $up, 'file_path' => $path];
  }
}

/**
 * A person starts an upload: the file's name, size and type are checked, an
 * address is asked of OneUp and the listing's record says "uploading".
 * Returns ['ok', 'error', 'upload_url', 'file_path', 'type'].
 */
if (!function_exists('ias_youtube_upload_start')) {
  function ias_youtube_upload_start(int $lid, string $name, int $size, string $type, string $actor): array {
    $no = function (string $why) { return ['ok' => false, 'error' => $why]; };
    $post = get_post($lid);
    if (!$post || $post->post_type !== ia_schema_post_type()) return $no('That listing could not be found.');
    $block = ias_youtube_upload_blockers();
    if ($block) return $no('Not yet: ' . implode('; ', $block) . '.');
    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    $types = ['mp4' => 'video/mp4', 'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime', 'webm' => 'video/webm'];
    if (!isset($types[$ext])) return $no('That is not a video file YouTube and Facebook both take — upload an .mp4 (or .mov, .m4v, .webm).');
    if ($size <= 0) return $no('The file is empty.');
    if ($size > IAS_YOUTUBE_MAX) return $no('The file is ' . size_format($size) . ' — Facebook takes at most ' . size_format(IAS_YOUTUBE_MAX) . '. Export it smaller and upload that.');
    $rec = ias_youtube_get($lid);
    $state = (string) ($rec['state'] ?? '');
    if (in_array($state, ['sent', 'live'], true)) return $no('This listing\'s video is already ' . ($state === 'live' ? 'on YouTube' : 'on its way to YouTube') . '.');
    if ($state === 'lost') return $no('This listing\'s video may already be on YouTube (OneUp lost its answer): check the channel, then paste its link or press Send it again.');
    if (!empty($rec['sending'])) return $no('This listing\'s video was just sent to YouTube and its answer is not in yet — wait a few minutes.');
    $addr = ias_youtube_upload_address();
    if (isset($addr['error'])) return $no((string) $addr['error']);
    $mime = (string) ($type !== '' && strpos($type, 'video/') === 0 ? $type : $types[$ext]);
    $was = $state === 'ready' ? ' (in place of ' . (string) ($rec['file_name'] ?? 'the earlier file') . ')' : '';
    $keep = array_intersect_key($rec, ['trail' => 1]);
    ias_youtube_put($lid, $keep + ['state' => 'uploading', 'file' => $addr['file_path'], 'file_name' => sanitize_file_name($name), 'file_size' => $size, 'file_type' => $mime, 'up_by' => $actor, 'up_at' => ias_now_sql()],
      'Upload started: ' . sanitize_file_name($name) . ' (' . size_format($size) . ')' . $was, $actor, false);
    return ['ok' => true, 'error' => '', 'upload_url' => $addr['upload_url'], 'file_path' => $addr['file_path'], 'type' => $mime];
  }
}

/** Is the file at OneUp's address there? true, false (it answered: not there), or null (no answer). */
if (!function_exists('ias_youtube_file_there')) {
  function ias_youtube_file_there(string $url): ?bool {
    $r = wp_remote_head($url, ['timeout' => 15, 'redirection' => 2]);
    if (is_wp_error($r)) return null;
    $code = (int) wp_remote_retrieve_response_code($r);
    if ($code >= 200 && $code < 300) return true;
    return in_array($code, [403, 404], true) ? false : null;
  }
}

/**
 * The browser says the file is up: it is checked on OneUp's storage, the
 * record says "ready", the Next Step card asking for a video closes, and it
 * goes to YouTube at once when it can. Returns ['ok', 'error', 'message'].
 */
if (!function_exists('ias_youtube_upload_done')) {
  function ias_youtube_upload_done(int $lid, string $path, string $actor): array {
    $rec = ias_youtube_get($lid);
    if (($rec['state'] ?? '') !== 'uploading' || (string) ($rec['file'] ?? '') !== $path) return ['ok' => false, 'error' => 'That upload is not the one this listing is waiting for — upload the file again.', 'message' => ''];
    if (ias_youtube_file_there($path) === false) {
      ias_youtube_put($lid, array_intersect_key($rec, ['trail' => 1]), 'The upload did not arrive on OneUp\'s storage', $actor);
      return ['ok' => false, 'error' => 'The file did not arrive on OneUp\'s storage — upload it again.', 'message' => ''];
    }
    $rec['state'] = 'ready';
    $rec['up_at'] = ias_now_sql();
    unset($rec['error'], $rec['err_at'], $rec['fails']);
    ias_youtube_put($lid, $rec, 'Uploaded: ' . (string) ($rec['file_name'] ?? 'the video') . ' (' . size_format((int) ($rec['file_size'] ?? 0)) . ')', $actor);
    if (function_exists('ia_video_close_task')) ia_video_close_task($lid, 'done', 'Video uploaded — it goes to YouTube and the link onto the listing by itself');
    $sent = ias_youtube_send($lid, $actor);
    if ($sent['ok']) return ['ok' => true, 'error' => '', 'message' => 'Uploaded and sent to YouTube. Its link goes on the listing by itself once YouTube has it — usually within a few minutes.'];
    $now = ias_youtube_get($lid);
    if (!empty($now['sending'])) return ['ok' => true, 'error' => '', 'message' => 'Uploaded and sent, but OneUp did not answer — it is looked for on OneUp before anything is sent again.'];
    return ['ok' => true, 'error' => '', 'message' => 'Uploaded. ' . ias_youtube_waiting_words($lid)];
  }
}

/* ---------------- Sending it to YouTube ---------------- */

/** Why a ready video is not going to YouTube right now ('' = it can go). */
if (!function_exists('ias_youtube_send_blocker')) {
  function ias_youtube_send_blocker(int $lid, array $rec): string {
    if (($rec['state'] ?? '') !== 'ready' || empty($rec['file'])) return 'there is no uploaded file waiting';
    if (!empty($rec['sending'])) return 'its last try is still being looked for on OneUp';
    $block = ias_youtube_blockers();
    if ($block) return $block[0];
    $post = get_post($lid);
    if (!$post || $post->post_status !== 'publish' || $post->post_password !== '') return 'the listing is not published yet (the description links to it)';
    if (ias_now()->format('N') === '7') return 'never on a Sunday';
    return '';
  }
}

/** What the row says about a ready video that has not gone yet. */
if (!function_exists('ias_youtube_waiting_words')) {
  function ias_youtube_waiting_words(int $lid): string {
    $rec = ias_youtube_get($lid);
    $why = ias_youtube_send_blocker($lid, $rec);
    if ($why === '' && !empty($rec['error'])) return 'OneUp did not take it last time (' . $rec['error'] . ') — it is tried again within the hour, three times at most.';
    if ($why === '') return 'It goes to YouTube in a moment.';
    $words = [
      'YouTube is off'    => 'YouTube is switched off in Social Settings: it goes when it is switched on.',
      'posting is paused' => 'Posting is paused: it goes to YouTube when it is resumed.',
      'never on a Sunday' => 'It goes to YouTube on Monday — never on a Sunday.',
      'the listing is not published yet (the description links to it)' => 'It goes to YouTube once the listing is published (the description links to it).',
    ];
    return $words[$why] ?? 'Not sent to YouTube yet: ' . $why . '.';
  }
}

/**
 * Send listing $lid's uploaded video to YouTube through OneUp. The attempt is
 * recorded first; a lost answer leaves it to be looked for, never sent
 * twice. Runs inside the push lock, like every write to OneUp. Returns
 * ['ok', 'error'].
 */
if (!function_exists('ias_youtube_send')) {
  function ias_youtube_send(int $lid, string $actor = 'system'): array {
    $rec = ias_youtube_get($lid);
    $why = ias_youtube_send_blocker($lid, $rec);
    if ($why !== '') return ['ok' => false, 'error' => $why];
    $now = ias_now();
    if (!ias_push_lock()) return ['ok' => false, 'error' => 'another run is sending to OneUp — it goes with the next one'];
    try {
      $rec = ias_youtube_get($lid); // afresh, inside the lock
      if (ias_youtube_send_blocker($lid, $rec) !== '') return ['ok' => false, 'error' => 'it changed meanwhile'];
      $y = ias_youtube_settings();
      $words = ias_youtube_words_for($lid);
      $when = ias_youtube_when($now, (array) ias_setting('slots', []));
      $params = [
        'category_id'         => (string) ias_setting('oneup_category_id', ''),
        'social_network_id'   => $y['account'],
        'scheduled_date_time' => $when,
        'title'               => $words['title'],
        'content'             => $words['text'],
        'video_url'           => (string) $rec['file'],
      ];
      if ($y['playlist'] !== '') $params['youtube'] = (string) wp_json_encode(['playlist_name' => $y['playlist_name'], 'playlist_id' => $y['playlist']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      $rec['sending'] = ['at' => ias_now_sql(), 'when' => $when, 'title' => $words['title'], 'text' => $words['text']];
      ias_youtube_put($lid, $rec);
      $r = ias_oneup_call('schedule_video', $params);
      ias_oneup_scheduled_forget();
      $rec = ias_youtube_get($lid);
      if ($r['ok']) {
        $rec['oneup'] = ['id' => (string) $r['id'], 'when' => $when, 'title' => $words['title'], 'text' => $words['text'], 'at' => ias_now_sql()];
        if ($rec['oneup']['id'] === '') { // OneUp's answer carries no id (its docs): found on the scheduled list by its words and time, so it can be taken back
          foreach ((array) ias_oneup_scheduled(true)['rows'] as $row) if (ias_youtube_row_is($row, $rec['oneup'], false)) $rec['oneup']['id'] = (string) ($row['post_id'] ?? '');
        }
        $rec['state'] = 'sent';
        unset($rec['sending'], $rec['error'], $rec['err_at'], $rec['fails'], $rec['was_on_oneup']);
        ias_youtube_put($lid, $rec, 'Sent to YouTube for ' . date_i18n('D M j, g:i a', strtotime($when)) . ': "' . $words['title'] . '"', $actor);
        return ['ok' => true, 'error' => ''];
      }
      if (ias_oneup_uncertain($r)) { // OneUp may have it: the try stays, and the read-back looks for it before anything else
        ias_youtube_put($lid, $rec, 'Sent, but OneUp\'s answer was lost (' . $r['error'] . ') — it is looked for before anything is sent again', $actor);
        return ['ok' => false, 'error' => 'OneUp did not answer — the next check looks for it before anything is sent again'];
      }
      unset($rec['sending']);
      $rec['fails'] = (int) ($rec['fails'] ?? 0) + 1;
      $rec['error'] = $r['error'];
      $rec['err_at'] = ias_now_sql();
      if ($rec['fails'] >= 3) $rec['state'] = 'failed';
      ias_youtube_put($lid, $rec, 'OneUp refused it' . ($rec['fails'] >= 3 ? ' three times — Try again on the row once it is fixed' : '') . ': ' . $r['error'], $actor);
      return ['ok' => false, 'error' => $r['error']];
    } finally {
      ias_push_unlock();
    }
  }
}

/** Take a copy that has not gone out yet back off OneUp (off means off). true when it is off OneUp. */
if (!function_exists('ias_youtube_takeback')) {
  function ias_youtube_takeback(int $lid, array $rec, string $why): bool {
    $id = (string) ($rec['oneup']['id'] ?? '');
    if ($id === '' || ias_oneup_takeback_blockers()) return false;
    $d = ias_oneup_call('delete', ['post_id' => $id]);
    ias_oneup_scheduled_forget();
    $gone = $d['ok'] || (ias_oneup_gone($d) && ias_oneup_confirm_absent($id));
    if (!$gone) return false;
    unset($rec['oneup']);
    $rec['state'] = 'ready';
    $rec['was_on_oneup'] = true;
    ias_youtube_put($lid, $rec, 'Taken back off OneUp before it went out (' . $why . ') — it goes again when YouTube is on');
    return true;
  }
}

/* ---------------- What YouTube made of it ---------------- */

/**
 * The video's id on YouTube, from what OneUp and YouTube say ('' = not
 * found yet): the published row's own id or address, then OneUp's YouTube
 * analytics, then the channel's public feed — the last two matched by title.
 */
if (!function_exists('ias_youtube_find_id')) {
  function ias_youtube_find_id(array $row, string $title): string {
    foreach (['post_id', 'source_url', 'permalink', 'url', 'link', 'video_url'] as $k) {
      $v = ias_youtube_video_id((string) ($row[$k] ?? ''));
      if ($v !== '') return $v;
    }
    $title = trim($title);
    if ($title === '') return '';
    // OneUp's YouTube analytics (its Intermediate plan and above): each video's id and title.
    $r = ias_oneup_call('yt_stats', ['social_network_id' => ias_youtube_settings()['account'], 'preset' => 'last_30_days']);
    if ($r['ok']) {
      foreach ((array) ($r['data']['data']['posts'] ?? $r['data']['posts'] ?? []) as $p) {
        if (is_array($p) && trim(html_entity_decode((string) ($p['title'] ?? ''), ENT_QUOTES, 'UTF-8')) === $title) {
          $v = ias_youtube_video_id((string) ($p['permalink'] ?? '')) ?: ias_youtube_video_id((string) ($p['id'] ?? ''));
          if ($v !== '') return $v;
        }
      }
    }
    // The channel's public feed (no key: its fifteen newest public videos).
    $channel = ias_youtube_settings()['account'];
    if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $channel)) {
      $feed = wp_remote_get('https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channel), ['timeout' => 15]);
      if (!is_wp_error($feed) && (int) wp_remote_retrieve_response_code($feed) === 200) {
        $xml = (string) wp_remote_retrieve_body($feed);
        if (preg_match_all('~<entry>(.*?)</entry>~s', $xml, $m)) {
          foreach ($m[1] as $entry) {
            if (!preg_match('~<yt:videoId>([A-Za-z0-9_-]{11})</yt:videoId>~', $entry, $vid) || !preg_match('~<title>(.*?)</title>~s', $entry, $t)) continue;
            if (trim(html_entity_decode($t[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) === $title) return $vid[1];
          }
        }
      }
    }
    return '';
  }
}

/** Put the YouTube link on the listing — never over a link a person put there. */
if (!function_exists('ias_youtube_link')) {
  function ias_youtube_link(int $lid, array $rec): array {
    $url = 'https://www.youtube.com/watch?v=' . (string) $rec['video_id'];
    $rec['url'] = $url;
    if (!function_exists('ia_video_save')) {
      return ias_youtube_put($lid, $rec, 'On YouTube: ' . $url . ' — the toolkit is too old to put it on the listing; paste it there');
    }
    $saved = ia_video_save($lid, $url, 'Intel Auctions Social', false);
    $rec['linked'] = $saved['ok'] ? 'listing' : 'kept';
    $rec['linked_at'] = ias_now_sql();
    return ias_youtube_put($lid, $rec, 'On YouTube: ' . $url . ($saved['ok'] ? ' — the link is on the listing' : ' — the listing already had a link, which stays'));
  }
}

/**
 * Read back every video that is out: what OneUp did with it, and its link
 * once YouTube has it. From the hourly run, an open calendar's check, and
 * the Property Videos page (at most every two minutes there). Also sends any
 * video that is ready and free to go. Returns the changes, in words.
 */
if (!function_exists('ias_youtube_check')) {
  function ias_youtube_check(bool $send = true): array {
    $changes = [];
    $all = ias_youtube_all();
    if (!$all) return $changes;

    // Uploads never confirmed are nothing.
    foreach ($all as $lid => $rec) if (ias_youtube_stale_upload($rec)) { ias_youtube_put($lid, array_intersect_key($rec, ['trail' => 1]), 'The upload was never finished — upload the file again', 'system', false); unset($all[$lid]); }

    // Off means off: a copy that has not gone out yet comes back off — by its id, with only the key needed (no read first).
    $off = ias_youtube_switched_off();
    if ($off && !ias_oneup_takeback_blockers()) {
      foreach ($all as $lid => $rec) {
        if (($rec['state'] ?? '') !== 'sent' || empty($rec['oneup']['id']) || strtotime((string) ($rec['oneup']['when'] ?? '')) <= strtotime(ias_now_sql())) continue;
        if (!ias_push_lock()) break;
        try {
          if (ias_youtube_takeback($lid, $rec, $off[0])) $changes[] = 'a YouTube video taken back (' . $off[0] . ')';
        } finally {
          ias_push_unlock();
        }
      }
      $all = ias_youtube_all();
    }

    $out = array_filter($all, function ($r) { return !empty($r['sending']) || ($r['state'] ?? '') === 'sent' || (($r['state'] ?? '') === 'live' && empty($r['url'])); });
    $locked = $out && !ias_youtube_blockers_read() && ias_push_lock(); // one run at a time, as for every write to OneUp (a take-back is one)
    if ($locked) try {
      $sched = ias_oneup_scheduled(true);
      $pub = ias_oneup_list('published', 5);
      $fail = ias_oneup_list('failed', 3);
      foreach ($out as $lid => $rec) {
        // A try whose answer was lost: looked for first, never sent again by the plugin.
        if (!empty($rec['sending'])) {
          $s = (array) $rec['sending'];
          $found = null; $live = null;
          foreach ($sched['rows'] as $row) if (ias_youtube_row_is($row, $s, false)) $found = $row;
          foreach ($pub['rows'] as $row) if (!$found && ias_youtube_row_is($row, $s, true)) $live = $row;
          if ($found || $live) {
            $rec['oneup'] = ['id' => $found ? (string) ($found['post_id'] ?? '') : '', 'when' => (string) $s['when'], 'title' => (string) $s['title'], 'text' => (string) $s['text'], 'at' => (string) $s['at']];
            $rec['state'] = 'sent';
            unset($rec['sending'], $rec['error']);
            $rec = ias_youtube_put($lid, $rec, 'Found on OneUp after all (its answer was lost) — kept, not sent twice');
            $changes[] = 'a YouTube video found on OneUp';
          } elseif ($sched['ok'] && $sched['complete'] && $pub['ok'] && strtotime((string) $s['at']) < strtotime(ias_now_sql()) - 30 * MINUTE_IN_SECONDS) {
            unset($rec['sending']);
            $rec['state'] = 'lost';
            ias_youtube_put($lid, $rec, 'Not found on OneUp half an hour after it was sent — check the channel, then Send it again on the row');
            $changes[] = 'a YouTube video to check';
            continue;
          } else {
            continue;
          }
        }
        $sent = (array) ($rec['oneup'] ?? []);
        if (($rec['state'] ?? '') === 'sent') {
          $id = (string) ($sent['id'] ?? '');
          $on_sched = null;
          foreach ($sched['rows'] as $row) {
            if (($id !== '' && (string) ($row['post_id'] ?? '') === $id) || ($id === '' && ias_youtube_row_is($row, $sent, false))) $on_sched = $row;
          }
          if ($on_sched) {
            if ($id === '') { $rec['oneup']['id'] = (string) ($on_sched['post_id'] ?? ''); $rec = ias_youtube_put($lid, $rec); }
            if ($off && $rec['oneup']['id'] !== '' && ias_youtube_takeback($lid, $rec, $off[0])) $changes[] = 'a YouTube video taken back (' . $off[0] . ')'; // its id only now known
            continue; // not out yet
          }
          $live = null;
          foreach ($pub['rows'] as $row) if (ias_youtube_row_is($row, $sent, true)) $live = $row;
          if ($live) {
            $rec['state'] = 'live';
            $rec['live_at'] = ias_oneup_time((string) ($live['created_at'] ?? ''), true) ?: ias_now_sql();
            $rec['row'] = array_intersect_key($live, array_flip(['post_id', 'source_url', 'permalink', 'url', 'link']));
            $rec = ias_youtube_put($lid, $rec, 'Published on YouTube');
            $changes[] = 'a video published on YouTube';
          } else {
            $failed = null;
            foreach ($fail['rows'] as $row) if (($id !== '' && (string) ($row['post_id'] ?? '') === $id) || ias_youtube_row_is($row, $sent, false)) $failed = $row;
            if ($failed) {
              $why = ias_oneup_fail_reason($failed);
              $rec['state'] = 'failed';
              $rec['error'] = $why !== '' ? $why : 'OneUp could not publish it';
              ias_youtube_put($lid, $rec, 'YouTube could not take it: ' . $rec['error'] . ' — Try again on the row once it is fixed');
              $changes[] = 'a YouTube video failed';
            }
            continue;
          }
        }
        if (($rec['state'] ?? '') === 'live' && empty($rec['url'])) {
          $vid = ias_youtube_find_id((array) ($rec['row'] ?? []), (string) ($sent['title'] ?? ''));
          if ($vid !== '') {
            $rec['video_id'] = $vid;
            ias_youtube_link($lid, $rec);
            $changes[] = 'a YouTube link on its listing';
          } elseif (empty($rec['no_link_said']) && strtotime((string) ($rec['live_at'] ?? '')) < strtotime(ias_now_sql()) - 2 * DAY_IN_SECONDS) {
            $rec['no_link_said'] = true;
            ias_youtube_put($lid, $rec, 'Its YouTube link could not be found two days on — paste it on the listing\'s row in Property Videos');
          }
        }
      }
    } finally {
      ias_push_unlock();
    }

    // Ready and free to go.
    if ($send) {
      foreach (ias_youtube_all() as $lid => $rec) {
        if (($rec['state'] ?? '') !== 'ready' || ias_youtube_send_blocker($lid, $rec) !== '') continue;
        if (!empty($rec['err_at']) && strtotime((string) $rec['err_at']) > strtotime(ias_now_sql()) - 15 * MINUTE_IN_SECONDS) continue; // refused a moment ago: not again every minute
        if (ias_youtube_send($lid)['ok']) $changes[] = 'a video sent to YouTube';
      }
    }
    return $changes;
  }
}

/** A person's Try again (failed) or Send it again (lost, after checking the channel): back to ready, and sent when it can. */
if (!function_exists('ias_youtube_retry')) {
  function ias_youtube_retry(int $lid, string $actor): array {
    $rec = ias_youtube_get($lid);
    $state = (string) ($rec['state'] ?? '');
    if (!in_array($state, ['failed', 'lost'], true) || empty($rec['file'])) return ['ok' => false, 'error' => 'There is nothing to send again.'];
    $rec['state'] = 'ready';
    unset($rec['oneup'], $rec['error'], $rec['err_at'], $rec['fails'], $rec['sending']);
    ias_youtube_put($lid, $rec, $state === 'lost' ? 'Send it again, chosen after checking the channel' : 'Try again', $actor);
    $r = ias_youtube_send($lid, $actor);
    return ['ok' => true, 'error' => '', 'message' => $r['ok'] ? 'Sent to YouTube again.' : ias_youtube_waiting_words($lid)];
  }
}

/* ---------------- Verify for YouTube ---------------- */

/**
 * The channel's playlists (GET fetchytplaylist), kept for Settings to offer:
 * [['id', 'name']]. Read by Verify for YouTube; never typed.
 */
if (!function_exists('ias_youtube_playlists_fetch')) {
  function ias_youtube_playlists_fetch(): array {
    $r = ias_oneup_call('yt_playlists', ['social_account_id' => ias_youtube_settings()['account']]);
    if (!$r['ok']) return ['error' => $r['error']];
    $out = [];
    foreach (ias_oneup_rows($r['data']) as $row) {
      $id = trim((string) ($row['playlist_id'] ?? ''));
      if ($id !== '') $out[] = ['id' => mb_substr($id, 0, 64), 'name' => mb_substr(trim((string) ($row['playlist_name'] ?? '')), 0, 150)];
    }
    update_option('ias_youtube_playlists', ['at' => ias_now_sql(), 'account' => ias_youtube_settings()['account'], 'list' => $out], false);
    return ['list' => $out];
  }
}

/**
 * Verify for YouTube's upload check: a tiny text file PUT to the address
 * OneUp handed over (as the browser will PUT the video — no other header, so
 * the presigned address must take it as it is), then read back from its
 * public address, and OneUp's storage asked whether a page on this site may
 * upload there (CORS). The test file is 42 bytes and never posted anywhere.
 * Returns [ok, words].
 */
if (!function_exists('ias_youtube_upload_test')) {
  function ias_youtube_upload_test(array $addr): array {
    $put = wp_remote_request((string) $addr['upload_url'], ['method' => 'PUT', 'timeout' => 30, 'headers' => ['Content-Type' => 'text/plain'], 'body' => 'Intel Auctions Social upload test (Verify)']);
    if (is_wp_error($put)) return [false, 'The test file could not be sent: ' . $put->get_error_message()];
    $code = (int) wp_remote_retrieve_response_code($put);
    if ($code < 200 || $code >= 300) return [false, 'OneUp\'s storage refused the test file (HTTP ' . $code . ').'];
    $there = ias_youtube_file_there((string) $addr['file_path']);
    if ($there !== true) return [false, $there === false ? 'The test file was sent but cannot be read back from its public address.' : 'The test file was sent; its public address did not answer.'];
    $host = wp_parse_url((string) $addr['upload_url']);
    $origin = (string) (wp_parse_url(admin_url(), PHP_URL_SCHEME) . '://' . wp_parse_url(admin_url(), PHP_URL_HOST));
    $pre = wp_remote_request((string) $addr['upload_url'], ['method' => 'OPTIONS', 'timeout' => 15, 'headers' => ['Origin' => $origin, 'Access-Control-Request-Method' => 'PUT', 'Access-Control-Request-Headers' => 'content-type']]);
    $allow = is_wp_error($pre) ? '' : (string) wp_remote_retrieve_header($pre, 'access-control-allow-origin');
    if (!is_wp_error($pre) && !in_array($allow, ['*', $origin], true)) return [false, 'OneUp\'s storage (' . (string) ($host['host'] ?? '') . ') does not let a page on ' . $origin . ' upload to it, so the browser could not send a video there. Ask OneUp to allow it.'];
    return [true, 'A 42-byte test file went up and came back from its public address' . (is_wp_error($pre) ? '' : ', and a page on this site may upload there') . '. It is never posted.'];
  }
}

/* ---------------- Hooks ---------------- */

add_filter('ias_oneup_endpoints', function (array $eps): array {
  $eps['upload']       = ['GET', 'uploadmedia'];   // OneUp's docs: a presigned address to PUT the file to (20 minutes) and its public file_path; not on the Basic plan
  $eps['yt_playlists'] = ['GET', 'fetchytplaylist']; // social_account_id → [{playlist_name, playlist_id}]
  $eps['yt_stats']     = ['GET', 'https://analyze.oneupapp.io/api/youtube/posts']; // each video's id, title, permalink (Intermediate plan and above)
  return $eps;
});

/** The toolkit's Property Videos: the row says the video is on its way, and no Next Step card asks for one meanwhile. */
add_filter('ia_video_pending', function ($text, $lid) {
  $rec = ias_youtube_get((int) $lid);
  switch ((string) ($rec['state'] ?? '')) {
    case 'ready': return 'Uploaded. ' . ias_youtube_waiting_words((int) $lid);
    case 'sent':  return 'Uploaded and sent to YouTube — the link goes on the listing by itself once YouTube has it.';
    case 'live':  return empty($rec['url']) ? 'On YouTube — its link goes on the listing by itself.' : (string) $text;
  }
  return (string) $text;
}, 10, 2);

/** The hourly run: read back what is out, send what is ready. */
add_filter('ias_job_run', function (array $result): array {
  $changes = ias_youtube_check(true);
  if ($changes) $result['parts'][] = 'YouTube: ' . implode(', ', array_unique($changes));
  return $result;
}, 33);

/* ---------------- The upload on the toolkit's Property Videos row ---------------- */

/** The row's YouTube part: the Upload video file button, or where the video stands. */
add_action('ia_missing_video_row', function ($row) {
  if (!current_user_can('manage_options')) return;
  $lid = (int) ($row['ID'] ?? 0);
  if ($lid <= 0 || ias_youtube_settings()['account'] === '') return; // no channel chosen: the row stays as the toolkit has it
  $rec = ias_youtube_get($lid);
  $state = ias_youtube_stale_upload($rec) ? '' : (string) ($rec['state'] ?? '');
  echo '<div class="ias-yt" data-listing="' . $lid . '" style="margin-top:6px">';
  if (in_array($state, ['', 'uploading', 'ready'], true)) {
    $block = ias_youtube_upload_blockers();
    if ($block) {
      echo '<span class="description">Upload video file: not yet — ' . esc_html(implode('; ', $block)) . ' (Social Settings).</span>';
    } else {
      $ready = $state === 'ready';
      echo '<label class="button ias-yt-pick">' . ($ready ? 'Upload a different file' : 'Upload video file') . '<input type="file" accept="video/mp4,video/quicktime,video/x-m4v,video/webm,.mp4,.mov,.m4v,.webm" style="display:none"></label> '
        . '<span class="description">' . ($ready ? esc_html((string) ($rec['file_name'] ?? 'A file') . ' (' . size_format((int) ($rec['file_size'] ?? 0)) . ') is uploaded — another file would take its place.')
          : 'it goes to YouTube, titled and described, and its link onto the listing — by itself') . '</span>';
      echo '<div class="ias-yt-bar" hidden style="margin-top:6px;max-width:420px;height:8px;background:#f0f0f1;border-radius:4px;overflow:hidden"><div style="height:100%;width:0;background:#2271b1"></div></div>';
      echo '<div class="ias-yt-msg description" aria-live="polite"></div>';
    }
  } elseif ($state === 'failed' || $state === 'lost') {
    echo '<p style="margin:0 0 4px;color:#b32d2e"><strong>' . ($state === 'failed' ? 'YouTube could not take the video' : 'Sent to YouTube, but its answer was lost and OneUp has no sign of it') . '.</strong> '
      . esc_html($state === 'failed' ? (string) ($rec['error'] ?? '') : 'Check the channel first: if the video is there, paste its link above instead.') . '</p>';
    echo '<button type="button" class="button ias-yt-retry">' . ($state === 'failed' ? 'Try again' : 'Send it again') . '</button> <span class="ias-yt-msg description" aria-live="polite"></span>';
  }
  echo '</div>';
});

/** Upload, Try again: one AJAX address, each step checked. */
add_action('wp_ajax_ias_youtube', function () {
  if (!current_user_can('manage_options') || !check_ajax_referer('ias_youtube', '_wpnonce', false)) wp_send_json_error(['message' => 'Not allowed — reload the page.'], 403);
  $lid = (int) ($_POST['listing'] ?? 0);
  if (!current_user_can('edit_post', $lid)) wp_send_json_error(['message' => 'Not allowed on this listing.'], 403);
  $step = sanitize_key((string) ($_POST['step'] ?? ''));
  $actor = ias_actor();
  if ($step === 'start') {
    $r = ias_youtube_upload_start($lid, (string) wp_unslash($_POST['name'] ?? ''), (int) ($_POST['size'] ?? 0), sanitize_text_field((string) wp_unslash($_POST['type'] ?? '')), $actor);
    if (!$r['ok']) wp_send_json_error(['message' => $r['error']]);
    wp_send_json_success(['upload_url' => $r['upload_url'], 'file_path' => $r['file_path'], 'type' => $r['type']]);
  }
  if ($step === 'done') {
    $r = ias_youtube_upload_done($lid, trim((string) wp_unslash($_POST['file_path'] ?? '')), $actor); // only compared with the address OneUp gave
    if (!$r['ok']) wp_send_json_error(['message' => $r['error']]);
    wp_send_json_success(['message' => $r['message']]);
  }
  if ($step === 'fail') { // the browser could not send the file: the upload is forgotten, nothing else
    $rec = ias_youtube_get($lid);
    if (($rec['state'] ?? '') === 'uploading') ias_youtube_put($lid, array_intersect_key($rec, ['trail' => 1]), 'The upload did not finish (' . sanitize_text_field((string) wp_unslash($_POST['why'] ?? 'the browser stopped')) . ')', $actor, false);
    wp_send_json_success(['message' => '']);
  }
  if ($step === 'retry') {
    $r = ias_youtube_retry($lid, $actor);
    if (!$r['ok']) wp_send_json_error(['message' => $r['error']]);
    wp_send_json_success(['message' => $r['message']]);
  }
  wp_send_json_error(['message' => 'Unknown step.']);
});

/** On Property Videos: read back what is out (at most every two minutes), so a row is never stale. */
if (!function_exists('ias_youtube_page_check')) {
  function ias_youtube_page_check(): void {
    if (get_transient('ias_youtube_page_check') || !ias_youtube_all()) return;
    set_transient('ias_youtube_page_check', 1, 2 * MINUTE_IN_SECONDS);
    ias_youtube_check(false);
  }
}
add_action('admin_init', function () {
  if (is_admin() && ($_GET['page'] ?? '') === 'ia-missing-video' && $_SERVER['REQUEST_METHOD'] === 'GET' && current_user_can('manage_options')) ias_youtube_page_check();
}, 30);

/** The upload's script: ask for an address, send the file straight to OneUp's storage with a progress bar, then say it is up. */
add_action('admin_footer', function () {
  if (($_GET['page'] ?? '') !== 'ia-missing-video' || !current_user_can('manage_options')) return;
  ?>
<script>
jQuery(function ($) {
  var nonce = <?php echo wp_json_encode(wp_create_nonce('ias_youtube')); ?>;
  function say($box, text, bad) { $box.find('.ias-yt-msg').first().text(text || '').css('color', bad ? '#b32d2e' : ''); }
  function post(data) { return $.post(ajaxurl, $.extend({ action: 'ias_youtube', _wpnonce: nonce }, data)); }
  $(document).on('change', '.ias-yt-pick input[type=file]', function () {
    var file = this.files && this.files[0], $box = $(this).closest('.ias-yt'), listing = $box.data('listing'), input = this;
    if (!file) return;
    var $bar = $box.find('.ias-yt-bar'), $fill = $bar.children().first(), $pick = $box.find('.ias-yt-pick');
    $pick.addClass('disabled').css('pointer-events', 'none');
    say($box, 'Getting an upload address…');
    var leave = function (e) { e.preventDefault(); e.returnValue = ''; };
    function done(text, bad) { $pick.removeClass('disabled').css('pointer-events', ''); input.value = ''; window.removeEventListener('beforeunload', leave); say($box, text, bad); }
    post({ step: 'start', listing: listing, name: file.name, size: file.size, type: file.type })
      .done(function (r) {
        if (!r || !r.success) { done((r && r.data && r.data.message) || 'The site did not answer.', true); return; }
        var xhr = new XMLHttpRequest();
        window.addEventListener('beforeunload', leave);
        $bar.prop('hidden', false); $fill.css('width', '0');
        xhr.upload.onprogress = function (e) { if (e.lengthComputable) { var pc = Math.round(e.loaded / e.total * 100); $fill.css('width', pc + '%'); say($box, 'Uploading ' + file.name + ': ' + pc + '% — keep this page open.'); } };
        xhr.onload = function () {
          if (xhr.status >= 200 && xhr.status < 300) {
            say($box, 'Checking the upload…');
            post({ step: 'done', listing: listing, file_path: r.data.file_path })
              .done(function (d) { done(d && d.data ? d.data.message : 'The site did not answer.', !(d && d.success)); if (d && d.success) $box.closest('tr').css('opacity', .7); })
              .fail(function () { done('The file is up, but the site did not answer — reload the page.', true); });
          } else {
            post({ step: 'fail', listing: listing, why: 'storage answered ' + xhr.status });
            done('The upload was refused (' + xhr.status + ') — try again.', true);
          }
        };
        xhr.onerror = function () { post({ step: 'fail', listing: listing, why: 'the connection dropped' }); done('The connection dropped during the upload — try again.', true); };
        xhr.open('PUT', r.data.upload_url);
        xhr.setRequestHeader('Content-Type', r.data.type);
        xhr.send(file);
      })
      .fail(function () { done('The site did not answer — try again, or reload the page.', true); });
  });
  $(document).on('click', '.ias-yt-retry', function () {
    var $b = $(this), $box = $b.closest('.ias-yt');
    $b.prop('disabled', true);
    post({ step: 'retry', listing: $box.data('listing') })
      .done(function (r) { say($box, r && r.data ? r.data.message : 'The site did not answer.', !(r && r.success)); })
      .fail(function () { say($box, 'The site did not answer — reload the page.', true); })
      .always(function () { $b.prop('disabled', false); });
  });
});
</script>
  <?php
});

/* ---------------- Where it says so ---------------- */

/** Where listing $lid's video stands, in a line (for the listing panel). '' = no record. */
if (!function_exists('ias_youtube_line')) {
  function ias_youtube_line(int $lid): string {
    $rec = ias_youtube_get($lid);
    if (!$rec || ias_youtube_stale_upload($rec)) return '';
    switch ((string) ($rec['state'] ?? '')) {
      case 'uploading': return 'Uploading the video file…';
      case 'ready':     return 'Video uploaded. ' . ias_youtube_waiting_words($lid);
      case 'sent':      return 'Video sent to YouTube for ' . date_i18n('D M j, g:i a', strtotime((string) ($rec['oneup']['when'] ?? ias_now_sql()))) . '.';
      case 'live':      return !empty($rec['url']) ? 'On YouTube: ' . $rec['url'] . (($rec['linked'] ?? '') === 'kept' ? ' (the listing kept the link it had)' : '') : 'On YouTube — its link is being looked up.';
      case 'failed':    return 'YouTube could not take the video: ' . (string) ($rec['error'] ?? '') . ' (Try again on Property Videos).';
      case 'lost':      return 'Sent to YouTube but not found on OneUp — check the channel (Send it again on Property Videos).';
    }
    return '';
  }
}

add_action('ias_listing_panel', function ($post) {
  if (!($post instanceof WP_Post)) return;
  $line = ias_youtube_line((int) $post->ID);
  if ($line === '') return;
  echo '<p style="margin:8px 0 0"><strong>Property video:</strong> ' . make_clickable(esc_html($line)) . '</p>';
}, 5, 1);
