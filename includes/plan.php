<?php
/**
 * Intel Auctions Social — the morning plan, the pushes, and cancelling at once.
 *
 * Every morning from 5:00 (Settings: planning hour) the hourly job re-plans
 * the next 30 days with the scheduler (scheduler.php) and writes the result:
 * each post's day and time, the posts dropped and why, the ones running late.
 * Then the pushes: every post scheduled in the next 21 days that is ready —
 * a Lane B post with a clean draft, or a Lane A post someone approved — is
 * rendered for OneUp, and every post that left the plan after going out is
 * taken back. In this version that is a dry run: each request is written to
 * the log (`ias.dryrun`) once, when it is new or has changed, with what would
 * stop it really being sent. **Plan now** on the calendar runs it at once.
 *
 * Cancelling does not wait for the morning: unpublishing, password-protecting,
 * trashing or deleting a listing cancels its posts before the request ends,
 * and **Cancel posts** on the listing panel does the same on purpose.
 */

if (!defined('ABSPATH')) exit;

/** Run the plan now. Returns counts for the log. */
if (!function_exists('ias_plan_run')) {
  function ias_plan_run(string $actor = 'system'): array {
    if (!ias_db_ready()) return ['skipped' => ['tables not ready']];
    $now = ias_now();
    $s = ias_settings();
    $rows = ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'pushed', 'published', 'human', 'dropped'], 'limit' => 2000]);
    $by_key = [];
    foreach ($rows as $r) $by_key[$r['slot_key']] = $r;
    $plan = ias_schedule(ias_engine_posts($rows, $now, 60 * (int) $s['lead_hours']), ias_engine_cfg($s, $now));
    $sum = ['placed' => 0, 'moved' => 0, 'dropped' => 0, 'reinstated' => 0, 'late' => 0, 'relaxed' => 0, 'unplaced' => 0];
    $fmt = function (string $at) { return date_i18n('D M j, g:i a', strtotime($at)); };

    foreach ($plan['at'] as $key => $at) {
      $r = $by_key[$key] ?? null;
      if (!$r) continue;
      $new = $at . ':00';
      $old = (string) ($r['scheduled_at'] ?? '');
      $fields = [];
      $flags = $r['flags'];
      if ($r['status'] === 'dropped') {
        $fields['status'] = 'planned';
        unset($flags['dropped_by']);
        $fields['note'] = '';
        ias_post_trail((int) $r['id'], 'Back on the plan: room opened up on ' . $fmt($at), $actor);
        ias_log('planned', (int) $r['listing_id'], ['post' => (int) $r['id'], 'type' => $r['type'], 'summary' => 'Back on the plan for ' . $fmt($at)], $actor);
        $sum['reinstated']++;
      }
      if ($old !== $new) {
        $fields['scheduled_at'] = $new;
        if ($old !== '' && $r['status'] !== 'dropped') {
          ias_post_trail((int) $r['id'], 'Moved from ' . $fmt($old) . ' to ' . $fmt($at), $actor);
          ias_log('moved', (int) $r['listing_id'], ['post' => (int) $r['id'], 'type' => $r['type'], 'summary' => 'Moved from ' . $fmt($old) . ' to ' . $fmt($at)], $actor);
          $sum['moved']++;
        }
      }
      foreach (['late' => 'late', 'relaxed' => 'relaxed'] as $what => $flag) {
        if (isset($plan[$what][$key])) {
          if (($flags[$flag] ?? '') !== $plan[$what][$key]) {
            ias_post_trail((int) $r['id'], $plan[$what][$key], $actor);
            ias_log('late', (int) $r['listing_id'], ['post' => (int) $r['id'], 'type' => $r['type'], 'summary' => $plan[$what][$key]], $actor);
          }
          $flags[$flag] = $plan[$what][$key];
          $sum[$what]++;
        } else {
          unset($flags[$flag]);
        }
      }
      $fields['flags'] = $flags;
      ias_post_update((int) $r['id'], $fields);
      $sum['placed']++;
    }

    foreach ($plan['dropped'] as $key => $why) {
      $r = $by_key[$key] ?? null;
      if (!$r || $r['status'] === 'dropped') continue;
      $flags = $r['flags'];
      $flags['dropped_by'] = 'scheduler';
      ias_post_update((int) $r['id'], ['status' => 'dropped', 'note' => $why, 'flags' => $flags, 'scheduled_at' => null]);
      ias_post_trail((int) $r['id'], 'Dropped: ' . $why, $actor);
      ias_log('dropped', (int) $r['listing_id'], ['post' => (int) $r['id'], 'type' => $r['type'], 'summary' => $why], $actor);
      if ($r['status'] === 'pushed') do_action('ias_post_cancelled', (int) $r['id'], $r);
      $sum['dropped']++;
    }
    foreach ($plan['unplaced'] as $key => $why) {
      $r = $by_key[$key] ?? null;
      if (!$r) continue;
      ias_post_update((int) $r['id'], ['note' => $why]);
      ias_log('late', (int) $r['listing_id'], ['post' => (int) $r['id'], 'type' => $r['type'], 'summary' => 'Could not be placed: ' . $why], $actor);
      $sum['unplaced']++;
    }
    foreach ($plan['later'] as $key => $why) {
      $r = $by_key[$key] ?? null;
      if ($r && !empty($r['scheduled_at']) && $r['status'] !== 'pushed') ias_post_update((int) $r['id'], ['scheduled_at' => null]);
    }

    update_option('ias_last_plan', $now->format('Y-m-d'), false);
    update_option('ias_last_plan_summary', ['at' => $now->format('Y-m-d H:i'), 'by' => $actor] + $sum, false);
    return $sum;
  }
}

/** Is the morning plan due? (From the planning hour, once a day; not counting Plan now.) */
if (!function_exists('ias_plan_due')) {
  function ias_plan_due(): bool {
    $now = ias_now();
    return (int) $now->format('G') >= (int) ias_setting('plan_hour', 5) && get_option('ias_last_plan', '') !== $now->format('Y-m-d');
  }
}

/* ---------------- Pushes (dry run in this version) ---------------- */

/** Is this post ready to go to OneUp (apart from the switches)? '' = yes, else why not. */
if (!function_exists('ias_push_ready')) {
  function ias_push_ready(array $p): string {
    if (empty($p['scheduled_at'])) return 'no time yet';
    if (empty($p['content']['text'])) return 'no draft yet';
    if ($p['status'] === 'held') return 'it needs a decision';
    if (!empty($p['flags']['stale'])) return 'the listing changed and the draft has not been rewritten yet';
    if ($p['lane'] === 'A' && $p['status'] !== 'approved') return 'it is waiting for approval';
    if (!in_array($p['status'], ['planned', 'approved', 'pushed'], true)) return 'it is ' . $p['status'];
    return '';
  }
}

/**
 * Bring OneUp in line with the plan for the next `push_days` days: create new
 * posts, re-create moved or changed ones, delete ones that left the plan. In a
 * dry run each request is logged once per change; in this version a dry run
 * is the only mode (live pushing is Phase 5).
 */
if (!function_exists('ias_push_run')) {
  function ias_push_run(): array {
    $out = ['dryrun' => 0, 'deleted' => 0, 'skipped' => []];
    if (!ias_db_ready()) return $out;
    $now = ias_now();
    $until = $now->modify('+' . (int) ias_setting('push_days', 21) . ' days')->format('Y-m-d H:i:s');
    $nowsql = $now->format('Y-m-d H:i:s');

    foreach (ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'pushed', 'dropped', 'skipped', 'cancelled'], 'limit' => 2000]) as $p) {
      if ($p['type'] === 'human') continue;
      $flags = $p['flags'];
      $sent = (string) ($flags['dry_hash'] ?? '');
      $in_range = !empty($p['scheduled_at']) && $p['scheduled_at'] >= $nowsql && $p['scheduled_at'] <= $until;
      $why_not = ias_push_ready($p);

      if ($in_range && $why_not === '') {
        $req = ias_render_facebook($p);
        $hash = md5((string) wp_json_encode($req));
        if ($hash === $sent) continue;
        $calls = $sent !== '' ? [ias_render_delete($p), $req] : [$req]; // a scheduled post's time cannot be edited: delete, then schedule again
        $flags['dry_hash'] = $hash;
        ias_post_update((int) $p['id'], ['flags' => $flags]);
        ias_log('dryrun', (int) $p['listing_id'], [
          'post' => (int) $p['id'], 'type' => $p['type'],
          'summary' => ($sent !== '' ? 'Would replace on OneUp (changed or moved)' : 'Would schedule on OneUp') . ' for ' . date_i18n('D M j, g:i a', strtotime((string) $p['scheduled_at'])) . ' — not sent: ' . implode('; ', ias_push_blockers($p)) . '.',
          'payload' => count($calls) === 1 ? $calls[0] : $calls,
        ]);
        $out['dryrun']++;
        continue;
      }
      // It went out (in the dry run's sense) and has since left the plan: take it back.
      if ($sent !== '' && in_array($p['status'], ['dropped', 'skipped', 'cancelled'], true)) {
        unset($flags['dry_hash']);
        ias_post_update((int) $p['id'], ['flags' => $flags]);
        ias_log('dryrun', (int) $p['listing_id'], [
          'post' => (int) $p['id'], 'type' => $p['type'],
          'summary' => 'Would delete from OneUp (' . $p['status'] . ') — not sent: ' . implode('; ', ias_push_blockers($p)) . '.',
          'payload' => ias_render_delete($p),
        ]);
        $out['deleted']++;
      }
    }
    return $out;
  }
}

/* ---------------- The hourly job's later steps ---------------- */

add_filter('ias_job_run', function (array $result): array {
  if (ias_plan_due()) {
    $result['plan'] = ias_plan_run();
    $result['parts'][] = 'planned: ' . (int) $result['plan']['placed'] . ' placed'
      . (!empty($result['plan']['moved']) ? ', ' . (int) $result['plan']['moved'] . ' moved' : '')
      . (!empty($result['plan']['dropped']) ? ', ' . (int) $result['plan']['dropped'] . ' dropped' : '');
  }
  $push = ias_push_run();
  $result['push'] = $push;
  if ($push['dryrun'] || $push['deleted']) $result['parts'][] = 'dry run: ' . ($push['dryrun'] + $push['deleted']) . ' payloads logged';
  return $result;
}, 10);

/* ---------------- Cancelling at once ---------------- */

/** Listings that changed in a way that cancels posts this request; synced once at shutdown. */
if (!function_exists('ias_mark_dirty')) {
  function ias_mark_dirty(int $post_id): void {
    if (get_post_type($post_id) !== ia_schema_post_type()) return;
    $GLOBALS['ias_dirty'][$post_id] = true;
  }
}

add_action('transition_post_status', function ($new, $old, $post) {
  if (!($post instanceof WP_Post) || $post->post_type !== ia_schema_post_type()) return;
  if ($old === 'publish' && $new !== 'publish') ias_mark_dirty((int) $post->ID); // unpublished, private, trashed, scheduled
  if ($new === 'publish' && $old !== 'publish') ias_mark_dirty((int) $post->ID); // republished: its posts come back
}, 10, 3);

add_action('post_updated', function ($id, $after, $before) {
  if (!($after instanceof WP_Post) || $after->post_type !== ia_schema_post_type()) return;
  if ((string) $after->post_password !== (string) $before->post_password) ias_mark_dirty((int) $id);
}, 10, 3);

add_action('before_delete_post', function ($id) {
  if (get_post_type((int) $id) === ia_schema_post_type()) $GLOBALS['ias_dirty'][(int) $id] = true;
});

add_action('shutdown', function () {
  if (empty($GLOBALS['ias_dirty']) || !function_exists('ias_sync')) return;
  $ids = array_keys($GLOBALS['ias_dirty']);
  $GLOBALS['ias_dirty'] = [];
  try {
    $r = ias_sync();
    if (!empty($r['cancelled']) || !empty($r['revived'])) {
      ias_log('run', 0, ['summary' => 'Listing ' . implode(', ', array_map(function ($i) { return '#' . $i; }, $ids)) . ' changed: ' . (int) ($r['cancelled'] ?? 0) . ' posts cancelled, ' . (int) ($r['revived'] ?? 0) . ' back']);
    }
  } catch (\Throwable $e) {
    if (function_exists('error_log')) error_log('[Intel Auctions Social] cancelling posts failed: ' . $e->getMessage());
  }
});

/** Cancel posts / Resume posts from the listing panel (a link, since the panel sits inside the post form). */
add_action('admin_post_ias_listing_posts', function () {
  $id = isset($_GET['listing']) ? (int) $_GET['listing'] : 0;
  $what = isset($_GET['do']) ? sanitize_key(wp_unslash($_GET['do'])) : '';
  if (!current_user_can('manage_options') || $id <= 0) wp_die('Not allowed.', 403);
  check_admin_referer('ias_listing_posts_' . $id);
  if ($what === 'cancel') {
    update_post_meta($id, '_ias_posts_cancelled', ias_now()->format('Y-m-d') . ' ' . ias_actor());
    ias_log('cancelled', $id, ['summary' => 'Cancel posts pressed on the listing panel'], ias_actor());
  } elseif ($what === 'resume') {
    delete_post_meta($id, '_ias_posts_cancelled');
    ias_log('planned', $id, ['summary' => 'Resume posts pressed on the listing panel'], ias_actor());
  }
  $r = ias_sync();
  set_transient('ias_listing_notice_' . get_current_user_id(), $what === 'cancel'
    ? 'Posts cancelled for this listing (' . (int) $r['cancelled'] . '). Press Resume posts to bring them back.'
    : 'Posts back on the plan for this listing (' . (int) $r['revived'] . ').', 60);
  wp_safe_redirect(get_edit_post_link($id, 'url') ?: admin_url());
  exit;
});

add_action('ias_listing_panel', function ($post) {
  if (!($post instanceof WP_Post)) return;
  $id = (int) $post->ID;
  $notice = get_transient('ias_listing_notice_' . get_current_user_id());
  if (is_string($notice) && $notice !== '') {
    delete_transient('ias_listing_notice_' . get_current_user_id());
    echo '<p style="background:#edfaef;border-left:3px solid #00a32a;padding:4px 8px">' . esc_html($notice) . '</p>';
  }
  $cancelled = (string) get_post_meta($id, '_ias_posts_cancelled', true);
  $url = function (string $do) use ($id) {
    return wp_nonce_url(admin_url('admin-post.php?action=ias_listing_posts&do=' . $do . '&listing=' . $id), 'ias_listing_posts_' . $id);
  };
  if ($cancelled !== '') {
    echo '<p><strong>Posts cancelled</strong> (' . esc_html($cancelled) . '). <a class="button button-small" href="' . esc_url($url('resume')) . '">Resume posts</a></p>';
  } elseif ($post->post_status === 'publish') {
    echo '<p><a class="button button-small" href="' . esc_url($url('cancel')) . '" onclick="return confirm(\'Cancel every social post for this listing that has not gone out? (For the rare sale that is called off; unpublishing does this too.)\')">Cancel posts</a></p>';
  }
}, 5, 1);
