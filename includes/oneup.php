<?php
/**
 * Intel Auctions Social — OneUp: the Facebook renderer and the client.
 *
 * Each post is stored as plain content (text, pictures, video, the first
 * comment and its link); a renderer turns it into one request to OneUp's
 * public API for the Facebook page. Instagram or LinkedIn later are more
 * renderers, not a rebuild.
 *
 * OneUp's API (https://www.oneupapp.io/api/…, the key as `apiKey`; read
 * against docs.oneupapp.io on Oct 14, 2026): a post is scheduled with POST
 * scheduletextpost / scheduleimagepost / schedulevideopost (category_id,
 * social_network_id — a list, even of one account: see
 * ias_oneup_wire_params() — scheduled_date_time "YYYY-MM-DD HH:MM",
 * content, image_url — several joined with "~~" — or video_url, and
 * first_comment). The documented answer is {"message": "1 new Posts
 * Scheduled.", "error": false, "data": []} — no post id — so a new post is
 * found on the scheduled list by its text and time; "0 new Posts" is a
 * refusal. A scheduled post's time cannot be edited (editpost changes only
 * its words), so a moved or changed post is deleted (POST
 * deletescheduledpost, post_id, scheduled posts only) and scheduled again.
 * Lists (scheduled, published, drafts, failed) come up to 50 a page from
 * `start`; the live account has answered with `next_start` too, and without
 * it a full page means there may be more (ias_oneup_list()). Each row carries
 * post_id, content, date_time (scheduled: the OneUp account's time) or
 * created_at (published: UTC), category_name and social_network_username;
 * a failed row's reason is `fail_reason`. On the published list `post_id` is
 * documented as the network's own id ("FB_…"), not OneUp's — so a published
 * post is matched to ours by its id or else by its words, page and time
 * (ias_published_pick()).
 *
 * The endpoint names and methods live in one filterable map
 * (`ias_oneup_endpoints`), as documented; **Verify OneUp** on the Settings
 * screen checks them with a draft that never publishes before any switch is
 * turned on. A method OneUp refuses (405) or an address too long for it (414)
 * is retried once the other way.
 *
 * Nothing is sent unless every switch allows it (ias_push_blockers()); the
 * key is added to the request only when it is sent and is never logged.
 */

if (!defined('ABSPATH')) exit;

if (!defined('IAS_ONEUP_BASE')) define('IAS_ONEUP_BASE', 'https://www.oneupapp.io/api/');
if (!defined('IAS_ONEUP_PAGE')) define('IAS_ONEUP_PAGE', 50); // rows a list page holds at most (OneUp's docs)

/**
 * Endpoint name => [HTTP method, path or full address, 'json' when the body
 * goes as JSON]. A path is under IAS_ONEUP_BASE; OneUp's analytics
 * (analyze.oneupapp.io) and comments (feed.oneupapp.io) live on hosts of
 * their own and need its Intermediate plan or above.
 */
if (!function_exists('ias_oneup_endpoints')) {
  function ias_oneup_endpoints(): array {
    return (array) apply_filters('ias_oneup_endpoints', [
      'categories'     => ['GET', 'listcategory'],
      'accounts'       => ['GET', 'listcategoryaccount'],
      'schedule_text'  => ['POST', 'scheduletextpost'],
      'schedule_image' => ['POST', 'scheduleimagepost'],
      'schedule_video' => ['POST', 'schedulevideopost'],
      'scheduled'      => ['GET', 'getscheduledposts'],
      'published'      => ['GET', 'getpublishedposts'],
      'drafts'         => ['GET', 'getdraftposts'],
      'failed'         => ['GET', 'getfailedposts'],
      'delete'         => ['POST', 'deletescheduledpost'], // OneUp's docs: post_id, unpublished posts only (Verify OneUp checks it on its test draft)
      'reschedule_failed' => ['POST', 'reschedulefailedpost'], // OneUp's docs: post_id + scheduled_date_time, failed posts only (Try again, 0.19.0)
      'edit'           => ['POST', 'editpost'],    // not used: it changes only the words, so a changed post is deleted and scheduled again
      'fb_stats'       => ['GET', 'https://analyze.oneupapp.io/api/facebook/posts'], // each Facebook post's reactions, comments, shares, clicks, views (stats.php)
    ]);
  }
}

/**
 * The Facebook request for one post: ['action' => 'create', 'endpoint',
 * 'method', 'url', 'params', 'alt']. The key is never in it (added at send
 * time); `alt` keeps each picture's alt text with the payload for the record —
 * OneUp's scheduling calls take none, and Facebook writes its own.
 */
if (!function_exists('ias_render_facebook')) {
  function ias_render_facebook(array $post): array {
    $c = (array) $post['content'];
    $media = (array) ($c['media'] ?? []);
    $when = !empty($post['scheduled_at']) ? substr((string) $post['scheduled_at'], 0, 16) : '';
    $params = [
      'category_id'         => (string) ias_setting('oneup_category_id', ''),
      'social_network_id'   => (string) ias_setting('oneup_account_id', ''),
      'scheduled_date_time' => $when,
      'content'             => (string) ($c['text'] ?? ''),
    ];
    if (!empty($c['first_comment'])) $params['first_comment'] = (string) $c['first_comment'];
    $alt = [];
    if (($media['kind'] ?? '') === 'video' && !empty($media['video_url'])) {
      $endpoint = 'schedule_video';
      $params['video_url'] = (string) $media['video_url'];
    } elseif (!empty($media['images'])) {
      $endpoint = 'schedule_image';
      $imgs = array_slice((array) $media['images'], 0, 10); // Facebook takes up to 10 in one post
      $params['image_url'] = implode('~~', array_map(function ($i) { return (string) $i['url']; }, $imgs));
      $alt = array_map(function ($i) { return (string) $i['alt']; }, $imgs);
    } else {
      $endpoint = 'schedule_text';
    }
    $ep = ias_oneup_endpoints()[$endpoint];
    return ['action' => 'create', 'channel' => 'facebook', 'endpoint' => $endpoint, 'method' => $ep[0], 'url' => IAS_ONEUP_BASE . $ep[1], 'params' => $params, 'alt' => $alt];
  }
}

/**
 * The request's fingerprint without its time: what the post says and shows.
 * Try again reschedules OneUp's failed copy only when this is unchanged
 * (0.19.0) — the time is the one thing a reschedule changes. Pure.
 */
if (!function_exists('ias_request_body_hash')) {
  function ias_request_body_hash(array $req): string {
    unset($req['params']['scheduled_date_time']);
    return md5((string) wp_json_encode($req));
  }
}

/** The request that takes a post back off OneUp. */
if (!function_exists('ias_render_delete')) {
  function ias_render_delete(array $post): array {
    $ep = ias_oneup_endpoints()['delete'];
    return ['action' => 'delete', 'channel' => 'facebook', 'endpoint' => 'delete', 'method' => $ep[0], 'url' => IAS_ONEUP_BASE . $ep[1],
      'params' => ['post_id' => (string) ((ias_post_sent($post)['id'] ?: $post['oneup_post_id']) ?: '(the OneUp id it was given)')], 'alt' => []];
  }
}

/**
 * What stops the plugin calling OneUp ([] = it may). $write = a call that puts
 * something new on OneUp (a post, or a new copy of one), which also needs
 * Verify OneUp passed for the page set now; reading back needs the master
 * switch, the key and the ids. Taking a post of ours back off OneUp is
 * ias_oneup_takeback_blockers()'s: off means off, so the master switch never
 * stops it.
 */
if (!function_exists('ias_oneup_blockers')) {
  function ias_oneup_blockers(bool $write = true): array {
    $out = [];
    if (!ias_setting('master', false)) $out[] = 'the master switch is off';
    if (ias_oneup_key() === '') $out[] = 'no OneUp key (Settings → OneUp)';
    if ((string) ias_setting('oneup_category_id', '') === '' || (string) ias_setting('oneup_account_id', '') === '') $out[] = 'the OneUp category or Facebook account id is not set';
    if ($write && !$out && !ias_oneup_verified()) $out[] = 'Verify OneUp has not passed for this page';
    return $out;
  }
}

/**
 * What stops the plugin taking one of its own posts back off OneUp ([] =
 * nothing). Only the key: a post that should not go out — its type switched
 * off, Pause, the master switch off, cancelled, skipped — comes off whatever
 * the other switches say (DJ, Oct 14, 2026: "Either things are active or
 * inactive"). With nothing of ours on OneUp there is nothing to take back, so
 * with the master switch off the plugin makes no call at all.
 */
if (!function_exists('ias_oneup_takeback_blockers')) {
  function ias_oneup_takeback_blockers(): array {
    return ias_oneup_key() === '' ? ['no OneUp key (Settings → OneUp)'] : [];
  }
}

/**
 * Has Verify OneUp passed for the page set now (every step ok, OneUp's time
 * zone the site's)? Nothing is written to OneUp until it has — a wrong
 * endpoint or time zone is found with a draft, not with a real post.
 */
if (!function_exists('ias_oneup_verified')) {
  function ias_oneup_verified(): bool {
    $v = get_option('ias_oneup_verify', []);
    return is_array($v) && !empty($v['ok']) && ($v['tz_offset'] ?? null) === 0
      && (string) ($v['key'] ?? '') === ias_oneup_key_print()
      && (string) ($v['category'] ?? '') === (string) ias_setting('oneup_category_id', '')
      && (string) ($v['account'] ?? '') === (string) ias_setting('oneup_account_id', '');
  }
}

/**
 * What stops this post going to OneUp right now ([] = nothing): everything
 * that stops writing to OneUp, plus Pause and the post type's own switch.
 * When one of them is a switch that is off (ias_push_switched_off()), a copy
 * already on OneUp comes back off (ias_push_one()).
 */
if (!function_exists('ias_push_blockers')) {
  function ias_push_blockers(array $post): array {
    $out = ias_oneup_blockers(true);
    if (ias_setting('paused', false)) $out[] = 'posting is paused';
    if (!ias_type_on((string) $post['type'])) $out[] = ias_post_type((string) $post['type'])['short'] . ' posts are off';
    return $out;
  }
}

/**
 * The switches that are off for this post ([] = none): the master switch,
 * Pause, its post type. Any of them, and its copy on OneUp comes back off —
 * off means off. (A missing key or id, or Verify not passed, stops anything
 * new going but takes nothing back: those are not a person switching
 * something off.)
 */
if (!function_exists('ias_push_switched_off')) {
  function ias_push_switched_off(array $post): array {
    $out = [];
    if (!ias_setting('master', false)) $out[] = 'the master switch is off';
    if (ias_setting('paused', false)) $out[] = 'posting is paused';
    if (!ias_type_on((string) $post['type'])) $out[] = ias_post_type((string) $post['type'])['short'] . ' posts are off';
    return $out;
  }
}

/** 'live' when the plugin may put posts on OneUp (master on, key and ids set, Verify OneUp passed), else 'off'. */
if (!function_exists('ias_push_mode')) {
  function ias_push_mode(): string {
    return ias_oneup_blockers(true) ? 'off' : 'live';
  }
}

/**
 * What this post has on OneUp for Facebook: `flags.oneup` (id, hash, at,
 * text). Returns ['mode' => 'live'|'', 'hash' => …, 'id' => …].
 */
if (!function_exists('ias_post_sent')) {
  function ias_post_sent(array $p): array {
    $live = (array) ($p['flags']['oneup'] ?? []);
    if (!empty($live['hash']) || !empty($live['id'])) return ['mode' => 'live', 'hash' => (string) ($live['hash'] ?? ''), 'id' => (string) ($live['id'] ?? '')];
    return ['mode' => '', 'hash' => '', 'id' => ''];
  }
}

/**
 * The status a person sees: a planned or approved post on the OneUp calendar
 * shows as Pushed; one whose time came in the old dry run (before 0.12.0,
 * `flags.dry_run`) as Not sent — nothing went out.
 */
if (!function_exists('ias_post_shown_status')) {
  function ias_post_shown_status(array $p): string {
    if ($p['status'] === 'published' && !empty($p['flags']['dry_run'])) return 'notsent';
    if (in_array($p['status'], ['planned', 'approved'], true) && ias_post_sent($p)['mode'] === 'live') return 'pushed';
    return (string) $p['status'];
  }
}

/**
 * One call to OneUp. Returns ['ok' => bool, 'code' => int, 'data' => decoded
 * body|null, 'error' => '…', 'id' => the post id it answered with, or '']. The
 * key goes into the request only; nothing here logs.
 */
if (!function_exists('ias_oneup_call')) {
  function ias_oneup_call(string $endpoint, array $params = []): array {
    $eps = ias_oneup_endpoints();
    if (!isset($eps[$endpoint])) return ['ok' => false, 'code' => 0, 'data' => null, 'error' => 'Unknown OneUp call: ' . $endpoint, 'id' => ''];
    if (function_exists('ias_push_lock_touch')) ias_push_lock_touch(); // a run in progress is never taken for a crashed one
    $key = ias_oneup_key();
    if ($key === '') return ['ok' => false, 'code' => 0, 'data' => null, 'error' => 'No OneUp key: add it under Settings → OneUp (or INTEL_ONEUP_API_KEY in wp-config.php).', 'id' => ''];
    $method = (string) $eps[$endpoint][0];
    $path = (string) $eps[$endpoint][1];
    $as_json = ($eps[$endpoint][2] ?? '') === 'json';
    $send = function (string $method, array $params) use ($path, $key, $as_json) {
      $url = preg_match('#^https://#', $path) ? $path : IAS_ONEUP_BASE . $path;
      $args = ['timeout' => 30, 'method' => $method];
      if ($method === 'GET') {
        $url = add_query_arg(array_map('rawurlencode', array_merge(['apiKey' => $key], array_map('strval', $params))), $url);
      } elseif ($as_json) { // the comment calls take a JSON body, the key in the query (OneUp's docs)
        $url = add_query_arg(['apiKey' => rawurlencode($key)], $url);
        $args['headers'] = ['Content-Type' => 'application/json'];
        $args['body'] = (string) wp_json_encode(array_map('strval', $params));
      } else {
        // The docs name apiKey a query parameter, and their POST examples send it in the form: both.
        $url = add_query_arg(['apiKey' => rawurlencode($key)], $url);
        $args['body'] = ['apiKey' => $key] + array_map('strval', $params);
      }
      return wp_remote_request($url, $args);
    };
    // Nothing OneUp (or the network) answers may carry the key onward into a log line, a note or an email.
    $fail = function (int $code, $data, string $error) use ($key) {
      return ['ok' => false, 'code' => $code, 'data' => $data, 'error' => ias_oneup_redact($error, $key), 'id' => ''];
    };
    $once = function (array $params) use ($send, $fail, $method) {
      $resp = $send($method, $params);
      $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
      if (in_array($code, [405, 414], true)) { // the other way, once
        $resp = $send($method === 'GET' ? 'POST' : 'GET', $params);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
      }
      if (is_wp_error($resp)) return $fail(0, null, $resp->get_error_message());
      $body = (string) wp_remote_retrieve_body($resp);
      $data = json_decode($body, true);
      $short = mb_substr(trim(wp_strip_all_tags($body)), 0, 200);
      if ($code < 200 || $code >= 300) return $fail($code, $data, 'OneUp answered HTTP ' . $code . ($short !== '' ? ': ' . $short : ''));
      if ($data === null && $body !== '') return $fail($code, null, 'OneUp\'s answer was not JSON: ' . $short);
      $err = ias_oneup_error_in((array) $data);
      if ($err !== '') return $fail($code, $data, 'OneUp said: ' . $err);
      return ['ok' => true, 'code' => $code, 'data' => $data, 'error' => '', 'id' => ias_oneup_find_id($data)];
    };
    // Only the scheduling calls take the account as a list; the analytics ask for one account by its plain id.
    if (!isset($params['social_network_id']) || strpos((string) $eps[$endpoint][1], 'schedule') !== 0) return $once($params);

    // OneUp takes the account as a list, even of one ("Social Network ID must be an array"). It is written
    // the way its documentation shows — a JSON list — and, should OneUp refuse that with the same words,
    // once more as social_network_id[0]; the way that worked is kept (`ias_oneup_id_form`) and tried first.
    // A refusal like that is a clear no — nothing was created — so the second try can never make a second post.
    $forms = get_option('ias_oneup_id_form', '') === 'brackets' ? ['brackets', 'json'] : ['json', 'brackets'];
    $r = $once(ias_oneup_wire_params($params, $forms[0]));
    if (!$r['ok'] && !ias_oneup_uncertain($r) && preg_match('/must be an? (array|list)/i', (string) $r['error'])) {
      $r2 = $once(ias_oneup_wire_params($params, $forms[1]));
      if ($r2['ok']) update_option('ias_oneup_id_form', $forms[1], false);
      return $r2;
    }
    if ($r['ok'] && get_option('ias_oneup_id_form', '') !== $forms[0]) update_option('ias_oneup_id_form', $forms[0], false);
    return $r;
  }
}

/**
 * The parameters as they go over the wire: a single account id becomes a
 * one-item list — `["1336677789520495"]` ($form 'json') or
 * `social_network_id[0]=…` ('brackets'). "ALL" and a list already given stay
 * as they are. Everything else is untouched (and what is recorded and hashed
 * as sent is the request before this, so the form never makes a post look
 * changed). Pure.
 */
if (!function_exists('ias_oneup_wire_params')) {
  function ias_oneup_wire_params(array $params, string $form): array {
    if (!array_key_exists('social_network_id', $params) || is_array($params['social_network_id'])) return $params;
    $id = trim((string) $params['social_network_id']);
    if ($id === '' || strtoupper($id) === 'ALL' || substr($id, 0, 1) === '[') return $params;
    unset($params['social_network_id']);
    if ($form === 'brackets') $params['social_network_id[0]'] = $id;
    else $params['social_network_id'] = (string) wp_json_encode([$id], JSON_UNESCAPED_SLASHES);
    return $params;
  }
}

/** $text with the key taken out, however it was written (as is, or URL-encoded). */
if (!function_exists('ias_oneup_redact')) {
  function ias_oneup_redact(string $text, string $key = ''): string {
    $key = $key !== '' ? $key : ias_oneup_key();
    if ($key === '') return $text;
    return str_replace(array_unique([$key, rawurlencode($key), urlencode($key)]), '[key]', $text);
  }
}

/** Did a call fail in a way that leaves it unknown whether OneUp acted (no answer, or a server error)? */
if (!function_exists('ias_oneup_uncertain')) {
  function ias_oneup_uncertain(array $r): bool {
    return !$r['ok'] && ($r['code'] === 0 || $r['code'] >= 500);
  }
}

/** An error OneUp reports inside a 200 answer ('' = none). */
if (!function_exists('ias_oneup_error_in')) {
  function ias_oneup_error_in(array $data): string {
    $msg = (string) ($data['message'] ?? $data['error_message'] ?? $data['msg'] ?? '');
    if (($data['error'] ?? false) === true || in_array(strtolower((string) ($data['status'] ?? '')), ['error', 'fail', 'failed'], true) || ($data['success'] ?? true) === false) {
      return $msg !== '' ? $msg : (is_string($data['error'] ?? null) ? (string) $data['error'] : 'an error');
    }
    if (is_string($data['error'] ?? null) && $data['error'] !== '') return (string) $data['error'];
    // "0 new Posts Scheduled." with no error flag: nothing was created — a clear no, never a success.
    if (preg_match('/^\s*0\s+new\s+posts?\b/i', $msg)) return $msg;
    return '';
  }
}

/**
 * The post id in a OneUp answer ('' = none): a `post_id` (or `postId`) at any
 * depth, never a bare `id` — that could be anything (a category, a request),
 * and a wrong id would later delete someone else's post. With no id, the post
 * is found on OneUp's list by its text and time instead.
 */
if (!function_exists('ias_oneup_find_id')) {
  function ias_oneup_find_id($data): string {
    if (!is_array($data)) return '';
    foreach (['post_id', 'postId'] as $k) {
      if (isset($data[$k]) && (is_int($data[$k]) || (is_string($data[$k]) && preg_match('/^\d+$/', $data[$k])))) return (string) $data[$k];
    }
    foreach ($data as $v) {
      if (is_array($v) && ($id = ias_oneup_find_id($v)) !== '') return $id;
    }
    return '';
  }
}

/**
 * Every row of a OneUp list (scheduled, published, drafts, failed), up to
 * $pages pages. Returns ['ok', 'rows', 'complete' (the whole list was read),
 * 'error']. OneUp's docs page a list by `start`, up to 50 rows a page, and say
 * nothing of where it ends; the live account has also answered with
 * `next_start`. So: a `next_start` is followed (null = the end); without one, a
 * full page means there may be more and the next is asked for from where this
 * one ended, and a short page is the end. A page that brings nothing new (a
 * server that ignored `start`) ends the reading as not complete — a partial
 * list must never read as "OneUp has none of our posts".
 */
if (!function_exists('ias_oneup_list')) {
  function ias_oneup_list(string $endpoint, int $pages = 10): array {
    $rows = [];
    $seen = [];
    $start = 0;
    for ($i = 0; $i < $pages; $i++) {
      $r = ias_oneup_call($endpoint, ['start' => $start]);
      if (!$r['ok']) return ['ok' => false, 'rows' => $rows, 'complete' => false, 'error' => $r['error']];
      $data = $r['data'];
      // Only an answer that really carries a list counts — an empty body, a bare [] or a "too many
      // requests" message must never read as "OneUp has none of our posts".
      $list = is_array($data) ? ($data['posts'] ?? $data['data'] ?? null) : null;
      if (!is_array($list)) return ['ok' => false, 'rows' => $rows, 'complete' => false, 'error' => 'OneUp\'s answer had no list of posts' . (is_array($data) && !empty($data['message']) && is_string($data['message']) ? ': ' . ias_oneup_redact((string) $data['message']) : '') . '.'];
      $page = array_values(array_filter($list, 'is_array'));
      $new = 0;
      foreach ($page as $row) {
        $k = isset($row['post_id']) && (string) $row['post_id'] !== '' ? 'id:' . $row['post_id'] : 'row:' . md5((string) wp_json_encode($row));
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $rows[] = $row; // as OneUp gave it: a titled (YouTube) row is split where YouTube's are read, never Facebook's or Google's
        $new++;
      }
      if ($page && !$new) return ['ok' => true, 'rows' => $rows, 'complete' => false, 'error' => ''];
      $next = ias_oneup_next_start($data, $start, count($page));
      if ($next === null) return ['ok' => true, 'rows' => $rows, 'complete' => true, 'error' => ''];
      $start = $next;
    }
    return ['ok' => true, 'rows' => $rows, 'complete' => false, 'error' => ''];
  }
}

/**
 * Where the next page of a OneUp list starts, or null at the end. A
 * `next_start` in the answer decides; without one, a full page (50) means ask
 * again from where it ended. Pure.
 */
if (!function_exists('ias_oneup_next_start')) {
  function ias_oneup_next_start(array $data, int $start, int $count): ?int {
    if (array_key_exists('next_start', $data)) {
      $n = $data['next_start'];
      return ($n === null || $n === '' || $n === false || (int) $n <= $start) ? null : (int) $n;
    }
    return $count >= IAS_ONEUP_PAGE ? $start + $count : null;
  }
}

/** Text compared the way OneUp may hand it back (line endings, runs of spaces, trailing space). Pure. */
if (!function_exists('ias_norm_text')) {
  function ias_norm_text(string $s): string {
    return trim((string) preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r"], "\n", $s)));
  }
}

/**
 * Which of our posts a row of OneUp's published list is, when its post_id is
 * not one of ours — OneUp's docs give the network's own id there ("FB_…"),
 * not the id the post was scheduled under. The post whose words are the
 * row's, published from 15 minutes before its time to a day after it; the
 * nearest in time when two could be. $cands: [key => ['text', 'when'
 * (site time)]]; $at: the row's time, in site time. Returns the key, or null.
 * The caller checks the row is on our page, and gives each post one row.
 * Only a status is read this way — nothing is ever deleted or replaced by a
 * match of words. Pure.
 */
if (!function_exists('ias_published_pick')) {
  function ias_published_pick(string $text, string $at, array $cands): ?string {
    $norm = ias_norm_text($text);
    $t = $at !== '' ? strtotime($at) : false;
    if ($norm === '' || !$t) return null;
    $best = null;
    $gap = PHP_INT_MAX;
    foreach ($cands as $k => $c) {
      if (ias_norm_text((string) ($c['text'] ?? '')) !== $norm) continue;
      $w = strtotime((string) ($c['when'] ?? ''));
      if (!$w) continue;
      $d = $t - $w;
      if ($d < -15 * 60 || $d > 86400) continue;
      if (abs($d) < $gap) { $gap = abs($d); $best = (string) $k; }
    }
    return $best;
  }
}

/** Why OneUp could not publish a post, from a row of its failed list ('' = it gave none). */
if (!function_exists('ias_oneup_fail_reason')) {
  function ias_oneup_fail_reason(array $row): string {
    foreach (['fail_reason', 'failure_reason', 'reason', 'error', 'message'] as $k) {
      if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') return trim($row[$k]);
    }
    return '';
  }
}

/**
 * A read-only call to OneUp (lists and the Settings screen's connection
 * check): only the listing endpoints are allowed here. Returns the decoded
 * body or WP_Error.
 */
if (!function_exists('ias_oneup_get')) {
  function ias_oneup_get(string $endpoint, array $params = []) {
    if (!in_array($endpoint, ['categories', 'accounts', 'scheduled', 'published', 'drafts', 'failed'], true)) return new WP_Error('ias_oneup_endpoint', 'Not a read-only OneUp call: ' . $endpoint);
    $r = ias_oneup_call($endpoint, $params);
    return $r['ok'] ? $r['data'] : new WP_Error('ias_oneup', $r['error']);
  }
}

/** The rows of a OneUp listing, whatever key holds them ({"accounts": […]}, {"categories": […]}, {"data": […]}, or a bare list). */
if (!function_exists('ias_oneup_rows')) {
  function ias_oneup_rows($data): array {
    if (!is_array($data)) return [];
    foreach (['accounts', 'categories', 'posts', 'data', 'message'] as $k) {
      if (isset($data[$k]) && is_array($data[$k])) return array_values(array_filter($data[$k], 'is_array'));
    }
    return array_values(array_filter($data, 'is_array'));
  }
}

/**
 * A post with a title — a YouTube video — comes back on OneUp's lists as
 * one `content`: "title~~~description" (the live account, Oct 14, 2026).
 * Split so `content` is the words the plugin sent and `title` the title;
 * the joined text stays in `content_joined`. Only for a row that is a
 * titled post (YouTube's read-back, its Verify, the test-draft clean-up):
 * a Facebook or Google post may carry "~~~" in its own words, and is read
 * as it is. A row OneUp already gave a title of its own is left alone.
 */
if (!function_exists('ias_oneup_row_split')) {
  function ias_oneup_row_split(array $row): array {
    $c = $row['content'] ?? null;
    if (!is_string($c) || strpos($c, '~~~') === false || (string) ($row['title'] ?? '') !== '') return $row;
    [$title, $body] = explode('~~~', $c, 2);
    $row['content_joined'] = $c;
    $row['content'] = $body;
    if (!isset($row['title']) || (string) $row['title'] === '') $row['title'] = $title;
    return $row;
  }
}

/** Pull [id, name, row] triples out of a OneUp listing, whatever its exact shape. */
if (!function_exists('ias_oneup_pairs')) {
  function ias_oneup_pairs($data, array $id_keys, array $name_keys): array {
    $rows = ias_oneup_rows($data);
    $out = [];
    foreach ((array) $rows as $row) {
      if (!is_array($row)) continue;
      $id = ''; $name = '';
      foreach ($id_keys as $k) if (isset($row[$k]) && $row[$k] !== '') { $id = (string) $row[$k]; break; }
      foreach ($name_keys as $k) if (isset($row[$k]) && $row[$k] !== '') { $name = (string) $row[$k]; break; }
      if ($id !== '') $out[] = [$id, $name, $row];
    }
    return $out;
  }
}

/**
 * What OneUp holds, as Look up accounts last found it (Settings): the
 * categories and each one's accounts — ['at', 'by', 'categories' => [['id',
 * 'name', 'accounts' => [['id', 'name', 'type']]]]]. Ids and names only,
 * never the key. Settings offers these to choose from, so no id is typed.
 */
if (!function_exists('ias_oneup_directory')) {
  function ias_oneup_directory(): array {
    $d = get_option('ias_oneup_directory', []);
    return is_array($d) && isset($d['categories']) && is_array($d['categories']) ? $d : [];
  }
}

/**
 * Ask OneUp for its categories and their accounts (read-only) and keep the
 * answer as the directory. Returns the directory, or ['error' => why]. Needs
 * the master switch on and the key, like every call. A category whose accounts could not
 * be read keeps the accounts it had last time.
 */
if (!function_exists('ias_oneup_directory_fetch')) {
  function ias_oneup_directory_fetch(string $by = ''): array {
    if (!ias_setting('master', false)) return ['error' => 'Turn the master switch on and save first: while it is off, the plugin never asks OneUp anything. (Nothing posts until a post type is on and Verify OneUp has passed.)'];
    if (ias_oneup_key() === '') return ['error' => 'Add the OneUp key first (Settings → OneUp) and save.'];
    $cats = ias_oneup_get('categories');
    if (is_wp_error($cats)) return ['error' => 'OneUp did not answer: ' . $cats->get_error_message()];
    $old = [];
    foreach ((array) (ias_oneup_directory()['categories'] ?? []) as $c) $old[(string) $c['id']] = (array) $c['accounts'];
    $out = [];
    $problems = [];
    foreach (ias_oneup_pairs($cats, ['id', 'category_id'], ['category_name', 'name']) as [$id, $name]) {
      $accts = ias_oneup_get('accounts', ['category_id' => $id]);
      $list = [];
      if (is_wp_error($accts)) {
        $problems[] = 'the accounts of "' . ($name !== '' ? $name : $id) . '" could not be read (' . $accts->get_error_message() . ')';
        $list = $old[$id] ?? [];
      } else {
        foreach (ias_oneup_pairs($accts, ['social_network_id', 'id'], ['social_network_name', 'username', 'name']) as [$aid, $aname, $row]) {
          $list[] = ['id' => mb_substr($aid, 0, 200), 'name' => mb_substr($aname, 0, 200), 'type' => mb_substr((string) ($row['social_network_type'] ?? $row['type'] ?? ''), 0, 40)];
        }
      }
      $out[] = ['id' => mb_substr($id, 0, 64), 'name' => mb_substr($name, 0, 200), 'accounts' => $list];
    }
    $dir = ['at' => ias_now_sql(), 'by' => $by, 'categories' => $out, 'problems' => $problems];
    update_option('ias_oneup_directory', $dir, false);
    // The page's name in OneUp's lists comes from here too (the read-back matches a person's posts by it).
    $acct = ias_oneup_directory_account((string) ias_setting('oneup_account_id', ''), (string) ias_setting('oneup_category_id', ''));
    if ($acct && $acct['name'] !== '' && $acct['name'] !== (string) ias_setting('oneup_account_name', '')) ias_settings_update(['oneup_account_name' => $acct['name']]);
    return $dir;
  }
}

/** One account in the directory (['id', 'name', 'type', 'category']), or null; $category '' = any. */
if (!function_exists('ias_oneup_directory_account')) {
  function ias_oneup_directory_account(string $id, string $category = ''): ?array {
    if ($id === '') return null;
    foreach ((array) (ias_oneup_directory()['categories'] ?? []) as $c) {
      if ($category !== '' && (string) $c['id'] !== $category) continue;
      foreach ((array) $c['accounts'] as $a) if ((string) $a['id'] === $id) return $a + ['category' => (string) $c['id']];
    }
    return null;
  }
}

/** Is a OneUp account of this social_network_type one for network $ch? */
if (!function_exists('ias_oneup_type_is')) {
  function ias_oneup_type_is(string $ch, string $type): bool {
    $type = strtolower(trim($type));
    $names = [
      'facebook'  => ['facebook', 'facebook page', 'fb'],
      'instagram' => ['instagram', 'instagram business'],
      'x'         => ['x', 'twitter'],
      'linkedin'  => ['linkedin', 'linkedin page', 'linkedin company'],
      'gbp'       => ['gbp', 'google', 'google business profile', 'google my business', 'gmb'],
      'youtube'   => ['youtube', 'youtube channel', 'yt'],
    ][$ch] ?? [strtolower($ch)];
    return in_array($type, $names, true);
  }
}

/**
 * Is this plugin really posting Facebook? Only when Listing posts can
 * actually reach OneUp: the master switch on, the key and the ids set,
 * Verify OneUp passed, Listing posts on. Pause does not change it: a pause is a short
 * stop, and handing Facebook back to the webhook for it would post every
 * listing announced meanwhile twice once pushes resume. The toolkit asks through
 * `ia_social_facebook_managed`: while this says no, the toolkit's webhook
 * keeps posting Facebook as before; once it says yes, the webhook carries
 * Google Business Profile only, so nothing is posted twice. (The filter
 * `ias_live_pushing_built` can still say no, for a site that wants the
 * plugin to plan but never take Facebook over.)
 */
if (!function_exists('ias_facebook_live')) {
  function ias_facebook_live(): bool {
    if (!apply_filters('ias_live_pushing_built', true)) return false;
    return !ias_oneup_blockers(true) && ias_type_on('listing');
  }
}
add_filter('ia_social_facebook_managed', function ($managed) {
  return $managed || ias_facebook_live();
});
