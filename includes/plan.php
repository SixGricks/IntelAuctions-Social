<?php
/**
 * Intel Auctions Social — the morning plan, the pushes, and cancelling at once.
 *
 * Every morning from 5:00 (Settings: planning hour) the hourly job re-plans
 * the next 30 days with the scheduler (scheduler.php) and writes the result:
 * each post's day and time, the posts dropped and why, the ones running late.
 * Then the pushes: every post scheduled in the next 21 days that is ready —
 * a Lane B post with a clean draft, or a Lane A post someone approved — is
 * rendered for OneUp, and every post that went out and is no longer ready
 * is taken back. In this version that is a dry run: each request is written to
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
    $sum = ['placed' => 0, 'moved' => 0, 'dropped' => 0, 'reinstated' => 0, 'late' => 0, 'relaxed' => 0, 'unplaced' => 0, 'blocked' => 0];
    $fmt = function (string $at) { return date_i18n('D M j, g:i a', strtotime($at)); };

    foreach ($plan['at'] as $key => $at) {
      $r = $by_key[$key] ?? null;
      if (!$r) continue;
      $new = $at . ':00';
      $old = (string) ($r['scheduled_at'] ?? '');
      $fields = [];
      $flags = $r['flags'];
      if (!empty($flags['plan_note'])) { // the plan's own note ("waits for…", "could not be placed") goes once it is placed
        if ((string) $r['note'] === (string) $flags['plan_note']) $fields['note'] = '';
        unset($flags['plan_note']);
      }
      if ($r['status'] === 'dropped') {
        unset($flags['dropped_by']);
        $fields['status'] = ias_back_on_plan_status(['flags' => $flags]); // a draft that stopped for a person comes back stopped
        $fields['note'] = $fields['status'] === 'held' ? 'Needs a decision: ' . (string) ($flags['holds'][0] ?? '') : '';
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
    // Posts the plan could not place lose their old time, so nothing goes out at a time the plan no longer holds.
    foreach (['unplaced' => 'Could not be placed: ', 'blocked' => ''] as $what => $prefix) {
      foreach ($plan[$what] ?? [] as $key => $why) {
        $r = $by_key[$key] ?? null;
        if (!$r) continue;
        $flags = $r['flags'];
        $flags['plan_note'] = $why;
        ias_post_update((int) $r['id'], ['note' => $why, 'flags' => $flags, 'scheduled_at' => null]);
        if ((string) $r['note'] !== $why) ias_log('late', (int) $r['listing_id'], ['post' => (int) $r['id'], 'type' => $r['type'], 'summary' => $prefix . $why], $actor);
        $sum[$what] = ($sum[$what] ?? 0) + 1;
      }
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
    if (ias_has_verify((array) $p['content'])) return 'the text still has a [VERIFY] marker';
    if ($p['status'] === 'held' || ias_post_needs_decision($p)) return 'it needs a decision';
    if (!empty($p['flags']['stale'])) return 'the listing changed and the draft has not been rewritten yet';
    if ($p['lane'] === 'A' && $p['status'] !== 'approved') return 'it is waiting for approval';
    if (!in_array($p['status'], ['planned', 'approved', 'pushed'], true)) return 'it is ' . $p['status'];
    return '';
  }
}

/**
 * Bring OneUp in line with the plan for the next `push_days` days: schedule
 * new posts, re-schedule moved or changed ones (OneUp cannot change a
 * scheduled post's time: delete, then schedule again), take back the ones that
 * stopped being ready or left the plan.
 *
 * It runs in one of two modes (ias_push_mode()): **live** when the master
 * switch is on, dry run off and the key and ids are set — then each request
 * goes to OneUp (live.php), Pause and each post type's switch still stopping
 * new posts (never a take-back); otherwise **dry run** — each request goes to
 * the log once per change instead. Both keep the same record of what was
 * "sent" (ias_post_sent(): the OneUp id and hash, or the dry run's hash), so
 * the plan behaves the same either way: a post sent and inside the veto window
 * is fixed in place (scheduler.php), a post sent and no longer ready —
 * stale, held, its approval withdrawn, moved off the plan or out of reach —
 * is taken back, and a post whose time came while it was sent counts as
 * published (a dry-run one flagged `dry_run`: nothing went anywhere; a live
 * one until the read-back confirms it). A Lane B post never sent goes only
 * while its time is still outside the veto window; otherwise it waits for the
 * next morning's plan to give it a later one. A post a person edited in OneUp
 * (`locked`) is never replaced, and taken back only when skipped or cancelled.
 */
if (!function_exists('ias_push_run')) {
  function ias_push_run(): array {
    $out = ['dryrun' => 0, 'deleted' => 0, 'published' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => [], 'readback' => null];
    if (!ias_db_ready()) return $out;
    // One run at a time: the hourly job, Plan now and an emailed Approve can meet.
    if (!ias_push_lock()) { $out['skipped'][] = 'another run was pushing'; return $out; }
    try {
      return ias_push_run_locked($out);
    } finally {
      ias_push_unlock();
    }
  }
}

/**
 * Send these posts to OneUp now — a person's change does not wait for the
 * hourly run (DJ, Oct 13, 2026: "instant update both directions"): under the
 * same lock and the same rules, after reading OneUp's scheduled list (so a
 * person's change there is still never overwritten). Returns the run's counts,
 * or ['busy' => true] when another run holds the lock (the hourly run then
 * catches it up).
 */
if (!function_exists('ias_push_now')) {
  function ias_push_now(array $ids): array {
    $out = ['dryrun' => 0, 'deleted' => 0, 'published' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => [], 'readback' => null];
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !ias_db_ready()) return $out;
    if (!ias_push_lock()) return $out + ['busy' => true];
    try {
      return ias_push_run_locked($out, $ids, false);
    } finally {
      ias_push_unlock();
    }
  }
}

/** The post actions whose change goes to OneUp at once (approve sends its own run). */
if (!function_exists('ias_push_now_actions')) {
  function ias_push_now_actions(): array {
    return (array) apply_filters('ias_push_now_actions', ['edit', 'media', 'media_reset', 'regenerate', 'skip', 'restore', 'release', 'move', 'unpin', 'retry', 'unlock', 'gbp_off', 'gbp_on', 'gbp_unlock']);
  }
}

/**
 * Send one post now and add what happened on OneUp to the page's notice:
 * scheduled, replaced, taken off, or why not — for Facebook and Google. In
 * the dry run it says nothing (the post's page shows what would go).
 */
if (!function_exists('ias_push_now_flash')) {
  function ias_push_now_flash(int $id): void {
    $before = ias_post_get($id);
    if (!$before || $before['type'] === 'human') return;
    $live = ias_push_mode() === 'live';
    $glive = function_exists('ias_gbp_live') && ias_gbp_live();
    if (!$live && !$glive) return;
    $r = ias_push_now([$id]);
    $after = ias_post_get($id) ?: $before;
    $bits = [];
    if (!empty($r['busy'])) {
      $bits[] = 'Another run is sending to OneUp right now — this change follows within the hour.';
    } else {
      $say = function (string $who, string $was, string $is, string $when) {
        if ($was === $is) return '';
        if ($is === '') return $who . ': taken off OneUp.';
        return $who . ': ' . ($was === '' ? 'scheduled' : 'replaced') . ' on OneUp' . ($when !== '' ? ' for ' . date_i18n('D M j, g:i a', strtotime($when)) : '') . '.';
      };
      if ($live) {
        $was = (string) (($before['flags']['oneup']['hash'] ?? '') . ($before['flags']['oneup']['id'] ?? ''));
        $is = (string) (($after['flags']['oneup']['hash'] ?? '') . ($after['flags']['oneup']['id'] ?? ''));
        $bits[] = $say('Facebook', $was, $is, (string) ($after['scheduled_at'] ?? ''));
      }
      if ($glive) {
        $was = (string) (($before['flags']['gbp']['hash'] ?? '') . ($before['flags']['gbp']['id'] ?? ''));
        $is = (string) (($after['flags']['gbp']['hash'] ?? '') . ($after['flags']['gbp']['id'] ?? ''));
        $bits[] = $say('Google', $was, $is, (string) ($after['flags']['gbp']['when'] ?? ''));
      }
      if (!empty($r['failed'])) {
        $last = array_values(array_filter(ias_log_recent('push_failed', 20), function ($e) use ($id) { return (int) ($e['meta']['post'] ?? 0) === $id; }));
        $bits[] = 'OneUp did not take it' . ($last ? ': ' . (string) $last[0]['meta']['summary'] : '') . ' — tried again on the next run.';
      }
    }
    $bits = array_values(array_filter($bits));
    if (!$bits) return;
    $key = 'ia_flash_' . get_current_user_id();
    $html = (string) get_transient($key);
    ia_flash_set($html . '<div class="notice notice-' . (!empty($r['failed']) ? 'warning' : 'info') . '"><p><strong>OneUp</strong> — ' . esc_html(implode(' ', $bits)) . '</p></div>');
  }
}

/** Take the push lock (atomic; a lock older than 10 minutes is a crashed run's and is taken over). */
if (!function_exists('ias_push_lock')) {
  function ias_push_lock(): bool {
    global $wpdb;
    $name = 'ias_push_lock';
    $now = time();
    wp_cache_delete($name, 'options');
    if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now))) return true;
    $held = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    if ((int) $held > $now - 600) return false;
    return (bool) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $name, $held));
  }
}
if (!function_exists('ias_push_unlock')) {
  function ias_push_unlock(): void {
    global $wpdb;
    $wpdb->delete($wpdb->options, ['option_name' => 'ias_push_lock']);
    wp_cache_delete('ias_push_lock', 'options');
  }
}

/**
 * The push run, with the lock held. $only: just these posts (a person's change,
 * sent at once — ias_push_now()), after a read-back of OneUp's scheduled list
 * only ($full false: the hourly run reads the published and failed lists too,
 * and concludes what a person deleted).
 */
if (!function_exists('ias_push_run_locked')) {
  function ias_push_run_locked(array $out, array $only = [], bool $full = true): array {
    $mode = ias_push_mode();
    if (function_exists('ias_push_mode_changed')) ias_push_mode_changed($mode);
    // What a person did in OneUp since the last run comes first: their delete or edit is never overwritten.
    unset($GLOBALS['ias_readback_lists']); // this run's lists only
    if (function_exists('ias_readback_run') && !ias_oneup_blockers(false)) $out['readback'] = ias_readback_run($full);
    // Google's own read-back, on the same lists (gbp.php): its posts a person changed in OneUp first, too.
    if (function_exists('ias_gbp_readback')) {
      ias_gbp_mode_changed(ias_gbp_mode());
      $g = ias_gbp_readback($full);
      if ($g) {
        if (is_array($out['readback']) && $out['readback']['ok']) { $out['readback']['changes'] = array_merge($out['readback']['changes'], $g); ias_readback_note($out['readback']); }
        else $out['readback'] = ['ok' => true, 'error' => '', 'changes' => $g];
      }
    }
    $now = ias_now();
    $until = $now->modify('+' . (int) ias_setting('push_days', 21) . ' days')->format('Y-m-d H:i:s');
    $nowsql = $now->format('Y-m-d H:i:s');
    $floor_b = $now->modify('+' . (int) ias_setting('lead_hours', 48) . ' hours')->format('Y-m-d H:i:00');
    $fmt = function (string $at) { return date_i18n('D M j, g:i a', strtotime($at)); };

    $c = ['now' => $now, 'nowsql' => $nowsql, 'until' => $until, 'floor_b' => $floor_b, 'fmt' => $fmt];
    $statuses = ['planned', 'held', 'waiting', 'approved', 'pushed', 'dropped', 'skipped', 'cancelled'];
    $posts = $only ? array_values(array_filter(array_map('ias_post_get', array_map('intval', $only)), function ($p) use ($statuses) { return $p && in_array($p['status'], $statuses, true); }))
      : ias_posts_query(['status' => $statuses, 'limit' => 2000]);
    foreach ($posts as $p) ias_push_one($p, $mode, $c, $out);
    if (function_exists('ias_gbp_run')) { // Google, sent for real once its own switches allow (gbp.php)
      $g = ias_gbp_run($only);
      foreach (['pushed', 'deleted', 'failed'] as $k) $out[$k] += $g[$k];
      $out['skipped'] = array_merge($out['skipped'], $g['skipped']);
    }
    if (function_exists('ias_push_channels_run')) $out['dryrun'] += ias_push_channels_run($only); // Instagram, X, LinkedIn — and Google until it is live: the dry run
    return $out;
  }
}

/**
 * One post's push (the hourly run's loop, and ias_push_now() for a person's
 * change): see ias_push_run(). $c: now, nowsql, until, floor_b, fmt.
 */
if (!function_exists('ias_push_one')) {
  function ias_push_one(array $p, string $mode, array $c, array &$out): void {
    ['now' => $now, 'nowsql' => $nowsql, 'until' => $until, 'floor_b' => $floor_b, 'fmt' => $fmt] = $c;
    if ($p['type'] === 'human') return;
    $flags = $p['flags'];
    $sent = ias_post_sent($p);
    $at = (string) ($p['scheduled_at'] ?? '');

    // Its time came while it was out: it went out.
    if ($sent['hash'] !== '' && $at !== '' && $at <= $nowsql && in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'pushed'], true)) {
      if ($sent['mode'] === 'dry') {
        $flags['dry_run'] = true;
        ias_post_update((int) $p['id'], ['status' => 'published', 'flags' => $flags, 'note' => 'Its time came in the dry run: it counts as published, but nothing was sent to OneUp.']);
        ias_post_trail((int) $p['id'], 'Its time came (dry run — nothing was sent)');
        ias_log('dryrun', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'Would have been published on ' . $fmt($at) . ' — nothing was sent.']);
      } else {
        $flags['unconfirmed'] = true; // the read-back confirms it (or reports it failed)
        ias_post_update((int) $p['id'], ['status' => 'published', 'flags' => $flags, 'published_at' => $at, 'note' => '']);
        ias_post_trail((int) $p['id'], 'Its time came on OneUp');
      }
      if (function_exists('ias_voice_record')) ias_voice_record((int) $p['id'], 'out'); // the log of final posts
      $out['published']++;
      return;
    }

    $in_range = $at !== '' && $at >= $nowsql && $at <= $until;
    $why_not = ias_push_ready($p);
    // A person who moved it there (Move on its page) has already said yes: the veto window is theirs.
    if ($why_not === '' && $sent['hash'] === '' && empty($flags['was_on_oneup']) && empty($flags['pinned_by']) && $p['lane'] !== 'A' && $at !== '' && $at < $floor_b) $why_not = 'its time is inside the 48-hour veto window';

    if ($in_range && $why_not === '') {
      $req = ias_render_facebook($p);
      $hash = md5((string) wp_json_encode($req));
      if ($hash === $sent['hash'] || !empty($p['locked'])) return; // unchanged, or a person edited it in OneUp
      if ($mode === 'live') {
        $blockers = ias_push_blockers($p);
        if ($blockers) { // paused, or its type is off: nothing new goes — and a changed post's old copy comes off rather than going out as it was
          $out['skipped'][] = '#' . (int) $p['id'] . ': ' . implode('; ', $blockers);
          if ($sent['mode'] === 'live' && ias_push_live_delete($p, 'it changed while ' . implode(' and ', $blockers) . ' — it goes again when they allow')) {
            $flags = (ias_post_get((int) $p['id']) ?: $p)['flags'];
            $flags['was_on_oneup'] = true;
            ias_post_update((int) $p['id'], ['flags' => $flags]);
            $out['deleted']++;
          }
          return;
        }
        if (ias_push_live_send($p, $req, $hash)) $out['pushed']++; else $out['failed']++;
        return;
      }
      $calls = $sent['hash'] !== '' ? [ias_render_delete($p), $req] : [$req]; // a scheduled post's time cannot be edited: delete, then schedule again
      $flags['dry_hash'] = $hash;
      ias_post_update((int) $p['id'], ['flags' => $flags]);
      ias_log('dryrun', (int) $p['listing_id'], [
        'post' => (int) $p['id'], 'type' => $p['type'],
        'summary' => ($sent['hash'] !== '' ? 'Would replace on OneUp (changed or moved)' : 'Would schedule on OneUp') . ' for ' . $fmt($at) . ' — not sent: ' . implode('; ', ias_push_blockers($p)) . '.',
        'payload' => count($calls) === 1 ? $calls[0] : $calls,
      ]);
      $out['dryrun']++;
      return;
    }
    // It went out and is no longer ready, or left the next push_days days: take it back.
    if ($sent['hash'] !== '' || $sent['id'] !== '') {
      if (!empty($p['locked']) && !in_array($p['status'], ['skipped', 'cancelled'], true)) return; // a person's edit in OneUp stays unless the post is called off
      $why = $why_not !== '' ? $why_not : 'moved past the next ' . (int) ias_setting('push_days', 21) . ' days';
      if ($sent['mode'] === 'live') {
        if ($mode === 'live' && ias_push_live_delete($p, $why)) $out['deleted']++;
        return;
      }
      unset($flags['dry_hash']);
      ias_post_update((int) $p['id'], ['flags' => $flags]);
      ias_log('dryrun', (int) $p['listing_id'], [
        'post' => (int) $p['id'], 'type' => $p['type'],
        'summary' => 'Would delete from OneUp (' . $why . ') — not sent: ' . implode('; ', ias_push_blockers($p)) . '.',
        'payload' => ias_render_delete($p),
      ]);
      $out['deleted']++;
    }
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
  if ($push['dryrun']) $result['parts'][] = 'dry run: ' . $push['dryrun'] . ' payloads logged';
  if ($push['pushed']) $result['parts'][] = $push['pushed'] . ' scheduled on OneUp';
  if ($push['deleted']) $result['parts'][] = $push['deleted'] . ' taken back';
  if ($push['failed']) $result['parts'][] = $push['failed'] . ' OneUp calls failed';
  if ($push['published']) $result['parts'][] = $push['published'] . ' went out';
  $rb = $push['readback'] ?? null; // the push run reads back first
  if (is_array($rb)) {
    if (!$rb['ok']) $result['parts'][] = 'read-back failed: ' . $rb['error'];
    elseif ($rb['changes']) $result['parts'][] = 'read-back: ' . implode(', ', $rb['changes']);
  }
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
