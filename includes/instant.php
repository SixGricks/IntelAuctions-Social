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

/** Move… on a post's page: a day and a time, and back to the plan's choice. */
if (!function_exists('ias_move_form')) {
  function ias_move_form(array $p): string {
    if ($p['type'] === 'human' || !in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'pushed', 'dropped'], true) || !empty($p['locked'])) return '';
    $at = !empty($p['scheduled_at']) ? strtotime((string) $p['scheduled_at']) : 0;
    $pinned = (string) ($p['flags']['pinned'] ?? '');
    $html = '<details class="ias-move"><summary>Move…</summary>'
      . ias_post_action_form($p, 'move', 'Move it', 'button',
        '<label>Day <input type="date" name="ias_day" value="' . esc_attr($at ? date('Y-m-d', $at) : '') . '" min="' . esc_attr(ias_now()->format('Y-m-d')) . '" required></label> '
        . '<label>Time <input type="time" name="ias_time" value="' . esc_attr($at ? date('H:i', $at) : '') . '" step="60" required></label> ')
      . '<p class="description">It goes out then — on Facebook' . (function_exists('ias_gbp_live') && ias_channel_settings('gbp')['on'] ? ' (and on Google a minute later)' : '') . ' — and stays there: the morning plan works around it. Not on a Sunday.</p></details>';
    if ($pinned !== '') $html .= ias_post_action_form($p, 'unpin', 'Let the plan choose the time', 'button-link', '', 'Let the morning plan choose this post\'s time again? It is re-planned now.');
    return $html;
  }
}

add_action('ias_calendar_action', function (string $action): void {
  if (!in_array($action, ['move', 'unpin', 'retry', 'unlock', 'gbp_unlock'], true)) return;
  $id = isset($_POST['ias_post']) ? (int) $_POST['ias_post'] : 0;
  $p = $id > 0 ? ias_post_get($id) : null;
  if (!$p || $p['type'] === 'human') return;
  $_POST['ias_back'] = ias_admin_url(IAS_PAGE_CALENDAR, ['post' => $id]);
  $actor = ias_actor();
  $flags = $p['flags'];
  $flash = function (string $html, string $level = 'success') { ia_flash_set('<div class="notice notice-' . $level . '"><p>' . $html . '</p></div>'); };
  $fmt = function (string $at) { return date_i18n('D M j, g:i a', strtotime($at)); };

  switch ($action) {
    case 'move':
      if ($p['type'] === 'human' || !in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'pushed', 'dropped'], true) || !empty($p['locked'])) { $flash('This post cannot be moved now.', 'error'); return; }
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
      $n = ias_move_day_count(substr($when, 0, 10), $id);
      $cap = (int) ias_setting('daily_cap', 3);
      $flash('Moved to <strong>' . esc_html($fmt($when)) . '</strong>. It stays there; the morning plan works around it.'
        . ($n >= $cap ? ' <strong>That day now has ' . ($n + 1) . ' posts</strong> (the plan aims for ' . $cap . ').' : ''), $n >= $cap ? 'warning' : 'success');
      return;

    case 'unpin':
      if (empty($flags['pinned'])) return;
      unset($flags['pinned'], $flags['pinned_by']);
      ias_post_update($id, ['flags' => $flags]);
      ias_post_trail($id, 'The plan chooses its time again', $actor);
      $was = (string) ($p['scheduled_at'] ?? '');
      if (function_exists('ias_plan_run')) ias_plan_run($actor);
      $p = ias_post_get($id) ?: $p;
      $at = (string) ($p['scheduled_at'] ?? '');
      $kept = $at !== '' && $at === $was && ias_post_sent($p)['mode'] !== '' && $at < ias_now()->modify('+' . (int) ias_setting('lead_hours', 48) . ' hours')->format('Y-m-d H:i:s');
      $flash($kept ? 'The plan chooses its time again from now on. It stays at <strong>' . esc_html($fmt($at)) . '</strong> for now: it is on OneUp and inside the ' . (int) ias_setting('lead_hours', 48) . '-hour window, where nothing is moved.'
        : 'The plan chooses its time again' . ($at !== '' ? ': <strong>' . esc_html($fmt($at)) . '</strong>' : '') . '.');
      return;

    case 'retry':
      if ($p['status'] !== 'failed') return;
      // The copy OneUp could not publish stays on OneUp's failed list, by its id — a fresh copy goes instead.
      if (!empty($flags['oneup']['id'])) $flags['failed_copies'][] = (string) $flags['oneup']['id'];
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
      ias_post_trail($id, 'Try again pressed — a fresh copy goes to OneUp', $actor);
      ias_log('planned', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Try again: back on the plan'], $actor);
      if (!array_key_exists('scheduled_at', $fields) || $fields['scheduled_at'] !== null) {
        $flash('Back on the plan for ' . esc_html($fmt((string) $p['scheduled_at'])) . '.');
      } else {
        if (function_exists('ias_plan_run')) ias_plan_run($actor);
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
