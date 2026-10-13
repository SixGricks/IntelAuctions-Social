<?php
/**
 * Intel Auctions Social — approvals on the screens: Approve and Send for
 * approval on a post, its approval history, Waiting for approval under the
 * calendar, and "Send the weekly email now" under Settings.
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
    echo '<p>' . ias_status_badge('approved') . ' by <strong>' . esc_html(ias_actor_name((string) ($d['by'] ?? $p['approved_by']))) . '</strong> ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) ($d['at'] ?? $p['approved_at'])))) . (!empty($d['via']) ? ' <span class="description">(' . esc_html((string) $d['via']) . ')</span>' : '') . '</p>';
  } elseif ($p['status'] === 'waiting') {
    echo '<p class="description">A reminder goes every morning except Sunday until someone answers.</p>'; // the bar says it is waiting
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

/** Waiting for approval, above the grid — only when something is. */
add_action('ias_calendar_attention', function (): void {
  $waiting = ias_posts_query(['status' => 'waiting', 'limit' => 100]);
  if (!$waiting) return;
  echo '<div class="ias-card"><h3>Waiting for approval <span class="description">(' . count($waiting) . ')</span></h3><ul style="margin:0">';
  foreach ($waiting as $p) {
    $sent = (array) ($p['approvals']['sent'] ?? []);
    $first = $sent ? (string) $sent[0]['at'] : '';
    echo '<li><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '"><strong>' . esc_html(ias_post_type((string) $p['type'])['short']) . '</strong> · ' . esc_html(ias_post_subject($p)) . '</a>'
      . ($first !== '' ? ' <span class="description">asked ' . esc_html(human_time_diff(strtotime($first), strtotime(ias_now_sql()))) . ' ago, ' . count($sent) . ' email' . (count($sent) === 1 ? '' : 's') . '</span>' : '') . '</li>';
  }
  echo '</ul></div>';
});

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
