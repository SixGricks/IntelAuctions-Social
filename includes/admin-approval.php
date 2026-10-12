<?php
/**
 * Intel Auctions Social — approvals and Facebook Event cards on the screens:
 * Approve and Send for approval on a post, its approval history, Waiting for
 * approval and the Event cards under the calendar, and "Send the weekly email
 * now" under Settings.
 */

if (!defined('ABSPATH')) exit;

add_action('ias_calendar_action', function (string $action): void {
  $id = isset($_POST['ias_post']) ? (int) $_POST['ias_post'] : 0;
  $actor = ias_actor();
  $flash = function (string $html, string $level = 'success') { ia_flash_set('<div class="notice notice-' . $level . '"><p>' . $html . '</p></div>'); };

  if ($action === 'approve' && $id > 0) {
    $_POST['ias_back'] = ias_admin_url(IAS_PAGE_CALENDAR, ['post' => $id]);
    $user = wp_get_current_user();
    $r = ias_approve($id, $user && $user->user_email ? strtolower($user->user_email) : $actor, 'WordPress');
    $flash(esc_html($r['message']), $r['ok'] ? 'success' : 'warning');
    return;
  }
  if (($action === 'send_approval' || $action === 'remind') && $id > 0) {
    $_POST['ias_back'] = ias_admin_url(IAS_PAGE_CALENDAR, ['post' => $id]);
    if (ias_is_sunday()) { $flash('No approval emails go out on Sunday — it goes tomorrow morning.', 'warning'); return; }
    $p = ias_post_get($id);
    $can = $p && ($action === 'remind' ? $p['status'] === 'waiting' && empty($p['flags']['stale']) : ias_approval_ready($p));
    if (!$can) { $flash('This post cannot be sent for approval now — it needs a decision, or its draft is being rewritten.', 'warning'); return; }
    $n = ias_approval_send($id, $action === 'remind');
    $flash($n ? 'Sent to ' . esc_html(implode(', ', ias_approvers())) . '.' : 'Nothing was sent (no approvers set, or the email failed).', $n ? 'success' : 'error');
    return;
  }
  if ($action === 'event_mark') {
    $listing = isset($_POST['ias_listing']) ? (int) $_POST['ias_listing'] : 0;
    $key = isset($_POST['ias_key']) ? sanitize_key(wp_unslash($_POST['ias_key'])) : '';
    $status = isset($_POST['ias_status']) && $_POST['ias_status'] === 'skipped' ? 'skipped' : 'created';
    if ($listing > 0 && $key !== '') ias_event_mark($listing, $key, $status, $actor);
    $flash($status === 'created' ? 'Marked created.' : 'Skipped.');
    return;
  }
});

/** Approve / Send buttons on a post. */
add_action('ias_post_view_actions', function (array $p, callable $form): void {
  if ($p['lane'] !== 'A' || empty($p['content']['text'])) return;
  $clean = !ias_post_needs_decision($p) && empty($p['flags']['stale']); // a stale draft is rewritten first
  if (in_array($p['status'], ['planned', 'waiting'], true) && $clean) echo $form('approve', 'Approve', 'button button-primary', '', 'Approve this post? It goes on the plan at the next free time.');
  if ($p['status'] === 'planned' && $clean) echo $form('send_approval', 'Send for approval now', 'button');
  if ($p['status'] === 'waiting' && $clean) echo $form('remind', 'Send a reminder now', 'button');
}, 10, 2);

/** Who was asked, when, and who said yes. */
add_action('ias_post_view_side', function (array $p): void {
  if ($p['lane'] !== 'A') return;
  $a = $p['approvals'];
  echo '<div class="ias-card"><h3>Approval</h3>';
  if ($p['status'] === 'approved' || !empty($a['decision'])) {
    $d = (array) ($a['decision'] ?? []);
    echo '<p>' . ias_status_badge('approved') . ' by <strong>' . esc_html((string) ($d['by'] ?? $p['approved_by'])) . '</strong> ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) ($d['at'] ?? $p['approved_at'])))) . (!empty($d['via']) ? ' <span class="description">(' . esc_html((string) $d['via']) . ')</span>' : '') . '</p>';
  } elseif ($p['status'] === 'waiting') {
    echo '<p>' . ias_status_badge('waiting') . ' — nothing goes to OneUp until someone approves it. A reminder goes every morning except Sunday.</p>';
  } else {
    echo '<p class="description">Lane A: this post waits for an emailed yes from ' . esc_html(implode(' or ', ias_approvers()) ?: 'an approver (none set in Settings)') . ' once its draft is clean.</p>';
  }
  $sent = array_reverse((array) ($a['sent'] ?? []));
  if ($sent) {
    echo '<ul style="margin:0">';
    foreach (array_slice($sent, 0, 8) as $s) {
      echo '<li class="description">' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $s['at'])) . ' — ' . ($s['kind'] === 'reminder' ? 'reminder' : 'request') . ' to ' . implode(', ', (array) $s['to'])) . '</li>';
    }
    echo '</ul>';
  }
  echo '</div>';
});

/** Waiting for approval, and the Facebook Event cards, under the grid. */
add_action('ias_calendar_after_grid', function (): void {
  $waiting = ias_posts_query(['status' => 'waiting', 'limit' => 100]);
  echo '<div class="ias-card"><h3>Waiting for approval <span class="description">(' . count($waiting) . ')</span></h3>';
  if (!$waiting) echo '<p class="description">Nothing is waiting. Roundup and Results posts are emailed for a yes as soon as their drafts are clean.</p>';
  else {
    echo '<ul style="margin:0">';
    foreach ($waiting as $p) {
      $sent = (array) ($p['approvals']['sent'] ?? []);
      $first = $sent ? (string) $sent[0]['at'] : '';
      echo '<li><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '"><strong>' . esc_html(ias_post_type((string) $p['type'])['short']) . '</strong> · ' . esc_html(ias_post_subject($p)) . '</a>'
        . ($first !== '' ? ' <span class="description">asked ' . esc_html(human_time_diff(strtotime($first), strtotime(ias_now_sql()))) . ' ago, ' . count($sent) . ' email' . (count($sent) === 1 ? '' : 's') . '</span>' : '') . '</li>';
    }
    echo '</ul>';
  }
  echo '</div>';

  $cards = ias_event_cards();
  $on = ias_type_on('events');
  echo '<div class="ias-card"><h3>Facebook Events to create <span class="description">(' . count($cards) . ')</span></h3>';
  echo '<p class="description">Facebook does not let outside apps create a Page\'s events, so each open house and auction day is prepared here once its Listing post has gone out. In Meta Business Suite → Events → Create event, copy the fields in, then press Mark created.'
    . ($on ? '' : ' (The Facebook Events switch is off, so these are not in the Monday email.)') . '</p>';
  foreach ($cards as $c) {
    $field = function (string $label, string $value, bool $area = false) {
      return '<p style="margin:4px 0"><span class="description">' . esc_html($label) . '</span><br>' . ($area
        ? '<textarea readonly rows="5" class="large-text" onclick="this.select()">' . esc_textarea($value) . '</textarea>'
        : '<input type="text" readonly class="large-text" value="' . esc_attr($value) . '" onclick="this.select()">') . '</p>';
    };
    echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin:0 0 12px;display:flex;gap:14px;flex-wrap:wrap">';
    if ($c['cover'] !== '') echo '<div><a href="' . esc_url($c['cover']) . '" target="_blank" rel="noopener"><img src="' . esc_url($c['cover']) . '" alt="" style="width:220px;border-radius:4px"></a><br><span class="description">Cover photo — open, then save</span></div>';
    echo '<div style="flex:1;min-width:260px"><strong>' . esc_html($c['kind']) . '</strong>';
    echo $field('Event name', $c['title']);
    echo $field('Starts', $c['start']->format('l, F j, Y g:i a')) . $field('Ends', $c['end']->format('l, F j, Y g:i a'));
    echo $field('Location', $c['where']) . $field('Details', $c['description'], true);
    foreach (['created' => 'Mark created', 'skipped' => 'Skip'] as $st => $label) {
      echo '<form method="post" style="display:inline-block;margin-right:6px">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
        . '<input type="hidden" name="ias_cal_action" value="event_mark"><input type="hidden" name="ias_listing" value="' . (int) $c['listing'] . '">'
        . '<input type="hidden" name="ias_key" value="' . esc_attr($c['key']) . '"><input type="hidden" name="ias_status" value="' . esc_attr($st) . '">'
        . '<button class="button' . ($st === 'created' ? ' button-primary' : '') . '">' . esc_html($label) . '</button></form>';
    }
    echo '</div></div>';
  }
  if (!$cards) echo '<p class="description">None waiting.</p>';
  echo '</div>';
}, 5);

/* ---------------- Settings: the weekly email on demand ---------------- */

add_action('admin_init', function () {
  if (!isset($_POST['ias_review_now']) || !current_user_can('manage_options')) return;
  check_admin_referer('ias_review_now');
  $n = ias_review_send(ias_actor());
  ia_flash_set('<div class="notice notice-' . ($n ? 'success' : 'error') . '"><p>' . ($n ? 'The weekly email went to ' . esc_html(implode(', ', ias_approvers())) . '.' : 'Nothing was sent — set the approvers first.') . '</p></div>');
  wp_safe_redirect(ias_admin_url(IAS_PAGE_SETTINGS));
  exit;
});

add_action('ias_settings_after_form', function () {
  echo '<div class="ias-card" style="max-width:760px"><h2 style="margin-top:0">Weekly email</h2><p>Send this week\'s review email to the approvers now (it also goes by itself on its day and hour).</p>'
    . '<form method="post">' . wp_nonce_field('ias_review_now', '_wpnonce', true, false) . '<input type="hidden" name="ias_review_now" value="1"><button class="button" data-ia-busy="Sending…">Send the weekly email now</button></form></div>';
}, 20);
