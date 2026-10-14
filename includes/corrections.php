<?php
/**
 * Intel Auctions Social — correction comments posted by the plugin (0.18.0).
 *
 * A post that went out is never edited (that resets its reach): when a fact
 * it carried changes, a correction is drafted as a comment (live.php,
 * `flags.correction`). Until 0.18.0 a person posted it on Facebook by hand.
 * Now (DJ, Oct 14, 2026: "Yes" — OneUp's Intermediate plan has comments) the
 * plugin can post it through OneUp's comments (POST feed.oneupapp.io/api/
 * reply-to-post, JSON: platform, Facebook's id for the post, the words, the
 * page's id):
 *
 *   - **Post it as a comment** — a person's click, on the calendar's
 *     corrections card and on the post's page;
 *   - **Post corrections automatically** (Settings, `corrections_auto`) — off
 *     at install: the hourly run posts each new correction itself.
 *
 * Either way it needs the master switch on, nothing paused, the key, and
 * Facebook's id for the post (from the daily read of how posts did,
 * stats.php, or the published list); never on a Sunday, never a `[VERIFY]`.
 * Never twice: the attempt is noted before it is sent; an answer that never
 * comes back is looked for among the page's comments (GET fetch-top-comments)
 * and, until it is found or a person decides, never sent again. A plan
 * without comments (403) is said once and not tried again for a week.
 */

if (!defined('ABSPATH')) exit;

add_filter('ias_oneup_endpoints', function (array $eps): array {
  $eps['comment']  = ['POST', 'https://feed.oneupapp.io/api/reply-to-post', 'json']; // a top-level comment on a published post
  $eps['comments'] = ['GET', 'https://feed.oneupapp.io/api/fetch-top-comments'];      // the page's posts with their comments (to find a lost one)
  return $eps;
});

/** Facebook's id for a published post ('' = not known yet): from the analytics, else the published list's network id. */
if (!function_exists('ias_correction_fb_id')) {
  function ias_correction_fb_id(array $p): string {
    $o = (array) ($p['flags']['oneup'] ?? []);
    $id = ias_fb_post_id((string) ($o['fb_id'] ?? ''));
    if ($id === '' || strpos($id, '_') === false) $id = ias_fb_post_id((string) ($o['published_id'] ?? ''), preg_match('/^\d+$/', (string) ias_setting('oneup_account_id', '')) ? (string) ias_setting('oneup_account_id', '') : '');
    return strpos($id, '_') !== false ? $id : '';
  }
}

/** The page's id for the comment ("<page>_<post>" → page), else the account id the page has in OneUp. */
if (!function_exists('ias_correction_page_id')) {
  function ias_correction_page_id(string $fb_id): string {
    $p = strpos($fb_id, '_');
    return $p !== false ? substr($fb_id, 0, $p) : (string) ias_setting('oneup_account_id', '');
  }
}

/**
 * What stops posting this post's correction now ([] = nothing). $auto: the
 * hourly run's own (also its switch, its refusals and a week after a plan
 * without comments).
 */
if (!function_exists('ias_correction_blockers')) {
  function ias_correction_blockers(array $p, bool $auto = false): array {
    $c = (array) ($p['flags']['correction'] ?? []);
    if ($p['status'] !== 'published' || empty($c['text']) || !empty($c['done'])) return ['there is no correction waiting'];
    $out = [];
    if ($auto && !ias_setting('corrections_auto', false)) $out[] = 'posting corrections automatically is off (Settings)';
    if (!ias_setting('master', false)) $out[] = 'the master switch is off';
    if (ias_setting('paused', false)) $out[] = 'posting is paused';
    if (ias_oneup_key() === '') $out[] = 'no OneUp key (Settings → OneUp)';
    if (ias_now()->format('N') === '7') $out[] = 'never on a Sunday';
    if (ias_has_verify(['text' => (string) $c['text']])) $out[] = 'it carries a [VERIFY] marker';
    if (ias_correction_fb_id($p) === '') $out[] = 'Facebook\'s id for the post is not known yet (it comes with the next daily read of how posts did)';
    if (!empty($c['sending'])) $out[] = 'an earlier try got no answer — it is being looked for among the page\'s comments';
    if ($auto) {
      if ((int) ($c['fails'] ?? 0) >= 3) $out[] = 'OneUp refused it three times';
      $np = (string) get_option('ias_comments_no_plan', '');
      if ($np !== '' && $np > ias_now()->modify('-7 days')->format('Y-m-d H:i:s')) $out[] = 'OneUp said this plan has no comments';
    }
    return $out;
  }
}

/**
 * Post this post's correction as a comment on Facebook. Returns ['ok',
 * 'error', 'uncertain']. The caller has checked ias_correction_blockers().
 */
if (!function_exists('ias_correction_post')) {
  function ias_correction_post(array $p, string $by = ''): array {
    $id = (int) $p['id'];
    $p = ias_post_get($id) ?: $p;
    if ($block = ias_correction_blockers($p)) return ['ok' => false, 'error' => implode('; ', $block), 'uncertain' => false];
    $flags = $p['flags'];
    $text = (string) $flags['correction']['text'];
    $fb = ias_correction_fb_id($p);
    $flags['correction']['sending'] = ias_now_sql(); // noted before it is sent: never twice
    unset($flags['correction']['error']);
    ias_post_update($id, ['flags' => $flags]);
    $r = ias_oneup_call('comment', ['platform' => 'facebook', 'post_id' => $fb, 'comment' => $text, 'social_account_id' => ias_correction_page_id($fb)]);
    $who = $by !== '' ? $by : 'the plugin';
    if ($r['ok']) {
      $flags['correction']['done'] = 'posted by ' . $who . ' ' . ias_now_sql();
      $flags['correction']['comment_id'] = mb_substr((string) ($r['data']['data']['comment_id'] ?? ''), 0, 80);
      unset($flags['correction']['sending'], $flags['correction']['fails']);
      ias_post_update($id, ['flags' => $flags]);
      ias_post_trail($id, 'Correction posted as a comment on Facebook', $by);
      ias_log('edited', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Correction posted as a comment on Facebook: ' . $text], $by);
      return ['ok' => true, 'error' => '', 'uncertain' => false];
    }
    if (ias_oneup_uncertain($r)) { // it may have gone: looked for, never sent again blindly
      $flags['correction']['error'] = 'No clear answer from OneUp (' . $r['error'] . ') — looking for it among the page\'s comments before anything is sent again.';
      ias_post_update($id, ['flags' => $flags]);
      ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Correction comment: no clear answer from OneUp — looked for before anything is sent again.'], $by);
      return ['ok' => false, 'error' => $flags['correction']['error'], 'uncertain' => true];
    }
    unset($flags['correction']['sending']);
    if ((int) $r['code'] === 403) update_option('ias_comments_no_plan', ias_now_sql(), false);
    $flags['correction']['fails'] = (int) ($flags['correction']['fails'] ?? 0) + 1;
    $flags['correction']['error'] = (int) $r['code'] === 403 ? 'OneUp says this plan has no comments (they need its Intermediate plan or above).' : 'OneUp refused it: ' . $r['error'];
    ias_post_update($id, ['flags' => $flags]);
    ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Correction comment refused: ' . $flags['correction']['error']], $by);
    return ['ok' => false, 'error' => $flags['correction']['error'], 'uncertain' => false];
  }
}

/**
 * A correction whose answer was lost: is it among the post's comments? Reads
 * the page's posts with their comments, newest first, a few pages. Returns
 * true when found (and marks it posted), false when the post was read and the
 * comment is not there, null when it could not tell.
 */
if (!function_exists('ias_correction_find')) {
  function ias_correction_find(array $p): ?bool {
    $c = (array) ($p['flags']['correction'] ?? []);
    if (empty($c['sending'])) return null;
    $fb = ias_correction_fb_id($p);
    $page = ias_correction_page_id($fb);
    $next = 0;
    for ($i = 0; $i < 5; $i++) {
      $r = ias_oneup_call('comments', ['filter' => 'latest', 'next_id' => (string) $next, 'social_account_id' => $page]);
      if (!$r['ok']) return null;
      $rows = (array) ($r['data']['data'] ?? []);
      foreach ($rows as $row) {
        if (!is_array($row) || !ias_fb_ids_match((string) ($row['post']['post_id'] ?? ''), $fb)) continue;
        $found = false;
        foreach ((array) ($row['comments'] ?? []) as $cm) {
          foreach (array_merge([$cm], (array) ($cm['child_comments'] ?? [])) as $one) {
            if (is_array($one) && ias_norm_text((string) ($one['content'] ?? '')) === ias_norm_text((string) $c['text'])) { $found = (string) ($one['comment_id'] ?? '') ?: true; break 2; }
          }
        }
        if ($found === false) return false;
        $flags = $p['flags'];
        $flags['correction']['done'] = 'posted by the plugin ' . (string) $c['sending'];
        $flags['correction']['comment_id'] = is_string($found) ? mb_substr($found, 0, 80) : '';
        unset($flags['correction']['sending'], $flags['correction']['error'], $flags['correction']['fails']);
        ias_post_update((int) $p['id'], ['flags' => $flags]);
        ias_post_trail((int) $p['id'], 'Correction comment found on Facebook (its answer had been lost) — not posted twice');
        ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'Correction comment found on Facebook after all — not posted twice.']);
        return true;
      }
      $nx = $r['data']['next_id'] ?? null;
      if ($nx === null || $nx === '' || (int) $nx <= $next) return null; // read to the end without the post: cannot tell
      $next = (int) $nx;
    }
    return null;
  }
}

/** A person decides about a correction whose answer was lost: post it again (the plugin forgets the try). */
if (!function_exists('ias_correction_forget_try')) {
  function ias_correction_forget_try(array $p): void {
    $flags = $p['flags'];
    unset($flags['correction']['sending'], $flags['correction']['error']);
    ias_post_update((int) $p['id'], ['flags' => $flags]);
  }
}

/**
 * The hourly job, after the corrections are drafted (live.php, 30): lost
 * answers are looked for; then, with the switch on, each correction waiting
 * is posted (a few a run).
 */
add_filter('ias_job_run', function (array $result): array {
  if (!ias_setting('master', false) || ias_oneup_key() === '') return $result;
  $posted = 0;
  foreach (ias_corrections_waiting() as $p) {
    if (!empty($p['flags']['correction']['sending'])) {
      if (ias_correction_find($p)) $posted++;
      continue;
    }
    if ($posted >= 5 || ias_correction_blockers($p, true)) continue;
    $r = ias_correction_post($p);
    if ($r['ok']) $posted++;
  }
  if ($posted) $result['parts'][] = $posted . ' correction' . ($posted === 1 ? '' : 's') . ' posted as a comment';
  return $result;
}, 31);

/** The calendar's and the post page's button: Post it as a comment (or why not). */
if (!function_exists('ias_correction_button')) {
  function ias_correction_button(array $p, string $back = ''): string {
    $c = (array) ($p['flags']['correction'] ?? []);
    $block = ias_correction_blockers($p);
    $form = function (string $action, string $label, string $cls, string $confirm) use ($p, $back) {
      return '<form method="post" style="display:inline-block;margin-right:6px">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
        . '<input type="hidden" name="ias_cal_action" value="' . esc_attr($action) . '"><input type="hidden" name="ias_post" value="' . (int) $p['id'] . '">'
        . ($back !== '' ? '<input type="hidden" name="ias_back" value="' . esc_url($back) . '">' : '')
        . '<button class="button ' . esc_attr($cls) . '" onclick="return confirm(\'' . esc_attr($confirm) . '\')" data-ia-busy="Posting…">' . esc_html($label) . '</button></form>';
    };
    if (!empty($c['sending'])) {
      return '<p class="description" style="margin:4px 0">' . esc_html((string) ($c['error'] ?? 'Being posted…')) . '</p>'
        . $form('correction_retry', 'Post it again', '', 'OneUp may have posted it already. Check the post on Facebook first. Post the comment again?');
    }
    if ($block) return '<span class="description">Not from here yet: ' . esc_html(implode('; ', $block)) . '.</span> ';
    return $form('correction_post', 'Post it as a comment', 'button-primary', 'Post this correction as a comment on the Facebook post now?')
      . (!empty($c['error']) ? '<span class="description" style="color:#b32d2e">' . esc_html((string) $c['error']) . '</span> ' : '');
  }
}

/** The calendar's actions for it. */
add_action('ias_calendar_action', function (string $action): void {
  if (!in_array($action, ['correction_post', 'correction_retry'], true)) return;
  $p = ias_post_get((int) ($_POST['ias_post'] ?? 0));
  if (!$p) return;
  $actor = ias_actor();
  if ($action === 'correction_retry') {
    if (empty($p['flags']['correction']['sending'])) return;
    ias_post_trail((int) $p['id'], 'Correction: a person chose to post it again (the earlier try had no answer)', $actor);
    ias_correction_forget_try($p);
    $p = ias_post_get((int) $p['id']) ?: $p;
  }
  $r = ias_correction_post($p, $actor);
  ia_flash_set('<div class="notice notice-' . ($r['ok'] ? 'success' : 'warning') . '"><p>' . ($r['ok'] ? 'Posted the correction as a comment on Facebook.' : esc_html('Not posted: ' . $r['error'])) . '</p></div>');
});
