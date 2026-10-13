<?php
/**
 * Intel Auctions Social — the plan on the calendar: Plan now, the last
 * plan's summary, the posts dropped and running late, each post's
 * would-be OneUp request, and the OneUp connection check in Settings.
 */

if (!defined('ABSPATH')) exit;

add_filter('ias_calendar_header_actions', function (string $html): string {
  return '<form method="post" style="display:inline;margin-right:6px">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
    . '<input type="hidden" name="ias_cal_action" value="plan_now"><button class="button" data-ia-busy="Planning…">Plan now</button></form>' . $html;
});

add_action('ias_calendar_action', function (string $action): void {
  if ($action !== 'plan_now') return;
  $sync = ias_sync();
  $plan = ias_plan_run(ias_actor());
  $push = ias_push_run();
  $bits = [(int) ($plan['placed'] ?? 0) . ' placed'];
  foreach (['moved', 'dropped', 'reinstated', 'late'] as $k) if (!empty($plan[$k])) $bits[] = (int) $plan[$k] . ' ' . ($k === 'reinstated' ? 'back on the plan' : $k);
  if ($push['dryrun']) $bits[] = $push['dryrun'] . ' dry-run payloads logged';
  if ($push['pushed']) $bits[] = $push['pushed'] . ' scheduled on OneUp';
  if ($push['deleted']) $bits[] = $push['deleted'] . ' taken back';
  if ($push['failed']) $bits[] = $push['failed'] . ' OneUp calls failed (see the log)';
  ias_log('run', 0, ['summary' => 'Plan now: ' . implode(', ', $bits)], ias_actor());
  ia_flash_set('<div class="notice notice-success"><p><strong>Planned.</strong> ' . esc_html(implode(', ', $bits)) . '. <span class="description">(' . (int) $sync['added'] . ' posts added to the plan.)</span></p></div>');
});

add_action('ias_calendar_before_grid', function (): void {
  // Back in the dry run, posts the plan no longer wants as they are can still be on the OneUp calendar.
  if (ias_push_mode() === 'dry') {
    $left = array_filter(ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'dropped', 'skipped', 'cancelled', 'failed'], 'limit' => 2000]), function ($p) {
      return $p['type'] !== 'human' && ias_post_sent($p)['mode'] === 'live' && ($p['status'] !== 'planned' && $p['status'] !== 'approved' || !empty($p['flags']['stale']));
    });
    if ($left) {
      echo '<div class="notice notice-warning inline"><p><strong>' . count($left) . ' post' . (count($left) === 1 ? ' is' : 's are') . ' still scheduled on OneUp</strong> from when pushing was live, though the plan no longer wants ' . (count($left) === 1 ? 'it' : 'them') . ' as ' . (count($left) === 1 ? 'it is' : 'they are') . ' (cancelled, skipped, changed or stopped for a decision). Delete ' . (count($left) === 1 ? 'it' : 'them') . ' in OneUp, or turn live pushing back on to let the plugin take ' . (count($left) === 1 ? 'it' : 'them') . ' off: ';
      echo implode(', ', array_map(function ($p) { return '<a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '">' . esc_html(ias_post_type((string) $p['type'])['short'] . ' · ' . ias_post_subject($p)) . '</a>'; }, array_slice(array_values($left), 0, 10))) . '</p></div>';
    }
  }
  $last = get_option('ias_last_plan_summary', []);
  if (!is_array($last) || empty($last['at'])) {
    echo '<p class="description">Not planned yet — the first plan runs on the next hourly run after ' . (int) ias_setting('plan_hour', 5) . ':00, or press Plan now.</p>';
    return;
  }
  $bits = [(int) $last['placed'] . ' placed'];
  foreach (['moved', 'dropped', 'late'] as $k) if (!empty($last[$k])) $bits[] = (int) $last[$k] . ' ' . $k;
  echo '<p class="description">Last planned ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $last['at']))) . ($last['by'] !== 'system' ? ' by ' . esc_html((string) $last['by']) : '') . ': ' . esc_html(implode(', ', $bits)) . '. Times shown are when each post would go out.</p>';
});

add_action('ias_calendar_after_grid', function (): void {
  $since = ias_now()->modify('-30 days')->format('Y-m-d H:i:s');
  $dropped = array_values(array_filter(ias_posts_query(['status' => 'dropped', 'order' => 'id', 'limit' => 200]), function ($p) use ($since) { return (string) $p['updated_at'] >= $since; }));
  $late = array_values(array_filter(ias_posts_query(['status' => ['planned', 'approved', 'pushed', 'waiting', 'held'], 'limit' => 1000]), function ($p) {
    return !empty($p['flags']['late']) || !empty($p['flags']['relaxed']);
  }));
  if (!$dropped && !$late) {
    echo '<p class="description">Nothing dropped in the last 30 days, and nothing running late — every guaranteed post fits its window.</p>';
    return;
  }
  $list = function (string $title, array $rows, callable $why) {
    echo '<div class="ias-card"><h3>' . esc_html($title) . ' <span class="description">(' . count($rows) . ')</span></h3><ul style="margin:0">';
    foreach (array_slice($rows, 0, 30) as $p) {
      echo '<li><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '"><strong>' . esc_html(ias_post_type((string) $p['type'])['short']) . '</strong> · ' . esc_html(ias_post_subject($p)) . '</a><br><span class="description">' . esc_html((string) $why($p)) . '</span></li>';
    }
    echo '</ul></div>';
  };
  echo '<div class="ias-cols"><div>';
  if ($dropped) $list('Dropped in the last 30 days', $dropped, function ($p) { return (string) $p['note']; });
  else echo '<p class="description">Nothing dropped in the last 30 days. Only Open House and Auction Week posts can be.</p>';
  echo '</div><div>';
  if ($late) $list('Running late or squeezed', $late, function ($p) { return (string) ($p['flags']['late'] ?? $p['flags']['relaxed']); });
  else echo '<p class="description">Every guaranteed post fits its window.</p>';
  echo '</div></div>';
}, 20);

add_action('ias_post_view_side', function (array $p): void {
  if ($p['type'] === 'human') {
    echo '<div class="ias-card"><h3>On OneUp</h3><p>A post a person scheduled for the page in OneUp' . ($p['oneup_post_id'] !== '' ? ' (#' . esc_html((string) $p['oneup_post_id']) . ')' : '') . '. The plan works around it; the plugin never changes it.</p>'
      . (!empty($p['content']['text']) ? '<div class="ias-preview" style="font-size:13px">' . esc_html((string) $p['content']['text']) . '</div>' : '') . '</div>';
    return;
  }
  $sent = ias_post_sent($p);
  if ($sent['mode'] === 'live') {
    $o = (array) $p['flags']['oneup'];
    echo '<div class="ias-card"><h3>On OneUp</h3><p>' . ($p['status'] === 'published' ? 'Went out' : 'Scheduled') . ($sent['id'] !== '' ? ' as post <code>#' . esc_html($sent['id']) . '</code>' : '') . ' for ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) ($o['when'] ?? $p['scheduled_at'])))) . '.'
      . (!empty($p['flags']['unconfirmed']) ? ' <span class="description">(waiting for the read-back to confirm it)</span>' : '') . '</p>';
    if (!empty($p['locked'])) echo '<p><strong>' . esc_html(ucfirst((string) ($p['flags']['edited_in_oneup'] ?? 'edited'))) . ' in OneUp by a person</strong> — the plugin keeps it as they made it and never replaces it.</p>';
    if (!empty($p['flags']['correction']['text']) && empty($p['flags']['correction']['done'])) {
      echo '<p><strong>A fact changed after it went out.</strong> Post this as a comment on it (a published post is never edited — that resets its reach):</p><div class="ias-preview" style="font-size:13px">' . esc_html((string) $p['flags']['correction']['text']) . '</div>';
    }
    echo '</div>';
    if ($p['status'] === 'published') return;
  }
  if ($p['status'] === 'failed') {
    echo '<div class="ias-card"><h3>Failed</h3><p>' . esc_html((string) $p['note']) . '</p><p class="description">Retry it in OneUp, or skip it here.</p></div>';
    return;
  }
  echo '<div class="ias-card"><h3>' . ($sent['mode'] === 'live' ? 'What went to OneUp' : 'What would go to OneUp') . '</h3>';
  $why = ias_push_ready($p);
  if ($why !== '') {
    echo '<p class="description">Nothing yet: ' . esc_html($why) . '.</p></div>';
    return;
  }
  $json = function (array $req) { return '<details><summary>Show the request</summary><p class="description">' . esc_html($req['method'] . ' ' . $req['url']) . ' — the key is added when it is sent.</p><div class="ias-json">' . esc_html((string) wp_json_encode($req['params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</div></details>'; };
  $block = ias_push_blockers($p);
  echo '<p><strong>Facebook</strong> — ' . esc_html($block ? 'not sent now: ' . implode('; ', $block) . '.' : ($sent['mode'] === 'live' ? 'on OneUp.' : 'goes on the next hourly run.')) . '</p>' . $json(ias_render_facebook($p));
  foreach (ias_channels_extra_on() as $ch) {
    $req = ias_render_channel($p, $ch);
    $problems = ias_channel_problems($req, $ch);
    echo '<p><strong>' . esc_html((string) ias_channel($ch)['label']) . '</strong> — dry run only, not sent' . ($problems ? '; ' . esc_html(implode('; ', $problems)) : '') . '.</p>' . $json($req);
  }
  echo '</div>';
});

/* ---------------- OneUp connection check (Settings) ---------------- */

add_action('admin_init', function () {
  if (!isset($_POST['ias_oneup_check']) || !current_user_can('manage_options')) return;
  check_admin_referer('ias_oneup_check');
  if (!ias_setting('master', false)) { // off means the plugin never calls OneUp — not even to read
    ia_flash_set('<div class="notice notice-warning"><p>Turn the <strong>master switch</strong> on first (dry run can stay on): while it is off, the plugin never calls OneUp.</p></div>');
    wp_safe_redirect(ias_admin_url(IAS_PAGE_SETTINGS));
    exit;
  }
  $cats = ias_oneup_get('categories');
  if (is_wp_error($cats)) {
    ia_flash_set('<div class="notice notice-error"><p>OneUp did not answer: ' . esc_html($cats->get_error_message()) . '</p></div>');
  } else {
    $html = '<div class="notice notice-success"><p><strong>OneUp answered.</strong> Categories:</p><ul style="list-style:disc;margin-left:20px">';
    foreach (ias_oneup_pairs($cats, ['id', 'category_id'], ['category_name', 'name']) as [$id, $name]) {
      $html .= '<li><code>' . esc_html($id) . '</code> ' . esc_html($name);
      $accts = ias_oneup_get('accounts', ['category_id' => $id]);
      if (!is_wp_error($accts)) {
        $list = [];
        foreach (ias_oneup_pairs($accts, ['social_network_id', 'id'], ['social_network_name', 'username', 'name']) as [$aid, $aname, $row]) {
          $list[] = esc_html($aname . ' (' . ($row['social_network_type'] ?? $row['type'] ?? '') . ')') . ': <code>' . esc_html($aid) . '</code>';
          if ((string) $aid === (string) ias_setting('oneup_account_id', '') && (string) $id === (string) ias_setting('oneup_category_id', '')) ias_settings_update(['oneup_account_name' => $aname]);
        }
        if ($list) $html .= '<br>' . implode('<br>', $list);
      }
      $html .= '</li>';
    }
    $html .= '</ul><p>Copy the category id and the Facebook page\'s account id into the fields below. Nothing was changed in OneUp.</p></div>';
    ia_flash_set($html);
  }
  ias_log('readback', 0, ['summary' => 'Connection check: ' . (is_wp_error($cats) ? $cats->get_error_message() : 'OneUp answered')], ias_actor());
  wp_safe_redirect(ias_admin_url(IAS_PAGE_SETTINGS));
  exit;
});

add_action('admin_init', function () {
  if (!isset($_POST['ias_oneup_verify']) || !current_user_can('manage_options')) return;
  check_admin_referer('ias_oneup_verify');
  $r = ias_oneup_verify(ias_actor());
  ia_flash_set('<div class="notice notice-' . ($r['ok'] ? 'success' : 'warning') . '"><p><strong>Verify OneUp: ' . ($r['ok'] ? 'every step passed.' : 'a step failed') . '</strong> The steps are below.</p></div>');
  wp_safe_redirect(ias_admin_url(IAS_PAGE_SETTINGS) . '#ias-verify');
  exit;
});

add_action('ias_settings_after_form', function () {
  $master = (bool) ias_setting('master', false);
  $ready = !ias_oneup_blockers(false);
  echo '<div class="ias-card" style="max-width:760px"><h2 style="margin-top:0">OneUp connection</h2>';
  if (!$master) echo '<p class="description">The master switch is off, so the plugin never calls OneUp. Turn it on (dry run can stay on) to check the connection and verify.</p>';
  echo '<p><strong>Check connection</strong> asks OneUp for its categories and their accounts with the OneUp key — a read-only check that changes nothing — so the ids above can be copied in.</p>';
  echo '<form method="post" style="margin-bottom:14px">' . wp_nonce_field('ias_oneup_check', '_wpnonce', true, false) . '<input type="hidden" name="ias_oneup_check" value="1">'
    . '<button class="button"' . (ias_oneup_key() === '' || !$master ? ' disabled title="' . esc_attr(!$master ? 'Turn the master switch on first' : 'No OneUp key yet (Settings → OneUp)') . '"' : '') . ' data-ia-busy="Asking OneUp…">Check connection</button></form>';

  echo '<h3 id="ias-verify">Verify OneUp</h3><p>Nothing is sent to OneUp for real until this has passed for the page set above. It checks every call the plugin makes, safely: it saves a <strong>draft</strong> for the page (drafts never publish — and if OneUp scheduled it as a real post anyway, Verify says so and takes it off), reads it back, compares its time with the site\'s, deletes it, and reads the scheduled, published and failed lists.</p>';
  echo '<form method="post">' . wp_nonce_field('ias_oneup_verify', '_wpnonce', true, false) . '<input type="hidden" name="ias_oneup_verify" value="1">'
    . '<button class="button button-primary"' . ($ready ? '' : ' disabled title="' . esc_attr(implode('; ', ias_oneup_blockers(false))) . '"') . ' onclick="return confirm(\'Save a test draft in OneUp (it never publishes), read it back and delete it?\')" data-ia-busy="Checking OneUp…">Verify OneUp</button>'
    . (ias_oneup_verified() ? ' ' . ia_ui_badge('passed for this page', 'ok') : '') . '</form>';
  $v = get_option('ias_oneup_verify', []);
  if (is_array($v) && !empty($v['steps'])) {
    echo '<p style="margin-top:10px">Last run ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $v['at']))) . ' by ' . esc_html((string) $v['by']) . ': ' . ($v['ok'] ? ia_ui_badge('passed', 'ok') : ia_ui_badge('a step failed', 'bad')) . '</p><table class="ias-kv">';
    foreach ((array) $v['steps'] as $st) {
      $badge = $st['ok'] === true ? ia_ui_badge('ok', 'ok') : ($st['ok'] === false ? ia_ui_badge('failed', 'bad') : ia_ui_badge('unclear', 'warn'));
      echo '<tr><th>' . esc_html((string) $st['label']) . '</th><td>' . $badge . ' ' . esc_html((string) $st['detail']) . '</td></tr>';
    }
    echo '</table>';
  }
  $rb = get_option('ias_readback_last', []);
  echo '<h3>Read-back</h3><p>' . (is_array($rb) && !empty($rb['at'])
    ? 'Last read ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $rb['at']))) . ': ' . ($rb['ok'] ? esc_html($rb['changes'] ? implode(', ', (array) $rb['changes']) : 'nothing new') : ia_ui_badge('failed', 'bad') . ' ' . esc_html((string) $rb['error']))
    : 'Every hour, once the master switch is on, the plugin reads back the page\'s scheduled, published and failed posts in OneUp.') . '</p>';
  echo '</div>';
});
