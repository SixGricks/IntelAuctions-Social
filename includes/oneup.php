<?php
/**
 * Intel Auctions Social — OneUp: the Facebook renderer and the client.
 *
 * Each post is stored as plain content (text, pictures, video, the first
 * comment and its link); a renderer turns it into one request to OneUp's
 * public API for the Facebook page. Instagram or LinkedIn later are more
 * renderers, not a rebuild.
 *
 * OneUp's API (https://www.oneupapp.io/api/…, the key as `apiKey`): a post
 * is scheduled with scheduletextpost / scheduleimagepost / schedulevideopost
 * (category_id, social_network_id, scheduled_date_time "YYYY-MM-DD HH:MM",
 * content, image_url — several joined with "~~" — or video_url, and
 * first_comment). A scheduled post's time cannot be edited, so a moved post
 * is deleted and scheduled again. The endpoint names live in one filterable
 * map (`ias_oneup_endpoints`); the ones for editing and deleting are to be
 * confirmed against OneUp's documentation when pushing goes live (Phase 5).
 *
 * In this version nothing is ever sent: the plan's pushes are written to the
 * log as dry-run payloads, and the only call the client makes is the
 * read-only connection check on the Settings screen.
 */

if (!defined('ABSPATH')) exit;

if (!defined('IAS_ONEUP_BASE')) define('IAS_ONEUP_BASE', 'https://www.oneupapp.io/api/');

/** Endpoint name => [HTTP method, path]. */
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
      'delete'         => ['POST', 'deletepost'],   // verify in Phase 5
      'edit'           => ['POST', 'editpost'],     // verify in Phase 5
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

/** The request that takes a post back off OneUp. */
if (!function_exists('ias_render_delete')) {
  function ias_render_delete(array $post): array {
    $ep = ias_oneup_endpoints()['delete'];
    return ['action' => 'delete', 'channel' => 'facebook', 'endpoint' => 'delete', 'method' => $ep[0], 'url' => IAS_ONEUP_BASE . $ep[1],
      'params' => ['post_id' => (string) ($post['oneup_post_id'] ?: '(the OneUp id it was given)')], 'alt' => []];
  }
}

/** What stops a post from really being sent right now ([] = nothing). */
if (!function_exists('ias_push_blockers')) {
  function ias_push_blockers(array $post): array {
    $out = [];
    if (!ias_setting('master', false)) $out[] = 'the master switch is off';
    if (ias_setting('dry_run', true)) $out[] = 'dry run is on';
    if (ias_setting('paused', false)) $out[] = 'pushes are paused';
    if (!ias_type_on((string) $post['type'])) $out[] = ias_post_type((string) $post['type'])['label'] . ' posts are off';
    if (ias_oneup_key() === '') $out[] = 'no OneUp key in wp-config';
    if ((string) ias_setting('oneup_category_id', '') === '' || (string) ias_setting('oneup_account_id', '') === '') $out[] = 'the OneUp category or Facebook account id is not set';
    $out[] = 'live pushing arrives in Phase 5';
    return $out;
  }
}

/**
 * A read-only call to OneUp (the Settings screen's connection check). Never
 * used for anything that changes OneUp. Returns the decoded body or WP_Error.
 */
if (!function_exists('ias_oneup_get')) {
  function ias_oneup_get(string $endpoint, array $params = []) {
    $eps = ias_oneup_endpoints();
    if (!isset($eps[$endpoint]) || $eps[$endpoint][0] !== 'GET') return new WP_Error('ias_oneup_endpoint', 'Not a read-only OneUp call: ' . $endpoint);
    $key = ias_oneup_key();
    if ($key === '') return new WP_Error('ias_oneup_key', 'No OneUp key: add INTEL_ONEUP_API_KEY to wp-config.php.');
    $url = add_query_arg(array_merge(['apiKey' => $key], $params), IAS_ONEUP_BASE . $eps[$endpoint][1]);
    $resp = wp_remote_get($url, ['timeout' => 20]);
    if (is_wp_error($resp)) return $resp;
    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);
    $data = json_decode($body, true);
    if ($code < 200 || $code >= 300) return new WP_Error('ias_oneup_http', 'OneUp answered HTTP ' . $code . ': ' . mb_substr(wp_strip_all_tags($body), 0, 200));
    return $data === null ? new WP_Error('ias_oneup_body', 'OneUp\'s answer was not JSON: ' . mb_substr(wp_strip_all_tags($body), 0, 200)) : $data;
  }
}

/** Pull [id, name] pairs out of a OneUp listing, whatever its exact shape. */
if (!function_exists('ias_oneup_pairs')) {
  function ias_oneup_pairs($data, array $id_keys, array $name_keys): array {
    $rows = is_array($data) ? ($data['data'] ?? $data['message'] ?? $data) : [];
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
