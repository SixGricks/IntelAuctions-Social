<?php
/**
 * Intel Auctions Social — how each post did on Facebook (0.17.0).
 *
 * The spec: "Every post records when it went out and how it did." Once a day
 * the plugin asks OneUp's analytics (GET analyze.oneupapp.io/api/facebook/
 * posts, the page's id, the last 31 days to yesterday — OneUp's Intermediate
 * plan or above) for the page's posts and their numbers: reactions,
 * comments, shares, clicks and views. Each is matched to one of ours — by
 * Facebook's id for it once known, else by its words at its time
 * (ias_published_pick(), as the read-back does) — and kept on the post
 * (`flags.stats`) with Facebook's id for it (`flags.oneup.fb_id`, which the
 * correction comments need) and its address on Facebook.
 *
 * Shown on the post's page, in its hover preview on the calendar, and in the
 * monthly summary by post type and division (so in the Monday email's too).
 * A read, so it needs the master switch on, the key and the page; never on
 * when Settings says not to (`stats_on`). A plan without analytics (403) is
 * said once and not asked again for a week. Nothing here ever writes to
 * OneUp or Facebook.
 */

if (!defined('ABSPATH')) exit;

/**
 * Facebook's id for a post as the comment calls want it ("<page>_<post>"),
 * from whatever OneUp gave: the analytics' id (with its page_id), or the
 * published list's network id ("FB_…"). '' when it cannot be made out. Pure.
 */
if (!function_exists('ias_fb_post_id')) {
  function ias_fb_post_id(string $raw, string $page_id = ''): string {
    $id = trim((string) preg_replace('/^fb_/i', '', trim($raw)));
    if ($id === '' || !preg_match('/^[A-Za-z0-9_]+$/', $id)) return '';
    if (strpos($id, '_') !== false) return $id;
    $page_id = trim($page_id);
    return preg_match('/^\d+$/', $id) && preg_match('/^\d+$/', $page_id) ? $page_id . '_' . $id : $id;
  }
}

/** Do two of Facebook's ids for a post name the same one (with or without the page in front)? Pure. */
if (!function_exists('ias_fb_ids_match')) {
  function ias_fb_ids_match(string $a, string $b): bool {
    $a = ias_fb_post_id($a);
    $b = ias_fb_post_id($b);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    if (strpos($a, '_') !== false && strpos($b, '_') !== false) return false; // both name their page, and differ
    $tail = function (string $id) { $p = strrpos($id, '_'); return $p === false ? $id : substr($id, $p + 1); };
    return $tail($a) === $tail($b);
  }
}

/** The numbers OneUp gave for one post, as stored: reactions, comments, shares, clicks, views and the engagement rate (%). Pure. */
if (!function_exists('ias_stats_numbers')) {
  function ias_stats_numbers(array $row): array {
    $st = (array) ($row['stats'] ?? []);
    $n = [];
    foreach (['reactions' => 'reactions', 'comments' => 'comments', 'shares' => 'shares', 'clicks' => 'clicks', 'views' => 'media_views'] as $k => $src) $n[$k] = max(0, (int) ($st[$src] ?? $st[$k] ?? 0));
    $n['rate'] = ias_stats_rate($n);
    return $n;
  }
}

/** OneUp's engagement rate: (reactions + comments + shares + clicks) / views, in percent, one decimal; null with no views. Pure. */
if (!function_exists('ias_stats_rate')) {
  function ias_stats_rate(array $n): ?float {
    $views = (int) ($n['views'] ?? 0);
    if ($views <= 0) return null;
    return round(((int) ($n['reactions'] ?? 0) + (int) ($n['comments'] ?? 0) + (int) ($n['shares'] ?? 0) + (int) ($n['clicks'] ?? 0)) / $views * 100, 1);
  }
}

/** The numbers in one line: "180 reactions · 32 comments · 45 shares · 210 clicks · 5,200 views · 9.0% engagement". Pure. */
if (!function_exists('ias_stats_line')) {
  function ias_stats_line(array $n): string {
    $bits = [];
    foreach (['reactions' => ['reaction', 'reactions'], 'comments' => ['comment', 'comments'], 'shares' => ['share', 'shares'], 'clicks' => ['click', 'clicks'], 'views' => ['view', 'views']] as $k => [$one, $many]) {
      $v = (int) ($n[$k] ?? 0);
      $bits[] = number_format($v) . ' ' . ($v === 1 ? $one : $many);
    }
    if (isset($n['rate']) && $n['rate'] !== null) $bits[] = number_format((float) $n['rate'], 1) . '% engagement';
    return implode(' · ', $bits);
  }
}

/** A time from the analytics ("2026-04-15T10:30:00+00:00") in site time ('Y-m-d H:i:s'), '' when unreadable. */
if (!function_exists('ias_stats_time')) {
  function ias_stats_time(string $raw): string {
    if (trim($raw) === '') return '';
    try {
      return (new DateTimeImmutable($raw, new DateTimeZone('UTC')))->setTimezone(ia_wp_tz())->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
      return '';
    }
  }
}

/** Is a read due? Once a day (20 hours apart); a week after OneUp said the plan has no analytics. */
if (!function_exists('ias_stats_due')) {
  function ias_stats_due(): bool {
    if (!ias_setting('stats_on', true)) return false;
    $last = get_option('ias_stats_last', []);
    if (!is_array($last) || empty($last['at'])) return true;
    $wait = !empty($last['no_plan']) ? '-7 days' : '-20 hours';
    return (string) $last['at'] <= ias_now()->modify($wait)->format('Y-m-d H:i:s');
  }
}

/** What stops reading the numbers now ([] = nothing): the setting, then everything a read needs. */
if (!function_exists('ias_stats_blockers')) {
  function ias_stats_blockers(): array {
    if (!ias_setting('stats_on', true)) return ['reading how posts did is off (Settings → OneUp)'];
    return ias_oneup_blockers(false);
  }
}

/**
 * Read the page's numbers from OneUp and keep them on our posts. Returns
 * ['ok', 'error', 'rows' (posts OneUp gave), 'matched', 'updated',
 * 'skipped' (why nothing was asked)]. One call; never a write.
 */
if (!function_exists('ias_stats_run')) {
  function ias_stats_run(string $by = ''): array {
    $out = ['ok' => false, 'error' => '', 'rows' => 0, 'matched' => 0, 'updated' => 0, 'skipped' => ''];
    $block = ias_stats_blockers();
    if ($block) { $out['skipped'] = implode('; ', $block); return $out; }
    $now = ias_now();
    $start = $now->modify('-31 days')->format('Y-m-d');
    $end = $now->modify('-1 day')->format('Y-m-d');
    // Ours that could be on that list: gone out from a day before the window to now.
    $ours = [];
    foreach (ias_posts_query(['status' => 'published', 'limit' => 1000, 'recent' => true]) as $p) {
      if ($p['type'] === 'human' || !empty($p['flags']['dry_run']) || ias_post_sent($p)['mode'] !== 'live') continue;
      $at = (string) ($p['published_at'] ?: ($p['flags']['oneup']['when'] ?? ''));
      if ($at === '' || substr($at, 0, 10) < $now->modify('-32 days')->format('Y-m-d')) continue;
      $ours[(string) $p['id']] = $p;
    }
    if (!$ours) { $out['skipped'] = 'no post of ours went out in the last month'; return $out; }

    $tz = ia_wp_tz()->getName();
    $params = ['social_network_id' => (string) ias_setting('oneup_account_id', ''), 'start_date' => $start, 'end_date' => $end];
    if (preg_match('#^[A-Za-z]+/[A-Za-z_/+-]+$|^UTC$#', $tz)) $params['timezone'] = $tz;
    $r = ias_oneup_call('fb_stats', $params);
    $last = ['at' => ias_now_sql(), 'by' => $by, 'ok' => false, 'error' => '', 'rows' => 0, 'matched' => 0, 'no_plan' => false];
    if (!$r['ok']) {
      $last['no_plan'] = (int) $r['code'] === 403;
      $last['error'] = $last['no_plan'] ? 'OneUp says this plan has no analytics (they need its Intermediate plan or above) — asked again in a week, or press Read now.' : $r['error'];
      update_option('ias_stats_last', $last, false);
      ias_log('readback', 0, ['summary' => 'How posts did on Facebook: OneUp did not answer — ' . $last['error']], $by);
      $out['error'] = $last['error'];
      return $out;
    }
    $data = is_array($r['data']) ? $r['data'] : [];
    $rows = (array) ($data['data']['posts'] ?? $data['posts'] ?? []);
    $rows = array_values(array_filter($rows, 'is_array'));
    $out['rows'] = count($rows);

    // By Facebook's id first (known from an earlier read, or the published list's network id), then by words and time.
    $cands = [];
    foreach ($ours as $k => $p) {
      $o = (array) $p['flags']['oneup'];
      $cands[$k] = ['text' => (string) ($o['text'] ?? ''), 'when' => (string) ($p['published_at'] ?: ($o['when'] ?? ''))];
    }
    $taken = [];
    foreach ($rows as $row) {
      $fb = ias_fb_post_id((string) ($row['id'] ?? ''), (string) ($row['page_id'] ?? ''));
      $key = null;
      if ($fb !== '') {
        foreach ($ours as $k => $p) {
          if (isset($taken[$k])) continue;
          $o = (array) $p['flags']['oneup'];
          if (ias_fb_ids_match($fb, (string) ($o['fb_id'] ?? '')) || ias_fb_ids_match($fb, (string) ($o['published_id'] ?? ''))) { $key = (string) $k; break; }
        }
      }
      if ($key === null) $key = ias_published_pick((string) ($row['message'] ?? ''), ias_stats_time((string) ($row['created_time'] ?? '')), array_diff_key($cands, $taken));
      if ($key === null) continue;
      $taken[$key] = true;
      unset($cands[$key]);
      $out['matched']++;
      $p = $ours[$key];
      $n = ias_stats_numbers($row);
      $flags = $p['flags'];
      $old = (array) ($flags['stats'] ?? []);
      $url = (string) ($row['permalink_url'] ?? '');
      $same = array_intersect_key($old, $n) == $n && (string) ($old['url'] ?? '') === $url && (string) ($flags['oneup']['fb_id'] ?? '') === $fb;
      if ($same) continue;
      $flags['stats'] = $n + ['at' => ias_now_sql(), 'url' => esc_url_raw($url)];
      if ($fb !== '') $flags['oneup']['fb_id'] = mb_substr($fb, 0, 80);
      ias_post_update((int) $p['id'], ['flags' => $flags]);
      $out['updated']++;
    }
    $out['ok'] = true;
    $last['ok'] = true;
    $last['rows'] = $out['rows'];
    $last['matched'] = $out['matched'];
    update_option('ias_stats_last', $last, false);
    if ($out['updated'] || $by !== '') ias_log('readback', 0, ['summary' => 'How posts did on Facebook: ' . $out['matched'] . ' of ours among ' . $out['rows'] . ' page posts' . ($out['updated'] ? ', ' . $out['updated'] . ' updated' : '') . '.'], $by);
    return $out;
  }
}

/** The post's numbers as stored, or null when none have been read yet. */
if (!function_exists('ias_post_stats')) {
  function ias_post_stats(array $p): ?array {
    $s = $p['flags']['stats'] ?? null;
    return is_array($s) && isset($s['views']) ? $s : null;
  }
}

/** The hourly job: once a day, after the pushes and the read-back. */
add_filter('ias_job_run', function (array $result): array {
  if (!ias_stats_due() || ias_stats_blockers()) return $result;
  $r = ias_stats_run();
  if ($r['skipped'] !== '') {
    // Nothing of ours went out lately: nothing asked, and asked again tomorrow.
    update_option('ias_stats_last', ['at' => ias_now_sql(), 'by' => '', 'ok' => true, 'error' => '', 'rows' => 0, 'matched' => 0, 'no_plan' => false, 'note' => $r['skipped']], false);
    return $result;
  }
  $result['parts'][] = $r['ok'] ? 'Facebook numbers: ' . $r['matched'] . ' posts' : 'Facebook numbers failed: ' . $r['error'];
  return $result;
}, 35);

/** On the post's page: how it did. */
add_action('ias_post_view_side', function (array $p): void {
  if ($p['type'] === 'human' || $p['status'] !== 'published') return;
  $s = ias_post_stats($p);
  echo '<div class="ias-card"><h3>How it did on Facebook</h3>';
  if (!$s) {
    echo '<p class="description">' . esc_html(ias_setting('stats_on', true) ? 'Not read yet: OneUp\'s numbers for a post come the day after it goes out (read once a day).' : 'Reading how posts did is off (Settings → OneUp).') . '</p></div>';
    return;
  }
  echo '<p>' . esc_html(ias_stats_line($s)) . '</p><p class="description">As OneUp had them ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $s['at']))) . '.'
    . (!empty($s['url']) ? ' <a href="' . esc_url((string) $s['url']) . '" target="_blank" rel="noopener">See it on Facebook</a>' : '') . '</p></div>';
}, 15);

/** Settings → OneUp connection: when the numbers were last read, and Read now. */
add_action('admin_init', function () {
  if (!isset($_POST['ias_stats_now']) || !current_user_can('manage_options')) return;
  check_admin_referer('ias_stats_now');
  $r = ias_stats_run(ias_actor());
  $msg = $r['skipped'] !== '' ? 'Nothing read: ' . $r['skipped'] . '.' : ($r['ok'] ? 'Read: ' . $r['matched'] . ' of ours among ' . $r['rows'] . ' posts on the page' . ($r['updated'] ? ', ' . $r['updated'] . ' updated' : '') . '.' : 'OneUp did not answer: ' . $r['error']);
  ia_flash_set('<div class="notice notice-' . ($r['ok'] ? 'success' : 'warning') . '"><p><strong>How posts did on Facebook:</strong> ' . esc_html($msg) . '</p></div>');
  wp_safe_redirect(ias_admin_url(IAS_PAGE_SETTINGS) . '#ias-stats');
  exit;
});

add_action('ias_settings_after_form', function () {
  $last = get_option('ias_stats_last', []);
  $block = ias_stats_blockers();
  echo '<div class="ias-card" style="max-width:760px"><h2 id="ias-stats" style="margin-top:0">How posts did on Facebook</h2>'
    . '<p>Once a day the plugin asks OneUp how each post did — reactions, comments, shares, clicks and views — and shows it on the post and in the monthly summary. It only reads (OneUp\'s Intermediate plan or above has the numbers).</p><p>';
  if (is_array($last) && !empty($last['at'])) {
    echo 'Last read ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $last['at']))) . ': '
      . ($last['ok'] ? esc_html(!empty($last['note']) ? 'nothing asked — ' . $last['note'] . '.' : $last['matched'] . ' of ours among ' . $last['rows'] . ' posts on the page.') : ia_ui_badge('failed', 'bad') . ' ' . esc_html((string) $last['error']));
  } else {
    echo esc_html($block ? 'Not read yet: ' . implode('; ', $block) . '.' : 'Not read yet — the next hourly run reads it.');
  }
  echo '</p><form method="post">' . wp_nonce_field('ias_stats_now', '_wpnonce', true, false) . '<input type="hidden" name="ias_stats_now" value="1"><button class="button"' . ($block ? ' disabled title="' . esc_attr(implode('; ', $block)) . '"' : '') . ' data-ia-busy="Asking OneUp…">Read now</button></form></div>';
}, 15);
