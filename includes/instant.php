<?php
/**
 * Intel Auctions Social — OneUp in the background (0.10.0).
 *
 * DJ, Oct 13, 2026: "can we make sure that Jacob and the intel team never
 * needs to touch OneUp? I would love a clear 2 way communication between
 * oneup and the plugin. Instant update both directions."
 *
 *   To OneUp at once   every change a person makes to a post — save, rewrite,
 *                      skip, restore, keep as written, move, try again, Google
 *                      on or off — goes to OneUp in the same request
 *                      (ias_push_now(), plan.php), not on the next hourly run.
 *   From OneUp live    OneUp sends nothing out (no webhooks), so the plugin
 *                      asks: every minute while anyone has the calendar or a
 *                      post open (ias_oneup_pulse(), shared by everyone looking
 *                      — never more than once a minute), right before every
 *                      change it sends, and hourly in the background. The
 *                      screen says when it last heard from OneUp and what
 *                      changed, and offers to show it.
 *   Publish now        a post whose time passed without it going out goes
 *                      in a few minutes, from its own page (0.12.0).
 *   Never into OneUp   everything that used to be done there is done here:
 *                      **Move** a post to another day and time (it holds — the
 *                      plan works around it, `flags.pinned`), **Try again** on a
 *                      post OneUp could not publish, **Edit it here again** on a
 *                      post someone changed in OneUp (their version becomes the
 *                      post's, editable here), and for Google, **Use this
 *                      version on Google** after a change made there.
 */

if (!defined('ABSPATH')) exit;

/* ---------------- Move, Try again, Edit it here again ---------------- */

/**
 * Is $when (site time 'Y-m-d H:i') a time a person may move a post to?
 * '' = yes, else why not. Pure apart from the clock and settings.
 */
if (!function_exists('ias_move_problem')) {
  function ias_move_problem(string $when): string {
    try { $t = new DateTimeImmutable($when, ia_wp_tz()); } catch (\Exception $e) { return 'That is not a day and time.'; }
    if ($t->format('Y-m-d H:i') !== $when) return 'That is not a day and time.';
    if ($t->format('N') === '7') return 'Never on Sunday.';
    if ($t < ias_now()->modify('+15 minutes')) return 'Pick a time at least 15 minutes from now.';
    if ($t > ias_now()->modify('+' . max(30, (int) ias_setting('plan_days', 30)) . ' days')) return 'Pick a time within the next ' . max(30, (int) ias_setting('plan_days', 30)) . ' days.';
    return '';
  }
}

/** The posts already on a day (planned or out), for a move's warning. */
if (!function_exists('ias_move_day_count')) {
  function ias_move_day_count(string $day, int $except): int {
    $n = 0;
    foreach (ias_posts_query(['from' => $day, 'to' => $day, 'limit' => 50]) as $o) {
      if ((int) $o['id'] === $except || empty($o['scheduled_at']) || substr((string) $o['scheduled_at'], 0, 10) !== $day) continue;
      if (in_array($o['status'], array_merge(ias_live_statuses(), ['published', 'human']), true)) $n++;
    }
    return $n;
  }
}

/** Can a person move this post (Move on its page, or drag it on the calendar)? */
if (!function_exists('ias_movable')) {
  function ias_movable(array $p): bool {
    return $p['type'] !== 'human' && in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'pushed', 'dropped'], true) && empty($p['locked']);
  }
}

/** The posting windows, named (ias_slot_names()), from Settings. */
if (!function_exists('ias_windows')) {
  function ias_windows(): array {
    return ias_slot_names((array) ias_setting('slots', []));
  }
}

/**
 * The posts on $day that stay where they are whatever happens — published,
 * a person's own, changed in OneUp, put there by a person, or on OneUp inside
 * the veto window: [[minute, 'Listing · 12 Alpha Road'], …]. A post moved
 * into a window keeps clear of these; the plan moves the rest out of its way.
 */
if (!function_exists('ias_move_fixed')) {
  function ias_move_fixed(string $day, int $except): array {
    $floor = ias_now()->modify('+' . (int) ias_setting('lead_hours', 48) . ' hours')->format('Y-m-d H:i:s');
    $out = [];
    foreach (ias_posts_query(['from' => $day, 'to' => $day, 'limit' => 50]) as $o) {
      $at = (string) ($o['scheduled_at'] ?? '');
      if ((int) $o['id'] === $except || $at === '' || substr($at, 0, 10) !== $day) continue;
      if (!in_array($o['status'], array_merge(ias_live_statuses(), ['published', 'human']), true)) continue;
      $pin = (string) ($o['flags']['pinned'] ?? '');
      $fixed = in_array($o['status'], ['published', 'human'], true) || !empty($o['locked']) || ($pin !== '' && substr($pin, 0, 10) === $day)
        || (ias_post_sent($o)['mode'] === 'live' && $at < $floor);
      if ($fixed) $out[] = [ias_hm_to_min(substr($at, 11, 5)), ias_post_type((string) $o['type'])['short'] . ' · ' . ias_post_subject($o)];
    }
    return $out;
  }
}

/**
 * Move a post into a posting window on a day (DJ, Oct 14, 2026: "drag posts
 * around on the calendar and choose the morning, afternoon, or evening time
 * slot"): its own shifted minute in that window, clear of the posts that stay
 * where they are, pinned there like a Move; then the plan runs at once, so
 * the posts it displaced find new times, and their copies on OneUp follow.
 * Returns ['ok' => bool, 'message' => html, 'when' => 'Y-m-d H:i', 'level'].
 */
if (!function_exists('ias_move_to_window')) {
  function ias_move_to_window(array $p, string $day, int $si, string $actor): array {
    $no = function (string $why) { return ['ok' => false, 'message' => 'Not moved: ' . esc_html($why), 'when' => '', 'level' => 'error']; };
    if (!ias_movable($p)) return $no('this post cannot be moved now.');
    $w = ias_windows()[$si] ?? null;
    if (!$w) return $no('there is no such time of day.');
    try { $d = new DateTimeImmutable($day, ia_wp_tz()); } catch (\Exception $e) { return $no('that is not a day.'); }
    if ($d->format('Y-m-d') !== $day) return $no('that is not a day.');
    if ($d->format('N') === '7') return $no('never on Sunday.');
    $now = ias_now();
    $soon = $now->modify('+15 minutes');
    $not_before = $day === $soon->format('Y-m-d') ? ias_hm_to_min($soon->format('H:i')) : ($day < $soon->format('Y-m-d') ? 24 * 60 : 0);
    $fixed = ias_move_fixed($day, (int) $p['id']);
    $m = ias_slot_minute($w['a'], $w['b'], (string) $p['slot_key'], (int) ias_setting('jitter_minutes', 25), array_column($fixed, 0), 60 * (int) ias_setting('min_gap_hours', 3), $not_before);
    $label = strtolower($w['name']);
    if ($m === null) {
      if ($not_before > $w['b']) return $no('the ' . $label . ' has passed ' . ($day === $now->format('Y-m-d') ? 'for today' : 'that day') . ' — choose a later time.');
      $near = '';
      foreach ($fixed as $f) {
        if (abs((int) $f[0] - $w['a']) < 60 * (int) ias_setting('min_gap_hours', 3) + ($w['b'] - $w['a'])) {
          $near = (string) $f[1];
          break;
        }
      }
      return $no('no room in the ' . $label . ' on ' . date_i18n('D M j', strtotime($day)) . ($near !== '' ? ': ' . $near . ' is held there' : '') . ', and posts are kept ' . (int) ias_setting('min_gap_hours', 3) . ' hours apart. Choose another time of day.');
    }
    $when = $day . ' ' . sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    $why = ias_move_problem($when);
    if ($why !== '') return $no($why);

    $id = (int) $p['id'];
    $was = (string) ($p['scheduled_at'] ?? '');
    $flags = (array) $p['flags'];
    $flags['pinned'] = $when;
    $flags['pinned_by'] = $actor;
    unset($flags['plan_note'], $flags['late'], $flags['relaxed']);
    $fields = ['scheduled_at' => $when . ':00', 'flags' => $flags];
    if ($p['status'] === 'dropped') {
      unset($fields['flags']['dropped_by']);
      $fields['status'] = ias_back_on_plan_status(['flags' => $fields['flags']]);
      $fields['note'] = $fields['status'] === 'held' ? 'Needs a decision: ' . (string) ($fields['flags']['holds'][0] ?? '') : '';
    } elseif ((string) $p['note'] === (string) ($p['flags']['plan_note'] ?? '-')) {
      $fields['note'] = '';
    }
    ias_post_update($id, $fields);
    $fmt = function (string $at) { return date_i18n('D M j, g:i a', strtotime($at)); };
    $words = 'Moved' . ($was !== '' ? ' from ' . $fmt($was) : '') . ' to ' . $fmt($when) . ' (' . $label . ') by ' . ias_actor_name($actor);
    ias_post_trail($id, $words, $actor);
    ias_log('moved', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => $words], $actor);

    // Only this post moves (0.20.0): the plan waits until the person is done, and OneUp has it once the page has answered.
    ias_plan_hold($actor);
    if (function_exists('ias_push_later')) ias_push_later($id);
    $n = ias_move_day_count($day, $id);
    $cap = (int) ias_setting('daily_cap', 3);
    $msg = 'Moved to <strong>' . esc_html(date_i18n('D M j', strtotime($day))) . ', ' . esc_html($label) . '</strong> (' . esc_html(date_i18n('g:i a', strtotime($when))) . '). It stays there' . ias_move_after_words()
      . ($n >= $cap ? ' <strong>That day now has ' . ($n + 1) . ' posts</strong> (the plan aims for ' . $cap . '): the plan moves one of the others when it tidies up.' : '');
    return ['ok' => true, 'message' => $msg, 'when' => $when, 'level' => $n >= $cap ? 'warning' : 'success'];
  }
}

/** What happens after a move, for its notice: OneUp follows, and the plan waits. */
if (!function_exists('ias_move_after_words')) {
  function ias_move_after_words(): string {
    $live = ias_push_mode() === 'live' ? '; OneUp has it in a moment' : '';
    return $live . '. Nothing else moves while you work: the plan tidies around your changes ' . IAS_PLAN_QUIET . ' minutes after your last move (on the next hourly run), or press <strong>Plan now</strong> when you are done.';
  }
}

/**
 * Run the plan now and send OneUp every other post it changed — moved, dropped
 * (no room left in its window) or brought back (room opened) — in this same
 * request, so OneUp follows at once (DJ, Oct 13, 2026: instant both ways).
 * The post a person acted on ($id) is the caller's to send. Returns ['moved',
 * 'dropped', 'reinstated'] (posts as they are now).
 */
if (!function_exists('ias_plan_run_follow')) {
  function ias_plan_run_follow(int $id, string $actor): array {
    $out = ['moved' => [], 'dropped' => [], 'reinstated' => []];
    if (!function_exists('ias_plan_run')) return $out;
    $statuses = array_merge(ias_live_statuses(), ['dropped']);
    $snap = function () use ($statuses, $id) {
      $rows = [];
      foreach (ias_posts_query(['status' => $statuses, 'limit' => 2000]) as $o) if ((int) $o['id'] !== $id) $rows[(int) $o['id']] = $o;
      return $rows;
    };
    $before = $snap();
    ias_plan_run($actor);
    $after = $snap();
    $live = ias_live_statuses();
    foreach ($before as $oid => $b) {
      $o = $after[$oid] ?? null;
      if (!$o) continue; // cancelled or skipped meanwhile: its own path takes it off
      $was = in_array($b['status'], $live, true);
      $is = in_array($o['status'], $live, true);
      if ($was && $is && (string) ($b['scheduled_at'] ?? '') !== (string) ($o['scheduled_at'] ?? '')) $out['moved'][] = $o;
      elseif ($was && $o['status'] === 'dropped') $out['dropped'][] = $o;
      elseif ($b['status'] === 'dropped' && $is) $out['reinstated'][] = $o;
    }
    $follow = array_map(function ($o) { return (int) $o['id']; }, array_merge($out['moved'], $out['dropped'], $out['reinstated']));
    if ($follow && function_exists('ias_push_now')) ias_push_now($follow);
    return $out;
  }
}

/** Move… on a post's page: a day and a time, and back to the plan's choice. */
if (!function_exists('ias_move_form')) {
  function ias_move_form(array $p): string {
    if (!ias_movable($p)) return '';
    $at = !empty($p['scheduled_at']) ? strtotime((string) $p['scheduled_at']) : 0;
    $pinned = (string) ($p['flags']['pinned'] ?? '');
    $now_si = $at ? ias_slot_index(ias_windows(), ias_hm_to_min(date('H:i', $at))) : -1;
    $radios = '';
    foreach (ias_windows() as $i => $w) {
      $radios .= '<label class="ias-move-slot"><input type="radio" name="ias_slot" value="' . (int) $i . '"' . checked($i === $now_si || ($now_si < 0 && $i === 0), true, false) . '> <strong>' . esc_html($w['name']) . '</strong> <span class="description">' . esc_html($w['from'] . '–' . $w['to']) . '</span></label>';
    }
    $radios .= '<label class="ias-move-slot"><input type="radio" name="ias_slot" value="exact"' . checked($now_si < 0 && !ias_windows(), true, false) . '> At <input type="time" name="ias_time" value="' . esc_attr($at ? date('H:i', $at) : '') . '" step="60" onfocus="var r=this.form.querySelector(\'input[name=ias_slot][value=exact]\');if(r)r.checked=true"></label>';
    $html = '<details class="ias-move"><summary>Move…</summary>'
      . ias_post_action_form($p, 'move', 'Move it', 'button',
        '<label>Day <input type="date" name="ias_day" value="' . esc_attr($at ? date('Y-m-d', $at) : '') . '" min="' . esc_attr(ias_now()->format('Y-m-d')) . '" required></label> '
        . '<fieldset class="ias-move-slots"><legend class="screen-reader-text">Time of day</legend>' . $radios . '</fieldset> ')
      . '<p class="description">It goes out then — on Facebook' . (function_exists('ias_gbp_live') && ias_channel_settings('gbp')['on'] ? ' (and on Google a minute later)' : '') . ' — and stays there: the plan works around it, moving what was in the way. A time of day picks the post\'s own minute in that window. Not on a Sunday. On the calendar you can also drag it to another day.</p></details>';
    if ($pinned !== '') $html .= ias_post_action_form($p, 'unpin', 'Let the plan choose the time', 'button-link', '', 'Let the morning plan choose this post\'s time again? It is re-planned now.');
    return $html;
  }
}

add_action('ias_calendar_action', function (string $action): void {
  if (!in_array($action, ['move', 'move_slot', 'unpin', 'retry', 'unlock', 'gbp_unlock'], true)) return;
  $id = isset($_POST['ias_post']) ? (int) $_POST['ias_post'] : 0;
  $p = $id > 0 ? ias_post_get($id) : null;
  if (!$p || $p['type'] === 'human') return;
  if (($_POST['ias_from'] ?? '') !== 'calendar' || empty($_POST['ias_back'])) $_POST['ias_back'] = ias_admin_url(IAS_PAGE_CALENDAR, ['post' => $id]); // a drag on the calendar comes back to the calendar
  $actor = ias_actor();
  $flags = $p['flags'];
  $flash = function (string $html, string $level = 'success') { ia_flash_set('<div class="notice notice-' . $level . '"><p>' . $html . '</p></div>'); };
  $fmt = function (string $at) { return date_i18n('D M j, g:i a', strtotime($at)); };

  // A time of day (the Move form's windows, or a drop on the calendar): its own minute in that window.
  $slot = isset($_POST['ias_slot']) ? sanitize_key(wp_unslash((string) $_POST['ias_slot'])) : '';
  if ($action === 'move_slot' || ($action === 'move' && $slot !== '' && $slot !== 'exact')) {
    if (!ctype_digit($slot)) { $flash('Not moved: choose the morning, afternoon or evening.', 'error'); return; }
    $r = ias_move_to_window($p, sanitize_text_field((string) wp_unslash($_POST['ias_day'] ?? '')), (int) $slot, $actor);
    $flash($r['message'], $r['level']);
    return;
  }

  switch ($action) {
    case 'move':
      if (!ias_movable($p)) { $flash('This post cannot be moved now.', 'error'); return; }
      $when = sanitize_text_field((string) wp_unslash($_POST['ias_day'] ?? '')) . ' ' . substr(sanitize_text_field((string) wp_unslash($_POST['ias_time'] ?? '')), 0, 5);
      $why = ias_move_problem($when);
      if ($why !== '') { $flash('Not moved: ' . esc_html($why), 'error'); return; }
      $was = (string) ($p['scheduled_at'] ?? '');
      $flags['pinned'] = $when;
      $flags['pinned_by'] = $actor;
      unset($flags['plan_note'], $flags['late'], $flags['relaxed']);
      $fields = ['scheduled_at' => $when . ':00', 'flags' => $flags];
      if ($p['status'] === 'dropped') {
        unset($fields['flags']['dropped_by']);
        $fields['status'] = ias_back_on_plan_status(['flags' => $fields['flags']]);
        $fields['note'] = $fields['status'] === 'held' ? 'Needs a decision: ' . (string) ($fields['flags']['holds'][0] ?? '') : '';
      } elseif ((string) $p['note'] === (string) ($p['flags']['plan_note'] ?? '-')) {
        $fields['note'] = '';
      }
      ias_post_update($id, $fields);
      $words = 'Moved' . ($was !== '' ? ' from ' . $fmt($was) : '') . ' to ' . $fmt($when) . ' by ' . ias_actor_name($actor);
      ias_post_trail($id, $words, $actor);
      ias_log('moved', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => $words], $actor);
      ias_plan_hold($actor);
      ias_push_later($id);
      $n = ias_move_day_count(substr($when, 0, 10), $id);
      $cap = (int) ias_setting('daily_cap', 3);
      $flash('Moved to <strong>' . esc_html($fmt($when)) . '</strong>. It stays there' . ias_move_after_words()
        . ($n >= $cap ? ' <strong>That day now has ' . ($n + 1) . ' posts</strong> (the plan aims for ' . $cap . '): the plan moves one of the others when it tidies up.' : ''), $n >= $cap ? 'warning' : 'success');
      return;

    case 'unpin':
      if (empty($flags['pinned'])) return;
      unset($flags['pinned'], $flags['pinned_by']);
      ias_post_update($id, ['flags' => $flags]);
      ias_post_trail($id, 'The plan chooses its time again', $actor);
      $was = (string) ($p['scheduled_at'] ?? '');
      ias_plan_run_follow($id, $actor);
      $p = ias_post_get($id) ?: $p;
      $at = (string) ($p['scheduled_at'] ?? '');
      $kept = $at !== '' && $at === $was && ias_post_sent($p)['mode'] !== '' && $at < ias_now()->modify('+' . (int) ias_setting('lead_hours', 48) . ' hours')->format('Y-m-d H:i:s');
      $flash($kept ? 'The plan chooses its time again from now on. It stays at <strong>' . esc_html($fmt($at)) . '</strong> for now: it is on OneUp and inside the ' . (int) ias_setting('lead_hours', 48) . '-hour window, where nothing is moved.'
        : 'The plan chooses its time again' . ($at !== '' ? ': <strong>' . esc_html($fmt($at)) . '</strong>' : '') . '.');
      return;

    case 'retry':
      if ($p['status'] !== 'failed') return;
      // OneUp's failed copy is rescheduled for the new time when the post is as it was (0.19.0, ias_push_live_send());
      // otherwise it stays on OneUp's failed list, by its id, and a fresh copy goes instead.
      if (!empty($flags['oneup']['id'])) {
        $flags['failed_copies'][] = (string) $flags['oneup']['id'];
        if (!empty($flags['oneup']['body'])) $flags['reuse_failed'] = ['id' => (string) $flags['oneup']['id'], 'body' => (string) $flags['oneup']['body'], 'at' => ias_now_sql()];
      }
      unset($flags['oneup'], $flags['push_fails'], $flags['push_error'], $flags['unconfirmed'], $flags['missing_since']);
      $flags['was_on_oneup'] = true; // it was sent once: a new copy is not held back by the veto window
      $status = ias_back_on_plan_status(['flags' => $flags]);
      $fields = ['status' => $status, 'flags' => $flags, 'oneup_post_id' => '', 'note' => $status === 'held' ? 'Needs a decision: ' . (string) ($flags['holds'][0] ?? '') : ''];
      $soon = ias_now()->modify('+15 minutes')->format('Y-m-d H:i:s');
      if (empty($p['scheduled_at']) || (string) $p['scheduled_at'] < $soon) {
        $fields['scheduled_at'] = null;
        unset($fields['flags']['pinned'], $fields['flags']['pinned_by']);
      }
      ias_post_update($id, $fields);
      ias_post_trail($id, 'Try again pressed — ' . (!empty($flags['reuse_failed']) ? 'OneUp\'s failed copy goes again at its new time' : 'a fresh copy goes to OneUp'), $actor);
      ias_log('planned', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Try again: back on the plan'], $actor);
      if (!array_key_exists('scheduled_at', $fields) || $fields['scheduled_at'] !== null) {
        $flash('Back on the plan for ' . esc_html($fmt((string) $p['scheduled_at'])) . '.');
      } else {
        ias_plan_run_follow($id, $actor);
        $p = ias_post_get($id) ?: $p;
        $flash('Back on the plan' . (!empty($p['scheduled_at']) ? ' for <strong>' . esc_html($fmt((string) $p['scheduled_at'])) . '</strong> (use Move for another time)' : '') . '.');
      }
      return;

    case 'unlock':
      if (empty($p['locked'])) return;
      // Their version, as OneUp has it now, becomes the post's: its words a person's edit, its time held.
      $o = (array) ($flags['oneup'] ?? []);
      $c = (array) $p['content'];
      if (!empty($o['text'])) {
        if (empty($c['claude_text'])) $c['claude_text'] = (string) ($c['text'] ?? '');
        $c['text'] = (string) $o['text'];
        $c['opener'] = ias_first_line($c['text']);
        $c['generated_by'] = 'edited';
        $flags['edited_by'] = $actor;
      }
      if (!empty($o['when'])) { $flags['pinned'] = substr((string) $o['when'], 0, 16); $flags['pinned_by'] = $actor; }
      unset($flags['edited_in_oneup']);
      $after = array_merge($p, ['content' => $c, 'flags' => $flags]);
      if (!empty($flags['oneup'])) $flags['oneup']['hash'] = md5((string) wp_json_encode(ias_render_facebook($after))); // OneUp has it as it is now: nothing to replace
      ias_post_update($id, ['content' => $c, 'flags' => $flags, 'locked' => 0, 'note' => '', 'facts_hash' => ias_facts_hash($after, ias_post_facts($after))]);
      if (!empty($o['text']) && function_exists('ias_voice_record')) ias_voice_record($id, 'kept'); // a person's words: the brand-voice log keeps them
      ias_post_trail($id, 'Edit it here again: the version changed in OneUp is kept, and edited here from now on', $actor);
      ias_log('edited', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Taken back from OneUp: its version there kept, edited here from now on'], $actor);
      $flash('This post is edited here again, starting from the version in OneUp. Changes you save go to OneUp at once.');
      return;

    case 'gbp_unlock':
      if (empty($flags['gbp_locked'])) return;
      unset($flags['gbp_locked']);
      ias_post_update($id, ['flags' => $flags]);
      ias_post_trail($id, 'Google: the plugin\'s version goes to Google again', $actor);
      $flash('Google gets the plugin\'s version of this post again.');
      return;
  }
});

/* ---------------- Publish now ---------------- */

/**
 * Publish now, for a post whose time has passed without it going out (DJ,
 * Oct 14, 2026: "I also want a "Publish now" button if a post is past it's
 * scheduled date"): a post the plan had at a time that is gone — posting was
 * off, it waited for a yes, or its time came in the old dry run (Not sent).
 * Returns null when the button does not belong on this post, else ['at' =>
 * its old time, 'why_not' => '' (it can go) or why it cannot go now].
 */
if (!function_exists('ias_publish_now_state')) {
  function ias_publish_now_state(array $p): ?array {
    if ($p['type'] === 'human' || !empty($p['locked']) || ias_post_sent($p)['mode'] !== '') return null;
    $legacy = $p['status'] === 'published' && !empty($p['flags']['dry_run']); // Not sent: its time came in the old dry run
    if (!$legacy && !in_array($p['status'], ['planned', 'approved', 'waiting', 'held'], true)) return null;
    $at = (string) ($p['scheduled_at'] ?? '');
    if ($at === '' || $at >= ias_now_sql()) return null;
    $c = (array) $p['content'];
    $why = '';
    if (empty($c['text'])) $why = 'it has no draft yet';
    elseif (ias_has_verify($c)) $why = 'it still has a [VERIFY] marker — put the right fact in first';
    elseif ($p['status'] === 'held' || ias_post_needs_decision($p)) $why = 'it needs a decision first';
    elseif (!empty($p['flags']['stale'])) $why = 'the listing changed since it was written — save or rewrite it first';
    elseif (ias_now()->format('N') === '7') $why = 'never on a Sunday — use Move for Monday';
    else {
      $block = ias_push_blockers($p);
      if ($block) $why = implode('; ', $block);
    }
    return ['at' => $at, 'why_not' => $why];
  }
}

/** The Publish now button (or why it cannot go now), for the post's bar. */
if (!function_exists('ias_publish_now_button')) {
  function ias_publish_now_button(array $p, array $state): string {
    if ($state['why_not'] !== '') return '<span class="description ias-pub-why">Publish now: not while ' . esc_html($state['why_not']) . '.</span>';
    $google = function_exists('ias_gbp_live') && ias_gbp_live() && ias_gbp_ready($p) === '';
    $confirm = 'Publish this post now? It goes to Facebook' . ($google ? ' (and Google a minute later)' : '') . ' in about ' . IAS_PUBLISH_NOW_MINUTES . ' minutes.' . ($p['lane'] === 'A' && $p['status'] !== 'approved' ? ' Publishing it is your approval.' : '');
    return ias_post_action_form($p, 'publish_now', 'Publish now', 'button button-primary', '', $confirm);
  }
}

if (!defined('IAS_PUBLISH_NOW_MINUTES')) define('IAS_PUBLISH_NOW_MINUTES', 3); // OneUp gets a time a few minutes ahead: "now" with room for the request

add_action('ias_calendar_action', function (string $action): void {
  if ($action !== 'publish_now') return;
  $id = isset($_POST['ias_post']) ? (int) $_POST['ias_post'] : 0;
  $p = $id > 0 ? ias_post_get($id) : null;
  if (!$p) return;
  $_POST['ias_back'] = ias_admin_url(IAS_PAGE_CALENDAR, ['post' => $id]);
  $state = ias_publish_now_state($p);
  if (!$state || $state['why_not'] !== '') {
    ia_flash_set('<div class="notice notice-error"><p>Not published: ' . esc_html($state ? $state['why_not'] : 'this post is not past its time, or it is already on OneUp') . '.</p></div>');
    return;
  }
  $actor = ias_actor();
  $when = ias_now()->modify('+' . IAS_PUBLISH_NOW_MINUTES . ' minutes')->format('Y-m-d H:i');
  $flags = (array) $p['flags'];
  $flags['pinned'] = $when; // a person's time: the plan works around it, and the veto window is theirs
  $flags['pinned_by'] = $actor;
  unset($flags['plan_note'], $flags['late'], $flags['relaxed'], $flags['dry_run'], $flags['unconfirmed']);
  $fields = ['scheduled_at' => $when . ':00', 'flags' => $flags, 'note' => ''];
  if ($p['status'] === 'published') $fields['status'] = $p['lane'] === 'A' ? 'approved' : 'planned'; // Not sent (old dry run): back on its way
  if ($p['lane'] === 'A' && in_array($p['status'], ['planned', 'waiting'], true)) { // pressing it is the yes
    $a = (array) $p['approvals'];
    foreach ((array) ($a['tokens'] ?? []) as $i => $t) $a['tokens'][$i]['used'] = 1;
    $a['decision'] = ['by' => $actor, 'at' => ias_now_sql(), 'via' => 'Publish now'];
    $fields += ['status' => 'approved', 'approvals' => $a, 'approved_by' => mb_substr($actor, 0, 100), 'approved_at' => ias_now_sql()];
    ias_log('approved', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Approved by ' . ias_actor_name($actor) . ' (Publish now)'], $actor);
    if (function_exists('ias_voice_record')) ias_voice_record($id, 'kept');
  }
  ias_post_update($id, $fields);
  $words = 'Publish now pressed by ' . ias_actor_name($actor) . ' — its time (' . date_i18n('D M j, g:i a', strtotime($state['at'])) . ') had passed; it goes out at ' . date_i18n('g:i a', strtotime($when));
  ias_post_trail($id, $words, $actor);
  ias_log('moved', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => $words], $actor);
  ia_flash_set('<div class="notice notice-success"><p><strong>Publishing.</strong> It goes out at ' . esc_html(date_i18n('g:i a', strtotime($when))) . ' (about ' . IAS_PUBLISH_NOW_MINUTES . ' minutes).</p></div>');
}, 5);

/** Posts past their time that did not go out, in the last 7 days — on the calendar, so nobody hunts for them. */
add_action('ias_calendar_before_grid', function (): void {
  $since = ias_now()->modify('-7 days')->format('Y-m-d');
  $past = array_values(array_filter(ias_posts_query(['status' => ['planned', 'approved', 'waiting', 'held', 'published'], 'from' => $since, 'to' => ias_now()->format('Y-m-d'), 'limit' => 200]), function ($p) {
    return ias_publish_now_state($p) !== null;
  }));
  if (!$past) return;
  echo '<div class="notice notice-warning inline"><p><strong>' . count($past) . ' post' . (count($past) === 1 ? '' : 's') . ' passed ' . (count($past) === 1 ? 'its' : 'their') . ' time without going out</strong> in the last 7 days. Open one to <strong>Publish now</strong> — or leave it, and the morning plan finds it a new time if it still has a window: ';
  echo implode(', ', array_map(function ($p) { return '<a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '">' . esc_html(ias_post_type((string) $p['type'])['short'] . ' · ' . ias_post_subject($p)) . '</a>'; }, array_slice($past, 0, 10))) . (count($past) > 10 ? ' …' : '') . '</p></div>';
}, 6);

/**
 * Drag a post on the calendar (desktop): drop it on a day — or back on its own
 * — and choose the morning, afternoon or evening; the choice submits the
 * calendar's move form (ias_move_to_window()). Escape, Cancel or a click
 * elsewhere puts it back. On a phone the post's own Move does the same.
 */
add_action('admin_footer', function () {
  if (($_GET['page'] ?? '') !== IAS_PAGE_CALENDAR || isset($_GET['post']) || !current_user_can('manage_options')) return;
  ?>
<script>
jQuery(function ($) {
  var cfg = window.iasCal, $form = $('#ias-drop-form');
  if (!cfg || !$form.length || !cfg.windows.length) return;
  var drag = null, $pop = null;
  function close() { if ($pop) { $pop.remove(); $pop = null; } $('.ias-day').removeClass('ias-drop-ok ias-drop-target'); }
  function nice(day) { var d = new Date(day + 'T12:00:00'); return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }); }
  function open($day, x, y) {
    close();
    var day = String($day.data('day')), same = day === drag.day;
    $day.addClass('ias-drop-target');
    $pop = $('<div class="ias-drop-pop" role="dialog" aria-label="Choose a time of day"></div>');
    $pop.append($('<p class="ias-drop-q"></p>').append('Move <strong></strong> to <strong></strong>:'));
    $pop.find('strong').eq(0).text(drag.label);
    $pop.find('strong').eq(1).text(nice(day));
    var $row = $('<div class="ias-drop-slots"></div>');
    $.each(cfg.windows, function (i, w) {
      var past = day === cfg.today && w.b < cfg.soonMin, now = same && i === drag.slot;
      var $b = $('<button type="button" class="button"></button>').attr('data-slot', i)
        .append($('<strong></strong>').text(w.name)).append('<br>').append($('<span class="description"></span>').text(now ? 'where it is now' : (past ? 'passed for today' : w.span)));
      if (past || now) $b.prop('disabled', true);
      $row.append($b);
    });
    $pop.append($row).append('<p class="description">It stays there; the plan works around it.</p>')
      .append($('<button type="button" class="button-link ias-drop-cancel">Cancel</button>'));
    $('body').append($pop);
    var w = $pop.outerWidth(), h = $pop.outerHeight();
    $pop.css({ left: Math.max(8, Math.min(x - w / 2, $(window).width() - w - 8)) + 'px', top: Math.max(8 + $(window).scrollTop(), Math.min(y + 12, $(window).scrollTop() + $(window).height() - h - 8)) + 'px' });
    $pop.data('day', day).data('post', drag.id);
    $pop.find('.ias-drop-slots button:enabled').first().trigger('focus');
  }
  $('.ias-cal').on('dragstart', '.ias-chip.drag', function (e) {
    var $c = $(this);
    drag = { id: $c.data('post'), slot: parseInt($c.data('slot'), 10), label: String($c.data('label')), day: String($c.closest('.ias-day').data('day')) };
    var dt = e.originalEvent.dataTransfer;
    if (dt) { dt.effectAllowed = 'move'; try { dt.setData('text/plain', String(drag.id)); } catch (x) {} }
    $c.addClass('is-dragging');
    $('body').addClass('ias-dragging');
  }).on('dragend', '.ias-chip.drag', function () {
    $(this).removeClass('is-dragging');
    $('body').removeClass('ias-dragging');
    $('.ias-day').removeClass('ias-drop-ok');
  }).on('dragover', '.ias-day', function (e) {
    if (!drag || String($(this).data('drop')) !== '1') return;
    e.preventDefault();
    if (e.originalEvent.dataTransfer) e.originalEvent.dataTransfer.dropEffect = 'move';
    $(this).addClass('ias-drop-ok');
  }).on('dragleave', '.ias-day', function (e) {
    if (!$.contains(this, e.originalEvent.relatedTarget)) $(this).removeClass('ias-drop-ok');
  }).on('drop', '.ias-day', function (e) {
    if (!drag || String($(this).data('drop')) !== '1') return;
    e.preventDefault();
    open($(this), e.originalEvent.pageX, e.originalEvent.pageY);
  });
  $(document).on('click', '.ias-drop-slots button', function () {
    $form.find('[name=ias_post]').val($pop.data('post'));
    $form.find('[name=ias_day]').val($pop.data('day'));
    $form.find('[name=ias_slot]').val($(this).data('slot'));
    $pop.find('button').prop('disabled', true);
    $(this).text('Moving…');
    $form.trigger('submit');
  }).on('click', '.ias-drop-cancel', close).on('keydown', function (e) {
    if (e.key === 'Escape') close();
  }).on('mousedown', function (e) {
    if ($pop && !$(e.target).closest('.ias-drop-pop').length) close();
  });
});
</script>
  <?php
});

/**
 * Hover a post on the calendar for its preview (DJ, Oct 14, 2026): a card
 * built from the chip's own data (ias_calendar_preview()) — with text, never
 * HTML — beside the post, after a moment's rest. Not while dragging, not on a
 * device without hover (a phone opens the post instead); keyboard focus shows
 * it too.
 */
add_action('admin_footer', function () {
  if (($_GET['page'] ?? '') !== IAS_PAGE_CALENDAR || isset($_GET['post']) || !current_user_can('manage_options')) return;
  ?>
<script>
jQuery(function ($) {
  if (!window.matchMedia || !window.matchMedia('(hover: hover)').matches) return;
  var $pv = null, timer = null, at = null;
  function hide() { clearTimeout(timer); if ($pv) { $pv.remove(); $pv = null; } at = null; }
  function place() {
    if (!$pv || !at) return;
    var r = at.getBoundingClientRect(), w = $pv.outerWidth(), h = $pv.outerHeight(), vw = window.innerWidth, vh = window.innerHeight;
    var left = r.right + 10, top = r.top;
    if (left + w > vw - 8) left = r.left - w - 10;
    if (left < 8) { left = Math.max(8, Math.min(r.left, vw - w - 8)); top = r.bottom + 8; }
    if (top + h > vh - 8) top = Math.max(8, vh - h - 8);
    $pv.css({ left: left + 'px', top: top + 'px' });
  }
  function show(el) {
    var d = $(el).data('pv');
    if (!d || $('body').hasClass('ias-dragging') || $('.ias-drop-pop').length) return;
    hide();
    at = el;
    $pv = $('<div class="ias-pv" role="tooltip"></div>');
    $pv.append($('<div class="ias-pv-head"></div>').text(d.head));
    $pv.append($('<div class="ias-pv-meta"></div>').text([d.note, d.when].filter(Boolean).join(' · ')));
    if (d.img) {
      var $i = $('<div class="ias-pv-img"></div>').append($('<img alt="">').attr('src', d.img).on('load', place).on('error', function () { $(this).closest('.ias-pv-img').remove(); place(); }));
      if (d.more > 0) $i.append($('<span class="ias-pv-more"></span>').text('+' + d.more + (d.more === 1 ? ' picture' : ' pictures')));
      $pv.append($i);
    } else if (d.video) {
      $pv.append($('<div class="ias-pv-meta"></div>').text('A video'));
    }
    $pv.append($('<div class="ias-pv-text"></div>').toggleClass('empty', !!d.empty).text(d.text));
    if (d.comment) $pv.append($('<div class="ias-pv-comment"></div>').text('First comment: ' + d.comment));
    if (d.stats) $pv.append($('<div class="ias-pv-stats"></div>').text(d.stats));
    $pv.append($('<div class="ias-pv-hint"></div>').text(d.hint));
    $('body').append($pv);
    place();
  }
  $(document).on('mouseenter focusin', '.ias-chip[data-pv]', function () {
    var el = this;
    clearTimeout(timer);
    timer = setTimeout(function () { show(el); }, 250);
  }).on('mouseleave focusout dragstart mousedown', '.ias-chip[data-pv]', hide).on('keydown', function (e) {
    if (e.key === 'Escape') hide();
  });
  $(window).on('scroll resize', hide);
});
</script>
  <?php
});

/* ---------------- From OneUp, while anyone is looking ---------------- */

/**
 * Ask OneUp what changed — a full read-back, Facebook's and Google's — at
 * most once a minute however many people are looking ($force: Check now,
 * at most every 10 seconds). Returns ['state' => off|busy|ok|error, 'at' =>
 * 'Y-m-d H:i:s' of the last answer, 'changes' => [...words], 'seq' =>
 * a number that moves whenever a read found something].
 */
if (!function_exists('ias_oneup_pulse')) {
  function ias_oneup_pulse(bool $force = false): array {
    $last = get_option('ias_pulse', []);
    $last = is_array($last) ? $last + ['at' => '', 'ts' => 0, 'state' => '', 'changes' => [], 'seq' => 0, 'error' => ''] : ['at' => '', 'ts' => 0, 'state' => '', 'changes' => [], 'seq' => 0, 'error' => ''];
    $fb = ias_oneup_blockers(false);
    $g = function_exists('ias_gbp_blockers_read') ? ias_gbp_blockers_read() : ['off'];
    if ($fb && $g) return ['state' => 'off', 'why' => $fb[0]] + $last;
    $wait = $force ? 10 : 55;
    if (time() - (int) $last['ts'] < $wait) return $last + ['cached' => true];
    if (function_exists('ias_push_queue_run')) ias_push_queue_run(0); // a move that has not reached OneUp yet goes first
    if (!ias_push_lock()) return ['state' => 'busy'] + $last;
    try {
      unset($GLOBALS['ias_readback_lists']);
      $changes = [];
      $state = 'ok';
      $error = '';
      if (!$fb) {
        $rb = ias_readback_run(true);
        if (!$rb['ok']) { $state = 'error'; $error = (string) $rb['error']; }
        $changes = (array) $rb['changes'];
      }
      if (function_exists('ias_gbp_readback') && !$g && $state === 'ok') $changes = array_merge($changes, ias_gbp_readback(true));
      $now = ias_now_sql();
      $out = ['at' => $state === 'ok' ? $now : (string) $last['at'], 'ts' => time(), 'state' => $state, 'error' => $error,
        'changes' => $changes ?: (array) ($state === 'ok' ? [] : $last['changes']), 'seq' => (int) $last['seq'] + ($changes ? 1 : 0)];
      update_option('ias_pulse', $out, false);
      return $out;
    } finally {
      ias_push_unlock();
    }
  }
}

add_action('wp_ajax_ias_pulse', function () {
  if (!current_user_can('manage_options') || !check_ajax_referer('ias_pulse', '_wpnonce', false)) wp_send_json_error(['message' => 'Not allowed — reload the page.'], 403);
  $r = ias_oneup_pulse(!empty($_POST['force']));
  $ago = $r['at'] !== '' ? human_time_diff(strtotime((string) $r['at']), strtotime(ias_now_sql())) : '';
  wp_send_json_success([
    'state'   => (string) $r['state'],
    'seq'     => (int) $r['seq'],
    'changes' => array_values(array_map('strval', (array) $r['changes'])),
    'ago'     => $ago,
    'why'     => (string) ($r['why'] ?? $r['error'] ?? ''),
  ]);
});

/** The line under the calendar's (and a post's) title: when OneUp was last heard from, and Check now. */
if (!function_exists('ias_pulse_pill')) {
  function ias_pulse_pill(): string {
    $fb = ias_oneup_blockers(false);
    $g = function_exists('ias_gbp_blockers_read') ? ias_gbp_blockers_read() : ['off'];
    if ($fb && $g) return '';
    $last = get_option('ias_pulse', []);
    $seq = is_array($last) ? (int) ($last['seq'] ?? 0) : 0;
    return '<div class="ias-pulse" id="ias-pulse" data-seq="' . $seq . '" data-nonce="' . esc_attr(wp_create_nonce('ias_pulse')) . '" aria-live="polite">'
      . '<span class="ias-pulse-dot"></span> <span class="ias-pulse-text">OneUp: checking…</span> '
      . '<button type="button" class="button-link ias-pulse-now">Check now</button> <a href="" class="ias-pulse-show" hidden>Show the changes</a></div>';
  }
}

add_action('ias_calendar_before_grid', function (): void {
  echo ias_pulse_pill();
}, 1);

add_action('admin_footer', function () {
  if (($_GET['page'] ?? '') !== IAS_PAGE_CALENDAR || !current_user_can('manage_options')) return;
  ?>
<script>
jQuery(function ($) {
  var $p = $('#ias-pulse');
  if (!$p.length) return;
  var seq = parseInt($p.data('seq'), 10) || 0, busy = false, timer = null;
  var onPost = /[?&]post=\d+/.test(location.search);
  function dirty() { return !!(window.iasPost && window.iasPost.dirty && window.iasPost.dirty()); }
  function paint(d) {
    var t = $p.find('.ias-pulse-text');
    $p.removeClass('is-ok is-error is-new');
    if (d.state === 'off') { t.text('OneUp: not connected — ' + d.why + '.'); return; }
    if (d.state === 'error') { $p.addClass('is-error'); t.text('OneUp did not answer (' + d.why + ') — trying again in a minute.'); return; }
    var heard = d.ago ? 'checked ' + d.ago + ' ago — nothing new' : 'nothing new';
    if (d.seq > seq) {
      $p.addClass('is-new');
      t.text('OneUp: ' + (d.changes.length ? d.changes.join(', ') : 'something changed') + '.');
      if (!onPost && !dirty()) { location.reload(); return; } // the calendar shows it at once
      $p.find('.ias-pulse-show').prop('hidden', false);
      return;
    }
    $p.addClass('is-ok');
    t.text('OneUp: ' + heard + '.');
  }
  function ask(force) {
    if (busy || document.hidden) return;
    busy = true;
    $.post(ajaxurl, { action: 'ias_pulse', _wpnonce: $p.data('nonce'), force: force ? 1 : 0 })
      .done(function (r) { if (r && r.success) paint(r.data); })
      .always(function () { busy = false; });
  }
  $p.on('click', '.ias-pulse-now', function () { ask(true); });
  $p.on('click', '.ias-pulse-show', function (e) {
    e.preventDefault();
    if (dirty() && !window.confirm('You have changes that are not saved. Show what changed in OneUp anyway (your changes are lost)?')) return;
    location.reload();
  });
  ask(false);
  timer = setInterval(function () { ask(false); }, 60000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) ask(false); });
});
</script>
  <?php
});
