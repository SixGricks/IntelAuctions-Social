<?php
/**
 * Intel Auctions Social — the morning plan, the pushes, and cancelling at once.
 *
 * Every morning from 5:00 (Settings: planning hour) the hourly job re-plans
 * the next 30 days with the scheduler (scheduler.php) and writes the result:
 * each post's day and time, the posts dropped and why, the ones running late.
 * Then the pushes: every post scheduled in the next 21 days that is ready —
 * a Lane B post with a clean draft, or a Lane A post someone approved — goes
 * to OneUp while its switches are on, and every post on OneUp that is no
 * longer ready, or whose switch went off, is taken back. A switch is on or
 * off; there is no dry run (DJ, Oct 14, 2026). **Plan now** on the calendar
 * runs it at once.
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

    $sum['redated'] = function_exists('ias_timing_run') ? ias_timing_run() : 0; // words that no longer fit the day a post now goes out (0.25.0)
    update_option('ias_last_plan', $now->format('Y-m-d'), false);
    update_option('ias_last_plan_summary', ['at' => $now->format('Y-m-d H:i'), 'by' => $actor] + $sum, false);
    delete_option('ias_plan_hold'); // planned: whatever a person moved is worked around now
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

/* ---------------- Pushes ---------------- */

/** Is this post ready to go to OneUp (apart from the switches)? '' = yes, else why not. */
if (!function_exists('ias_push_ready')) {
  function ias_push_ready(array $p, string $network = 'facebook'): string {
    if (empty($p['scheduled_at'])) return 'no time yet';
    if ($network === 'facebook' && ($p['type'] ?? '') === 'manual' && !in_array('facebook', ias_post_networks($p), true)) return 'Facebook is not ticked for it';
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
 * Off means off (DJ, Oct 14, 2026: "Either things are active or inactive"):
 * a post goes to OneUp only while the master switch is on, its type is on,
 * nothing is paused and Verify OneUp has passed (ias_push_blockers()); when
 * one of those switches is off (ias_push_switched_off()), a copy already on
 * OneUp is taken back (`was_on_oneup`, so it goes again when it is on, even
 * inside the veto window). A post on
 * OneUp inside the veto window is fixed in place (scheduler.php); one no
 * longer ready — stale, held, its approval withdrawn, moved off the plan or
 * out of reach — is taken back; one whose time came counts as published
 * until the read-back confirms it. A Lane B post never sent goes only while
 * its time is still outside the veto window; otherwise it waits for the next
 * morning's plan to give it a later one. A post a person edited in OneUp
 * (`locked`) is never replaced, and taken back only when skipped or
 * cancelled.
 */
if (!function_exists('ias_push_run')) {
  function ias_push_run(): array {
    $out = ['deleted' => 0, 'published' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => [], 'readback' => null];
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
    $out = ['deleted' => 0, 'published' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => [], 'readback' => null];
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

/* ---------------- A person's moves: instant, and the plan waits (0.20.0) ---------------- */

/**
 * DJ, Oct 14, 2026: "When moving calendar items they move VERY slowly … Can
 * it just move them and wait to run the agent until later. This will allow a
 * human to make a bunch of changes without AI changing things at the same
 * time." (No AI runs on a move: the time went on the plan shuffling other
 * posts and on OneUp's round trips for each.) So a move (Move, a drag)
 * changes only the post moved; the plan waits until the person is done —
 * `ias_plan_hold`, re-planned on the hourly run once nothing has moved for
 * IAS_PLAN_QUIET minutes, or at once with Plan now — and the moved post goes
 * to OneUp after the page has answered (ias_push_later()).
 */
if (!defined('IAS_PLAN_QUIET')) define('IAS_PLAN_QUIET', 15);

/** A person moved a post: the plan waits until they are done. */
if (!function_exists('ias_plan_hold')) {
  function ias_plan_hold(string $actor): void {
    $h = ias_plan_hold_get();
    update_option('ias_plan_hold', ['first' => $h ? (string) $h['first'] : ias_now_sql(), 'last' => ias_now_sql(), 'n' => ($h ? (int) $h['n'] : 0) + 1, 'by' => $actor], false);
  }
}

/** The plan's wait: ['first', 'last', 'n', 'by'], or [] when it is not waiting. */
if (!function_exists('ias_plan_hold_get')) {
  function ias_plan_hold_get(): array {
    $h = get_option('ias_plan_hold', []);
    return is_array($h) && !empty($h['last']) ? $h : [];
  }
}

/** Has nothing moved for long enough that the plan may tidy up now? */
if (!function_exists('ias_plan_hold_ready')) {
  function ias_plan_hold_ready(): bool {
    $h = ias_plan_hold_get();
    return $h && (string) $h['last'] <= ias_now()->modify('-' . IAS_PLAN_QUIET . ' minutes')->format('Y-m-d H:i:s');
  }
}

/**
 * Send this post to OneUp after the page has answered: it is queued
 * (`ias_push_queue`) and sent at the end of this request, once the browser
 * has its page, so a move is instant however slow OneUp is. What is still
 * queued then (a busy run, a host that cannot answer early) goes with the
 * open calendar's next check or the hourly run.
 */
if (!function_exists('ias_push_later')) {
  function ias_push_later(int $id): void {
    $q = get_option('ias_push_queue', []);
    $q = is_array($q) ? $q : [];
    $q[$id] = ias_now_sql();
    update_option('ias_push_queue', $q, false);
    if (empty($GLOBALS['ias_push_later_hooked'])) {
      $GLOBALS['ias_push_later_hooked'] = true;
      add_action('shutdown', 'ias_push_after_response', 1);
    }
  }
}

/** At the end of the request: let the browser go, then send what is queued. */
if (!function_exists('ias_push_after_response')) {
  function ias_push_after_response(): void {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit(120);
    ias_push_queue_run(30);
  }
}

/** Send what is queued (waiting up to $wait seconds for another run to finish). Returns how many went. */
if (!function_exists('ias_push_queue_run')) {
  function ias_push_queue_run(int $wait = 0): int {
    wp_cache_delete('ias_push_queue', 'options');
    $q = get_option('ias_push_queue', []);
    if (!is_array($q) || !$q || !ias_db_ready()) return 0;
    $deadline = time() + max(0, $wait);
    while (!ias_push_lock()) {
      if (time() >= $deadline) return 0;
      sleep(1);
    }
    try {
      wp_cache_delete('ias_push_queue', 'options');
      $q = (array) get_option('ias_push_queue', []);
      if (!$q) return 0;
      ias_push_run_locked(['deleted' => 0, 'published' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => [], 'readback' => null], array_map('intval', array_keys($q)), false);
      // Taken off the queue only now, and only what was sent: a move made meanwhile stays queued.
      wp_cache_delete('ias_push_queue', 'options');
      $now = (array) get_option('ias_push_queue', []);
      foreach ($q as $id => $at) if (isset($now[$id]) && $now[$id] === $at) unset($now[$id]);
      if ($now) update_option('ias_push_queue', $now, false); else delete_option('ias_push_queue');
      return count($q);
    } finally {
      ias_push_unlock();
    }
  }
}

/** The post actions whose change goes to OneUp at once (approve sends its own run). */
if (!function_exists('ias_push_now_actions')) {
  function ias_push_now_actions(): array {
    // Move and a drag are not here: they go to OneUp after the page has answered (ias_push_later(), 0.20.0).
    return (array) apply_filters('ias_push_now_actions', ['edit', 'media', 'media_reset', 'regenerate', 'skip', 'restore', 'release', 'unpin', 'retry', 'unlock', 'gbp_off', 'gbp_on', 'gbp_unlock', 'publish_now']);
  }
}

/**
 * Send one post now and add what happened on OneUp to the page's notice:
 * scheduled, replaced, taken off, or why not — for Facebook and Google. With
 * posting off and nothing of it on OneUp there is nothing to send, and it
 * says nothing (the post's page says why it does not go).
 */
if (!function_exists('ias_push_now_flash')) {
  function ias_push_now_flash(int $id): void {
    $before = ias_post_get($id);
    if (!$before || $before['type'] === 'human') return;
    $live = ias_push_mode() === 'live' || ias_post_sent($before)['mode'] === 'live';
    $glive = function_exists('ias_gbp_live') && (ias_gbp_live() || !empty($before['flags']['gbp']));
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
    if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now))) { $GLOBALS['ias_push_lock_held'] = true; return true; }
    $held = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    if ((int) $held > $now - 600) return false;
    $taken = (bool) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $name, $held));
    if ($taken) $GLOBALS['ias_push_lock_held'] = true;
    return $taken;
  }
}
if (!function_exists('ias_push_unlock')) {
  function ias_push_unlock(): void {
    global $wpdb;
    unset($GLOBALS['ias_push_lock_held']);
    $wpdb->delete($wpdb->options, ['option_name' => 'ias_push_lock']);
    wp_cache_delete('ias_push_lock', 'options');
  }
}
/**
 * While this process holds the push lock, every OneUp call restamps it
 * (0.24.1): a read-back slowed by OneUp's timeouts can outlast the ten
 * minutes, and a second caller would otherwise take the lock mid-run and
 * send a post a second time.
 */
if (!function_exists('ias_push_lock_touch')) {
  function ias_push_lock_touch(): void {
    if (empty($GLOBALS['ias_push_lock_held'])) return;
    global $wpdb;
    $wpdb->update($wpdb->options, ['option_value' => (string) time()], ['option_name' => 'ias_push_lock']);
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
    // What a person did in OneUp since the last run comes first: their delete or edit is never overwritten.
    unset($GLOBALS['ias_readback_lists']); // this run's lists only
    if (function_exists('ias_readback_run') && !ias_oneup_blockers(false)) $out['readback'] = ias_readback_run($full);
    // Google's own read-back, on the same lists (gbp.php): its posts a person changed in OneUp first, too.
    if (function_exists('ias_gbp_readback')) {
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
      : ias_posts_query(['status' => $statuses, 'limit' => 2000]); // soonest first
    foreach ($posts as $p) {
      if (ias_push_out_of_time()) { $out['skipped'][] = 'out of time: the rest follow on the next run'; break; }
      ias_push_one($p, $mode, $c, $out);
    }
    if (function_exists('ias_gbp_run')) { // Google, by its own switch (gbp.php)
      $g = ias_gbp_run($only);
      foreach (['pushed', 'deleted', 'failed'] as $k) $out[$k] += $g[$k];
      $out['skipped'] = array_merge($out['skipped'], $g['skipped']);
    }
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

    // A person's own post not for Facebook (0.25.0): nothing of it is Facebook's — only its time coming is noted here.
    if ($p['type'] === 'manual' && $sent['mode'] !== 'live' && !in_array('facebook', ias_post_networks($p), true)) {
      if ($at !== '' && $at <= $nowsql && in_array($p['status'], ['planned', 'approved'], true)) {
        if (!empty($flags['gbp']['id'])) {
          ias_post_update((int) $p['id'], ['status' => 'published', 'published_at' => $at, 'note' => '']);
          ias_post_trail((int) $p['id'], 'Its time came on OneUp (Google)');
          $out['published']++;
        } elseif (!in_array('gbp', ias_post_networks($p), true)) {
          $flags['cancelled_by'] = 'system';
          $why = 'Its time passed — none of the networks ticked for it posts yet, so nothing went out.';
          ias_post_update((int) $p['id'], ['status' => 'cancelled', 'flags' => $flags, 'note' => $why]);
          ias_post_trail((int) $p['id'], $why);
        }
      }
      return;
    }

    // Its time came while it was on OneUp: it went out.
    if ($sent['mode'] === 'live' && $at !== '' && $at <= $nowsql && in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'pushed'], true)) {
      $flags['unconfirmed'] = true; // the read-back confirms it (or reports it failed)
      ias_post_update((int) $p['id'], ['status' => 'published', 'flags' => $flags, 'published_at' => $at, 'note' => '']);
      ias_post_trail((int) $p['id'], 'Its time came on OneUp');
      if (function_exists('ias_voice_record')) ias_voice_record((int) $p['id'], 'out'); // the log of final posts
      $out['published']++;
      return;
    }

    $in_range = $at !== '' && $at >= $nowsql && $at <= $until;
    $why_not = ias_push_ready($p);
    // A person who moved it there (Move on its page) has already said yes: the veto window is theirs.
    if ($why_not === '' && $sent['hash'] === '' && empty($flags['was_on_oneup']) && empty($flags['pinned_by']) && $p['lane'] !== 'A' && $at !== '' && $at < $floor_b) $why_not = 'its time is inside the 48-hour veto window';

    if ($in_range && $why_not === '') {
      $blockers = ias_push_blockers($p);
      if ($blockers) { // nothing new goes — and off means off: with a switch off, a copy already on OneUp comes back off until it is on again
        $out['skipped'][] = '#' . (int) $p['id'] . ': ' . implode('; ', $blockers);
        $off = ias_push_switched_off($p);
        if ($off && $sent['mode'] === 'live' && empty($p['locked']) && !ias_oneup_takeback_blockers() && ias_push_live_delete($p, implode(' and ', $off) . ' — it goes again when that changes')) {
          $flags = (ias_post_get((int) $p['id']) ?: $p)['flags'];
          $flags['was_on_oneup'] = true;
          ias_post_update((int) $p['id'], ['flags' => $flags]);
          $out['deleted']++;
        }
        return;
      }
      $req = ias_render_facebook($p);
      $hash = md5((string) wp_json_encode($req));
      if ($hash === $sent['hash'] || !empty($p['locked'])) return; // unchanged, or a person edited it in OneUp
      if (ias_push_live_send($p, $req, $hash)) $out['pushed']++; else $out['failed']++;
      return;
    }
    // It is on OneUp and no longer ready, or left the next push_days days: take it back.
    if ($sent['mode'] === 'live') {
      if (!empty($p['locked']) && !in_array($p['status'], ['skipped', 'cancelled'], true)) return; // a person's edit in OneUp stays unless the post is called off
      $why = $why_not !== '' ? $why_not : 'moved past the next ' . (int) ias_setting('push_days', 21) . ' days';
      if (!ias_oneup_takeback_blockers() && ias_push_live_delete($p, $why)) $out['deleted']++;
    }
  }
}

/**
 * The time a push run may still take, for a run started from a page (a
 * switch changed, Pause): it stops after `ias_push_deadline` and the hourly
 * run does the rest. The posts go soonest first, so what is due first is
 * done first. No deadline set: never out of time.
 */
if (!function_exists('ias_push_out_of_time')) {
  function ias_push_out_of_time(): bool {
    $d = (float) ($GLOBALS['ias_push_deadline'] ?? 0);
    return $d > 0 && microtime(true) > $d;
  }
}

/**
 * After a switch changed (Settings, Pause, Resume): bring OneUp in line at
 * once, soonest first, for up to $seconds — a post switched off comes back
 * off OneUp before the page has finished, and one switched on goes now
 * rather than within the hour. What does not fit follows on the hourly run.
 * Returns the run's counts (['busy' => true] when another run held the lock).
 */
if (!function_exists('ias_push_switched')) {
  function ias_push_switched(int $seconds = 20): array {
    $out = ['deleted' => 0, 'published' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => [], 'readback' => null];
    if (!ias_db_ready()) return $out;
    if (!ias_push_lock()) return $out + ['busy' => true];
    $GLOBALS['ias_push_deadline'] = microtime(true) + max(1, $seconds);
    try {
      return ias_push_run_locked($out, [], false);
    } finally {
      unset($GLOBALS['ias_push_deadline']);
      ias_push_unlock();
    }
  }
}

/** The words for a page's notice after ias_push_switched(): '' when nothing happened on OneUp. */
if (!function_exists('ias_push_switched_words')) {
  function ias_push_switched_words(array $r): string {
    if (!empty($r['busy'])) return 'Another run is sending to OneUp right now — this change follows within the hour.';
    $bits = [];
    if ($r['deleted']) $bits[] = $r['deleted'] . ' taken back off OneUp';
    if ($r['pushed']) $bits[] = $r['pushed'] . ' scheduled on OneUp';
    if ($r['failed']) $bits[] = $r['failed'] . ' OneUp did not take (tried again on the next run)';
    $late = in_array('out of time: the rest follow on the next run', (array) $r['skipped'], true);
    if (!$bits && !$late) return '';
    return ($bits ? ucfirst(implode(', ', $bits)) . '.' : '') . ($late ? ' The rest follow within the hour.' : '');
  }
}


/* ---------------- The hourly job's later steps ---------------- */

add_filter('ias_job_run', function (array $result): array {
  $waited = !ias_plan_due() && ias_plan_hold_ready(); // a person's moves, done with: the plan tidies around them now
  if (ias_plan_due() || $waited) {
    $result['plan'] = ias_plan_run();
    if ($waited) $result['parts'][] = 'planned around the posts a person moved';
    $result['parts'][] = 'planned: ' . (int) $result['plan']['placed'] . ' placed'
      . (!empty($result['plan']['moved']) ? ', ' . (int) $result['plan']['moved'] . ' moved' : '')
      . (!empty($result['plan']['dropped']) ? ', ' . (int) $result['plan']['dropped'] . ' dropped' : '');
  }
  $push = ias_push_run();
  ias_push_queue_run(0); // anything a person moved that has not reached OneUp yet
  $result['push'] = $push;
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
