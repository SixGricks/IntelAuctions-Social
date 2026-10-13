<?php
/**
 * Intel Auctions Social — Lane A: approve first, by email.
 *
 * The roundup and Results posts carry hard facts, so nothing in Lane A goes
 * anywhere until DJ or Jacob says yes. Once such a post has a draft that
 * passed its checks it becomes "Waiting for approval" and each approver gets
 * an email with the full text, the picture and two buttons:
 *
 *   Approve — a signed one-time link (a random token, kept only as a hash,
 *             tied to that approver and that post). It opens a page with the
 *             post and an Approve button — a click on the page, not the link,
 *             approves, so an email scanner that follows links approves
 *             nothing. The first yes wins; every other link for the post is
 *             spent with it. A link lasts IAS_APPROVAL_DAYS days; an expired,
 *             used, replaced or altered one fails safely and changes nothing.
 *   Edit    — opens the post in WordPress (login needed): change the text,
 *             then Save and approve.
 *
 * A post still waiting gets a reminder every morning — with fresh links —
 * until someone answers; it never expires. No approval email or reminder goes
 * out on a Sunday. Approving puts the post on the plan at once, at the next
 * free time at least an hour ahead.
 *
 * Also here: the Monday review email.
 */

if (!defined('ABSPATH')) exit;

if (!defined('IAS_APPROVAL_DAYS')) define('IAS_APPROVAL_DAYS', 4);

/** Lane A posts that can be sent for approval: a draft of today's facts, no holds, not yet asked or answered. */
if (!function_exists('ias_approval_ready')) {
  function ias_approval_ready(array $p): bool {
    return $p['lane'] === 'A' && $p['status'] === 'planned' && !empty($p['content']['text'])
      && !ias_post_needs_decision($p) && empty($p['flags']['stale']);
  }
}

/** Sunday in the site's time zone? (No approval emails, no reminders.) */
if (!function_exists('ias_is_sunday')) {
  function ias_is_sunday(): bool {
    return ias_now()->format('N') === '7';
  }
}

/** The approve link for one token. */
if (!function_exists('ias_approve_url')) {
  function ias_approve_url(int $post_id, string $token): string {
    return add_query_arg(['action' => 'ias_approve', 'p' => $post_id, 't' => $token], admin_url('admin-post.php'));
  }
}

/** Where Edit goes: the post on the Social Calendar (login needed). */
if (!function_exists('ias_edit_url')) {
  function ias_edit_url(int $post_id): string {
    return ias_admin_url(IAS_PAGE_CALENDAR, ['post' => $post_id]) . '#ias-text';
  }
}

/**
 * New tokens for every approver: [email => token]. Only the hash is kept,
 * with the approver, the round and the expiry.
 */
if (!function_exists('ias_approval_tokens')) {
  function ias_approval_tokens(int $post_id, int $round): array {
    $p = ias_post_get($post_id);
    if (!$p) return [];
    $a = $p['approvals'];
    $keep = array_slice((array) ($a['tokens'] ?? []), -24);
    $out = [];
    foreach (ias_approvers() as $email) {
      $token = bin2hex(random_bytes(24));
      $out[$email] = $token;
      $keep[] = ['h' => hash('sha256', $token), 'email' => $email, 'round' => $round, 'exp' => time() + IAS_APPROVAL_DAYS * DAY_IN_SECONDS, 'used' => 0];
    }
    $a['tokens'] = $keep;
    $a['round'] = $round;
    ias_post_update($post_id, ['approvals' => $a]);
    return $out;
  }
}

/**
 * Check a link: ['ok' => bool, 'reason' => code, 'email' => approver, 'post' => row].
 * Reasons: no_post, bad (unknown or altered), used, expired, replaced (the
 * post's words changed after the email), changed (a fact behind it changed
 * after the email: it is being rewritten), decided (the post was already
 * approved or is no longer waiting).
 */
if (!function_exists('ias_approval_check')) {
  function ias_approval_check(int $post_id, string $token): array {
    $p = ias_post_get($post_id);
    if (!$p) return ['ok' => false, 'reason' => 'no_post', 'post' => null];
    $hash = hash('sha256', $token);
    foreach ((array) ($p['approvals']['tokens'] ?? []) as $t) {
      if (!hash_equals((string) $t['h'], $hash)) continue;
      $stale = !empty($p['flags']['stale']) && in_array($p['status'], ['waiting', 'planned'], true);
      if (!empty($t['void'])) return ['ok' => false, 'reason' => $stale ? 'changed' : 'replaced', 'post' => $p, 'email' => $t['email']];
      if (!empty($t['used'])) return ['ok' => false, 'reason' => $p['status'] === 'waiting' ? 'used' : 'decided', 'post' => $p, 'email' => $t['email']];
      if ($stale) return ['ok' => false, 'reason' => 'changed', 'post' => $p, 'email' => $t['email']];
      if ($p['status'] !== 'waiting') return ['ok' => false, 'reason' => 'decided', 'post' => $p, 'email' => $t['email']];
      if ((int) $t['exp'] < time()) return ['ok' => false, 'reason' => 'expired', 'post' => $p, 'email' => $t['email']];
      return ['ok' => true, 'reason' => '', 'post' => $p, 'email' => $t['email']];
    }
    return ['ok' => false, 'reason' => 'bad', 'post' => $p];
  }
}

/**
 * Approve a waiting post (from a link or the calendar). The first yes wins:
 * every outstanding link is spent. Then the post is planned at once.
 * Returns ['ok' => bool, 'message' => …].
 */
if (!function_exists('ias_approve')) {
  function ias_approve(int $post_id, string $by, string $via): array {
    $p = ias_post_get($post_id);
    if (!$p) return ['ok' => false, 'message' => 'That post no longer exists.'];
    if ($p['status'] === 'approved') return ['ok' => false, 'message' => 'Already approved by ' . $p['approved_by'] . ' on ' . date_i18n('D M j, g:i a', strtotime((string) $p['approved_at'])) . '.'];
    if (!in_array($p['status'], ['waiting', 'planned'], true) || $p['lane'] !== 'A' || empty($p['content']['text'])) {
      return ['ok' => false, 'message' => 'This post cannot be approved now (it is ' . (ias_statuses()[$p['status']]['label'] ?? $p['status']) . ').'];
    }
    if (ias_post_needs_decision($p)) return ['ok' => false, 'message' => 'This draft needs a decision first: ' . implode(' ', (array) $p['flags']['holds'])];
    if (!empty($p['flags']['stale'])) return ['ok' => false, 'message' => 'A fact behind this draft changed after it was written, so it is being rewritten. Approve the new version — it comes in its own email.'];

    $a = $p['approvals'];
    foreach ((array) ($a['tokens'] ?? []) as $i => $t) $a['tokens'][$i]['used'] = 1;
    $a['decision'] = ['by' => $by, 'at' => ias_now_sql(), 'via' => $via];
    ias_post_update($post_id, ['status' => 'approved', 'approvals' => $a, 'approved_by' => mb_substr($by, 0, 100), 'approved_at' => ias_now_sql(), 'note' => '']);
    ias_post_trail($post_id, 'Approved by ' . $by . ' (' . $via . ')', $by);
    ias_log('approved', (int) $p['listing_id'], ['post' => $post_id, 'type' => $p['type'], 'summary' => 'Approved by ' . $by . ' (' . $via . ')'], $by);
    if (function_exists('ias_voice_record')) ias_voice_record($post_id, 'kept'); // a person said yes to these words: the brand-voice log keeps them

    // On the plan straight away — the approval was the yes, so no 48-hour wait.
    if (function_exists('ias_plan_run')) {
      ias_plan_run($by);
      if (function_exists('ias_push_run')) ias_push_run();
    }
    $p = ias_post_get($post_id);
    $when = !empty($p['scheduled_at']) ? date_i18n('l, F j \a\t g:i a', strtotime((string) $p['scheduled_at'])) : '';
    return ['ok' => true, 'message' => 'Approved. ' . ($when !== '' ? 'It is planned for ' . $when . '.' : 'It goes on the plan at the next free time.')];
  }
}

/* ---------------- The emails ---------------- */

/** The email for one approver: [subject, html]. */
if (!function_exists('ias_approval_email')) {
  function ias_approval_email(array $p, string $token, bool $reminder): array {
    $c = $p['content'];
    $def = ias_post_type((string) $p['type']);
    $subject = ($reminder ? 'Reminder — still waiting for your yes: ' : 'Approve: ') . $def['label'] . ' — ' . ias_post_subject($p);
    $img = (string) ($c['media']['images'][0]['url'] ?? '');
    $navy = '#233a66';
    $btn = function (string $url, string $label, string $bg, string $fg) {
      return '<a href="' . esc_url($url) . '" style="display:inline-block;background:' . $bg . ';color:' . $fg . ';text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:6px;margin:0 8px 8px 0;border:1px solid ' . $bg . '">' . esc_html($label) . '</a>';
    };
    $exp = date_i18n('l, F j', time() + IAS_APPROVAL_DAYS * DAY_IN_SECONDS + (int) round((float) get_option('gmt_offset') * HOUR_IN_SECONDS));
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:620px;color:#1d2327">'
      . '<p style="color:' . $navy . ';font-weight:bold;letter-spacing:.04em;margin:0 0 4px">INTEL AUCTIONS · SOCIAL</p>'
      . '<h2 style="margin:0 0 12px">' . esc_html($def['label'] . ': ' . ias_post_subject($p)) . '</h2>'
      . ($reminder ? '<p><strong>This post is still waiting for a yes.</strong> It goes nowhere until one of you approves it.</p>' : '<p>This post carries hard facts, so it waits for a yes from you or ' . (count(ias_approvers()) > 1 ? 'the other approver' : 'another approver') . ' before it is scheduled. Read it through:</p>')
      . ($img !== '' ? '<p><img src="' . esc_url($img) . '" alt="' . esc_attr((string) ($c['media']['images'][0]['alt'] ?? '')) . '" width="540" style="max-width:100%;border-radius:6px"></p>' : '')
      . '<div style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:14px;font-size:15px;line-height:1.5">' . esc_html((string) $c['text']) . '</div>'
      . (!empty($c['first_comment']) ? '<p style="margin:12px 0 4px"><strong>First comment</strong></p><div style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;font-size:13px">' . esc_html((string) $c['first_comment']) . '</div>' : '')
      . '<p style="margin:18px 0 8px">' . $btn(ias_approve_url((int) $p['id'], $token), 'Approve', $navy, '#ffffff') . $btn(ias_edit_url((int) $p['id']), 'Edit in WordPress', '#ffffff', $navy) . '</p>'
      . '<p style="font-size:12px;color:#646970">Approve opens a page where one more click approves it — the first yes wins. This link is yours alone, works once and lasts until ' . esc_html($exp) . '; every reminder brings new ones. Edit needs your WordPress login. '
      . 'Reminders come every morning except Sunday until someone answers.</p></div>';
    return [$subject, $html];
  }
}

/** Send the request (or a reminder) to every approver. Returns how many went. */
if (!function_exists('ias_approval_send')) {
  function ias_approval_send(int $post_id, bool $reminder): int {
    $p = ias_post_get($post_id);
    $to = ias_approvers();
    if (!$p || !$to || ias_is_sunday()) return 0;
    $round = (int) ($p['approvals']['round'] ?? 0) + 1;
    $tokens = ias_approval_tokens($post_id, $round);
    $sent = 0;
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    foreach ($tokens as $email => $token) {
      [$subject, $html] = ias_approval_email($p, $token, $reminder);
      if (wp_mail($email, $subject, $html, $headers)) $sent++;
    }
    $p = ias_post_get($post_id);
    $a = $p['approvals'];
    $a['sent'] = array_slice(array_merge((array) ($a['sent'] ?? []), [['at' => ias_now_sql(), 'to' => array_keys($tokens), 'round' => $round, 'kind' => $reminder ? 'reminder' : 'request', 'ok' => $sent]]), -30);
    $fields = ['approvals' => $a];
    if ($p['status'] === 'planned') $fields['status'] = 'waiting';
    ias_post_update($post_id, $fields);
    ias_post_trail($post_id, ($reminder ? 'Reminder' : 'Sent for approval') . ' to ' . implode(', ', array_keys($tokens)) . ($sent < count($tokens) ? ' (' . (count($tokens) - $sent) . ' failed to send)' : ''));
    ias_log('emailed', (int) $p['listing_id'], ['post' => $post_id, 'type' => $p['type'], 'summary' => ($reminder ? 'Reminder' : 'Approval request') . ' to ' . implode(', ', array_keys($tokens)), 'sent' => $sent]);
    return $sent;
  }
}

/** Send a request now if this post just became ready (not on Sunday). */
if (!function_exists('ias_approval_maybe_send')) {
  function ias_approval_maybe_send(int $post_id): void {
    $p = ias_post_get($post_id);
    if ($p && ias_approval_ready($p) && !ias_is_sunday() && ias_approvers()) ias_approval_send($post_id, false);
  }
}
add_action('ias_post_generated', function ($id) { ias_approval_maybe_send((int) $id); });
add_action('ias_post_edited', function ($id) { ias_approval_maybe_send((int) $id); });
add_action('ias_post_released', function ($id) { ias_approval_maybe_send((int) $id); });

/**
 * The hourly step: requests for posts that became ready, and from the
 * planning hour each morning a reminder for every post still waiting. Never
 * on Sunday.
 */
if (!function_exists('ias_approvals_run')) {
  function ias_approvals_run(): array {
    $out = ['requests' => 0, 'reminders' => 0];
    if (ias_is_sunday()) return $out + ['skipped' => ['Sunday: no approval emails']];
    if (!ias_approvers()) return $out + ['skipped' => ['no approvers set']];
    $now = ias_now();
    $today = $now->format('Y-m-d');
    $morning = (int) $now->format('G') >= (int) ias_setting('plan_hour', 5);
    foreach (ias_posts_query(['status' => ['planned', 'waiting'], 'limit' => 500]) as $p) {
      if ($p['lane'] !== 'A') continue;
      if ($p['status'] === 'planned' && ias_approval_ready($p)) {
        if (ias_approval_send((int) $p['id'], false)) $out['requests']++;
        continue;
      }
      if ($p['status'] !== 'waiting' || !$morning || !empty($p['flags']['stale'])) continue; // a stale draft is being rewritten: its new version brings its own email
      $sent = (array) ($p['approvals']['sent'] ?? []);
      $last = $sent ? substr((string) end($sent)['at'], 0, 10) : '';
      if ($last < $today && ias_approval_send((int) $p['id'], true)) $out['reminders']++;
    }
    return $out;
  }
}

/* ---------------- The approve page (from the email link) ---------------- */

/** A small standalone page (no admin chrome — the approver may not be logged in). */
if (!function_exists('ias_approval_page')) {
  function ias_approval_page(string $title, string $body, int $status = 200): void {
    status_header($status);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
      . '<title>' . esc_html($title) . ' · Intel Auctions</title><style>body{font-family:Arial,Helvetica,sans-serif;background:#f0f0f1;color:#1d2327;margin:0;padding:24px}'
      . '.box{max-width:640px;margin:0 auto;background:#fff;border-radius:10px;padding:24px;border:1px solid #dcdcde}h1{font-size:22px;margin:4px 0 14px}'
      . '.k{color:#233a66;font-weight:bold;letter-spacing:.04em;font-size:12px}.post{white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:14px;line-height:1.5}'
      . 'button,.btn{background:#233a66;color:#fff;border:0;border-radius:6px;padding:12px 24px;font-size:16px;font-weight:bold;cursor:pointer;text-decoration:none;display:inline-block}'
      . '.btn2{background:#fff;color:#233a66;border:1px solid #233a66}img{max-width:100%;border-radius:6px}.muted{color:#646970;font-size:13px}</style></head><body><div class="box">'
      . '<div class="k">INTEL AUCTIONS · SOCIAL</div><h1>' . esc_html($title) . '</h1>' . $body . '</div></body></html>';
  }
}

/**
 * What a link answers: ['title', 'body', 'status']. $yes = the page's Approve
 * button was pressed (a POST); a GET only shows the post.
 */
if (!function_exists('ias_approval_response')) {
  function ias_approval_response(int $post_id, string $token, bool $yes): array {
    $token = (string) preg_replace('/[^a-f0-9]/', '', strtolower($token));
    $check = ias_approval_check($post_id, $token);
    $edit = '<a class="btn btn2" href="' . esc_url(ias_edit_url($post_id)) . '">Open it in WordPress</a>';

    if (!$check['ok']) {
      $p = $check['post'];
      $msg = [
        'no_post'  => ['That post no longer exists', 'It may have been cancelled when its listing changed. Nothing was approved.'],
        'bad'      => ['This link does not work', 'It may have been copied incompletely or changed. Nothing was approved. Use the newest email, or open the post in WordPress.'],
        'used'     => ['This link was already used', 'Each link works once. Nothing was changed.'],
        'replaced' => ['This post was changed since this email', 'Its words were edited or rewritten, so this link no longer approves it. Nothing was approved — the new version comes in its own email.'],
        'changed'  => ['The listing changed since this email', 'A fact behind this post (a date, a price…) changed after it was written, so this version can no longer be approved. Nothing was approved — the corrected version comes in its own email.'],
        'expired'  => ['This link has expired', 'Links last ' . IAS_APPROVAL_DAYS . ' days. Nothing was approved — the post is still waiting. The next morning\'s reminder brings a new link, or open it in WordPress.'],
        'decided'  => ['Already decided', $p && $p['status'] === 'approved'
          ? 'It was approved by ' . esc_html((string) $p['approved_by']) . ' on ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $p['approved_at']))) . ' — the first yes wins. Nothing else to do.'
          : 'This post is no longer waiting for approval (it is ' . esc_html((string) (ias_statuses()[$p['status'] ?? '']['label'] ?? ($p['status'] ?? ''))) . '). Nothing was changed.'],
      ][$check['reason']] ?? ['This link does not work', 'Nothing was approved.'];
      // Only a real approver's link is worth a log line; unknown and altered links are anyone's (and could flood the shared log).
      if (!in_array($check['reason'], ['bad', 'no_post'], true)) {
        ias_log('approved', (int) $p['listing_id'], ['post' => $post_id, 'summary' => 'Approval link refused: ' . $check['reason'], 'refused' => $check['reason']], (string) ($check['email'] ?? 'link'));
      }
      return ['title' => $msg[0], 'body' => '<p>' . $msg[1] . '</p><p>' . ($check['reason'] === 'no_post' ? '' : $edit) . '</p>', 'status' => $check['reason'] === 'bad' ? 403 : 200, 'reason' => $check['reason']];
    }

    $p = $check['post'];
    if ($yes) {
      $r = ias_approve($post_id, (string) $check['email'], 'email link');
      return ['title' => $r['ok'] ? 'Approved — thank you' : 'Not approved', 'body' => '<p>' . esc_html($r['message']) . '</p><p>' . $edit . '</p>', 'status' => 200, 'reason' => $r['ok'] ? 'approved' : 'refused'];
    }

    // Show it, and ask for the click (a scanner that follows links approves nothing).
    $c = $p['content'];
    $img = (string) ($c['media']['images'][0]['url'] ?? '');
    $body = '<p>' . esc_html(ias_post_type((string) $p['type'])['label'] . ': ' . ias_post_subject($p)) . '</p>'
      . ($img !== '' ? '<p><img src="' . esc_url($img) . '" alt="' . esc_attr((string) ($c['media']['images'][0]['alt'] ?? '')) . '"></p>' : '')
      . '<div class="post">' . esc_html((string) $c['text']) . '</div>'
      . (!empty($c['first_comment']) ? '<p class="muted"><strong>First comment:</strong> ' . esc_html((string) $c['first_comment']) . '</p>' : '')
      . '<form method="post" action="' . esc_url(ias_approve_url($post_id, $token)) . '" style="margin-top:18px">'
      . '<input type="hidden" name="ias_yes" value="1"><button type="submit">Approve this post</button> ' . $edit . '</form>'
      . '<p class="muted">Approving as ' . esc_html((string) $check['email']) . '. It goes on the plan at the next free time, at least an hour from now.</p>';
    return ['title' => 'Approve this post?', 'body' => $body, 'status' => 200, 'reason' => 'ask'];
  }
}

if (!function_exists('ias_approval_handle')) {
  function ias_approval_handle(): void {
    $post_id = isset($_REQUEST['p']) ? (int) $_REQUEST['p'] : 0;
    $token   = isset($_REQUEST['t']) ? (string) wp_unslash($_REQUEST['t']) : '';
    $yes     = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_POST['ias_yes']);
    $r = ias_approval_response($post_id, $token, $yes);
    ias_approval_page($r['title'], $r['body'], (int) $r['status']);
    exit;
  }
}
add_action('admin_post_nopriv_ias_approve', 'ias_approval_handle');
add_action('admin_post_ias_approve', 'ias_approval_handle');

/* ---------------- The Monday review email ---------------- */

/** Is the weekly email due? (Its day, from its hour, once a week; never on Sunday.) */
if (!function_exists('ias_review_due')) {
  function ias_review_due(): bool {
    $now = ias_now();
    $day = (int) ias_setting('review_day', 1);
    if ($day === 7) $day = 1;
    if ((int) $now->format('N') !== $day || (int) $now->format('G') < (int) ias_setting('review_hour', 7)) return false;
    return get_option('ias_last_review', '') !== $now->format('Y-m-d');
  }
}

/** The weekly review: [subject, html]. */
if (!function_exists('ias_review_email')) {
  function ias_review_email(): array {
    $now = ias_now();
    $start = $now->setTime(0, 0);
    $end = $start->modify('+6 days');
    $since = $now->modify('-7 days')->format('Y-m-d H:i:s');
    $navy = '#233a66';
    $h = function (string $t) use ($navy) { return '<h3 style="color:' . $navy . ';margin:22px 0 6px;font-size:16px">' . esc_html($t) . '</h3>'; };
    $link = function (array $p) { return '<a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '">' . esc_html(ias_post_type((string) $p['type'])['label'] . ' — ' . ias_post_subject($p)) . '</a>'; };

    $week = ias_posts_query(['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'status' => ['planned', 'held', 'waiting', 'approved', 'pushed', 'published', 'human'], 'limit' => 200]);
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:680px;color:#1d2327">'
      . '<p style="color:' . $navy . ';font-weight:bold;letter-spacing:.04em;margin:0 0 4px">INTEL AUCTIONS · SOCIAL</p>'
      . '<h2 style="margin:0">The week ahead on Facebook</h2><p style="color:#646970;margin:4px 0 0">' . esc_html($start->format('l, F j') . ' – ' . $end->format('l, F j')) . '</p>';

    $html .= $h('This week (' . count($week) . ' posts)');
    if (!$week) $html .= '<p>Nothing planned this week.</p>';
    else {
      $html .= '<table cellpadding="6" style="border-collapse:collapse;width:100%;font-size:14px">';
      foreach ($week as $p) {
        $when = ias_post_when($p);
        $html .= '<tr style="border-bottom:1px solid #f0f0f1"><td style="white-space:nowrap;vertical-align:top">' . esc_html($when ? date_i18n(!empty($p['scheduled_at']) ? 'D M j, g:i a' : 'D M j', strtotime($when->format('Y-m-d H:i:s'))) : '') . '</td>'
          . '<td>' . $link($p) . '</td><td style="white-space:nowrap">' . esc_html((ias_statuses()[ias_post_shown_status($p)]['label'] ?? $p['status']) . (!empty($p['flags']['dry_run']) ? ' (dry run)' : '')) . '</td></tr>';
      }
      $html .= '</table>';
    }

    $waiting = ias_posts_query(['status' => 'waiting', 'limit' => 50]);
    $html .= $h('Waiting for approval (' . count($waiting) . ')');
    $html .= $waiting ? '<ul>' . implode('', array_map(function ($p) use ($link) { return '<li>' . $link($p) . '</li>'; }, $waiting)) . '</ul><p style="font-size:13px;color:#646970">Each has its own email with an Approve button; the morning reminders bring fresh ones.</p>' : '<p>Nothing is waiting.</p>';

    $held = ias_posts_query(['status' => ['held', 'failed'], 'limit' => 50]);
    if ($held) $html .= $h('Needs a decision (' . count($held) . ')') . '<ul>' . implode('', array_map(function ($p) use ($link) { return '<li>' . $link($p) . '<br><span style="color:#646970;font-size:13px">' . esc_html((string) $p['note']) . '</span></li>'; }, $held)) . '</ul>';
    $fixes = function_exists('ias_corrections_waiting') ? ias_corrections_waiting() : [];
    if ($fixes) $html .= $h('Corrections to post as a comment (' . count($fixes) . ')') . '<ul>' . implode('', array_map(function ($p) use ($link) { return '<li>' . $link($p) . '<br><span style="font-size:13px">' . esc_html((string) $p['flags']['correction']['text']) . '</span></li>'; }, $fixes)) . '</ul><p style="font-size:13px;color:#646970">A post that has gone out is never edited (that resets its reach): post the correction as a comment on it, then press Comment posted on the Social Calendar.</p>';

    $voice = function_exists('ias_voice_review_html') ? ias_voice_review_html() : '';
    if ($voice !== '') $html .= $h('House rules: Claude suggested changes') . $voice;

    $new = array_filter(ias_posts_query(['order' => 'id', 'limit' => 300]), function ($p) use ($since) { return (string) $p['created_at'] >= $since && $p['type'] !== 'human'; });
    $counts = array_count_values(array_map(function ($p) { return ias_post_type((string) $p['type'])['label']; }, $new));
    $html .= $h('New since last week (' . count($new) . ')');
    $html .= $new ? '<p>' . esc_html(implode(', ', array_map(function ($k, $v) { return $v . ' ' . $k; }, array_keys($counts), $counts))) . '.</p>' : '<p>No new posts were added to the plan.</p>';

    $dropped = array_filter(ias_posts_query(['status' => 'dropped', 'order' => 'id', 'limit' => 100]), function ($p) use ($since) { return (string) $p['updated_at'] >= $since; });
    $html .= $h('Dropped this week (' . count($dropped) . ')');
    $html .= $dropped ? '<ul>' . implode('', array_map(function ($p) use ($link) { return '<li>' . $link($p) . '<br><span style="color:#646970;font-size:13px">' . esc_html((string) $p['note']) . '</span></li>'; }, $dropped)) . '</ul>' : '<p>Nothing was dropped.</p>';

    // The first weekly email of a month carries last month's summary.
    if ((int) $now->format('j') <= 7 && function_exists('ias_summary_html')) {
      $ym = $now->modify('first day of last month')->format('Y-m');
      $html .= $h('Last month: ' . date_i18n('F Y', strtotime($ym . '-01'))) . ias_summary_html($ym, true);
    }

    $cal = (string) ias_setting('oneup_calendar_url', '');
    $html .= '<p style="margin-top:24px">'
      . ($cal !== '' ? '<a href="' . esc_url($cal) . '" style="display:inline-block;background:' . $navy . ';color:#fff;text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:6px;margin-right:8px">Open the OneUp calendar</a>' : '')
      . '<a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR)) . '" style="display:inline-block;border:1px solid ' . $navy . ';color:' . $navy . ';text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:6px">Open the Social Calendar</a></p>'
      . '<p style="font-size:12px;color:#646970">Sent every ' . esc_html(['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Monday'][(int) ias_setting('review_day', 1)] ?? 'Monday') . ' at ' . (int) ias_setting('review_hour', 7) . ':00 to ' . esc_html(implode(', ', ias_approvers())) . ' by Intel Auctions Social.</p></div>';
    return ['Facebook this week: ' . count($week) . ' posts' . ($waiting ? ', ' . count($waiting) . ' waiting for approval' : '') . ($dropped ? ', ' . count($dropped) . ' dropped' : ''), $html];
  }
}

if (!function_exists('ias_review_send')) {
  function ias_review_send(string $by = 'system'): int {
    $to = ias_approvers();
    if (!$to) return 0;
    [$subject, $html] = ias_review_email();
    $sent = 0;
    foreach ($to as $email) if (wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8'])) $sent++;
    update_option('ias_last_review', ias_now()->format('Y-m-d'), false);
    ias_log('review', 0, ['summary' => 'Weekly email to ' . implode(', ', $to) . ': ' . $subject, 'sent' => $sent], $by);
    return $sent;
  }
}

add_filter('ias_job_run', function (array $result): array {
  $a = ias_approvals_run();
  $result['approvals'] = $a;
  if ($a['requests'] || $a['reminders']) $result['parts'][] = 'approval emails: ' . $a['requests'] . ' new, ' . $a['reminders'] . ' reminders';
  if (ias_review_due()) {
    $n = ias_review_send();
    $result['parts'][] = 'weekly email sent to ' . $n;
  }
  return $result;
}, 20);

/** The post's words changed: every outstanding link for it stops working (the new version gets its own email). */
if (!function_exists('ias_approval_void')) {
  function ias_approval_void(int $post_id): void {
    $p = ias_post_get($post_id);
    if (!$p || empty($p['approvals']['tokens'])) return;
    $a = $p['approvals'];
    foreach ($a['tokens'] as $i => $t) if (empty($t['used'])) $a['tokens'][$i]['void'] = 1;
    ias_post_update($post_id, ['approvals' => $a]);
  }
}
