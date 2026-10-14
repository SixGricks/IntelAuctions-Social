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
  if ($push['pushed']) $bits[] = $push['pushed'] . ' scheduled on OneUp';
  if ($push['deleted']) $bits[] = $push['deleted'] . ' taken back';
  if ($push['failed']) $bits[] = $push['failed'] . ' OneUp calls failed (see the log)';
  ias_log('run', 0, ['summary' => 'Plan now: ' . implode(', ', $bits)], ias_actor());
  ia_flash_set('<div class="notice notice-success"><p><strong>Planned.</strong> ' . esc_html(implode(', ', $bits)) . '. <span class="description">(' . (int) $sync['added'] . ' posts added to the plan.)</span></p></div>');
});

add_action('ias_calendar_before_grid', function (): void {
  // Off means off: posts still on OneUp that a switch turned off come back off — say so while they do.
  $left = array_filter(ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'dropped', 'skipped', 'cancelled'], 'limit' => 2000]), function ($p) {
    return $p['type'] !== 'human' && empty($p['locked']) && ias_post_sent($p)['mode'] === 'live' && (ias_push_switched_off($p) || ias_push_ready($p) !== '');
  });
  if ($left) {
    $stuck = ias_oneup_takeback_blockers();
    echo '<div class="notice notice-' . ($stuck ? 'error' : 'info') . ' inline"><p><strong>' . count($left) . ' post' . (count($left) === 1 ? ' is' : 's are') . ' coming back off OneUp</strong> — switched off, skipped, changed or stopped for a decision. '
      . ($stuck ? 'They cannot be taken back without the OneUp key: put it back under Settings → OneUp.' : 'Each goes on the next hourly run at the latest, soonest first.') . ' ';
    echo implode(', ', array_map(function ($p) { return '<a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '">' . esc_html(ias_post_type((string) $p['type'])['short'] . ' · ' . ias_post_subject($p)) . '</a>'; }, array_slice(array_values($left), 0, 10))) . '</p></div>';
  }
  $last = get_option('ias_last_plan_summary', []);
  if (!is_array($last) || empty($last['at'])) {
    echo '<p class="description">Not planned yet — the first plan runs on the next hourly run after ' . (int) ias_setting('plan_hour', 5) . ':00, or press Plan now.</p>';
    return;
  }
  $hold = function_exists('ias_plan_hold_get') ? ias_plan_hold_get() : [];
  if ($hold) {
    $from = date_i18n('g:i a', strtotime((string) $hold['last']) + IAS_PLAN_QUIET * 60);
    $one = (int) $hold['n'] === 1;
    echo '<div class="notice notice-info inline"><p><strong>The plan is waiting while you work.</strong> ' . (int) $hold['n'] . ' post' . ($one ? '' : 's') . ' moved since ' . esc_html(date_i18n('g:i a', strtotime((string) $hold['first']))) . ' — ' . ($one ? 'it stays where you put it' : 'they stay where you put them') . ', and nothing else moves until the plan tidies around ' . ($one ? 'it' : 'them') . ': on the next hourly run from ' . esc_html($from) . ', or now with <strong>Plan now</strong>. Until then a day can show more posts than it will keep.</p></div>';
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
    if (!empty($p['locked'])) echo '<p><strong>' . esc_html(ucfirst((string) ($p['flags']['edited_in_oneup'] ?? 'edited'))) . ' in OneUp by a person</strong> — the plugin keeps it as they made it, until someone presses <strong>Edit it here again</strong> (above).</p>';
    if (!empty($p['flags']['correction']['text']) && empty($p['flags']['correction']['done'])) {
      echo '<p><strong>A fact changed after it went out.</strong> The correction goes on it as a comment (a published post is never edited — that resets its reach):</p><div class="ias-preview" style="font-size:13px">' . esc_html((string) $p['flags']['correction']['text']) . '</div>';
      if (function_exists('ias_correction_button')) echo '<p>' . ias_correction_button($p, ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '</p>';
    } elseif (!empty($p['flags']['correction']['done']) && strpos((string) $p['flags']['correction']['done'], 'posted by') === 0) {
      echo '<p class="description">Correction ' . esc_html((string) preg_replace('/ \d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', '', (string) $p['flags']['correction']['done'])) . ' as a comment: ' . esc_html((string) $p['flags']['correction']['text']) . '</p>';
    }
    echo '</div>';
    if ($p['status'] === 'published') return;
  }
  if ($p['status'] === 'failed') {
    echo '<div class="ias-card"><h3>Failed</h3><p>' . esc_html((string) $p['note']) . '</p><p class="description">Press <strong>Try again</strong> (above): OneUp\'s own copy goes again at the time the plan gives it (a fresh copy, if the post changed since), or skip it.</p></div>';
    return;
  }
  echo '<div class="ias-card"><h3>' . ($sent['mode'] === 'live' ? 'What went to OneUp' : 'What goes to OneUp') . '</h3>';
  $why = ias_push_ready($p);
  if ($why !== '') {
    echo '<p class="description">Nothing yet: ' . esc_html($why) . '.</p></div>';
    return;
  }
  $json = function (array $req) { return '<details><summary>Show the request</summary><p class="description">' . esc_html($req['method'] . ' ' . $req['url']) . ' — the key is added when it is sent.</p><div class="ias-json">' . esc_html((string) wp_json_encode($req['params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</div></details>'; };
  $block = ias_push_blockers($p);
  echo '<p><strong>Facebook</strong> — ' . esc_html($block ? 'not sent: ' . implode('; ', $block) . '.' : ($sent['mode'] === 'live' ? 'on OneUp.' : 'goes on the next hourly run.')) . '</p>' . $json(ias_render_facebook($p));
  if (ias_channel_settings('gbp')['on']) { // Google posts; Instagram, X and LinkedIn are previews only (their tabs)
    $req = ias_render_gbp($p);
    $problems = ias_channel_problems($req, 'gbp');
    $g = (array) ($p['flags']['gbp'] ?? []);
    $gwhy = ias_gbp_ready($p);
    $stop = array_values(array_unique(array_merge(ias_gbp_switched_off($p), ias_gbp_blockers())));
    $state = $stop ? 'not sent: ' . implode('; ', $stop) . '.' : ($gwhy !== '' ? 'not sent: ' . $gwhy . '.' : ($g ? 'on OneUp.' : 'goes on the next hourly run (outside the veto window).'));
    echo '<p><strong>' . esc_html((string) ias_channel('gbp')['label']) . '</strong> — ' . esc_html($state) . ($problems ? ' ' . esc_html(ucfirst(implode('; ', $problems))) . '.' : '') . '</p>' . $json($req);
  }
  echo '</div>';
});

/* ---------------- OneUp accounts (Settings) ---------------- */

/**
 * Look up accounts: OneUp's categories and their accounts, kept as the
 * directory Settings offers to choose from. Without script it is a form
 * (POST → redirect → GET); with it, the page asks over admin-ajax and fills
 * the lists in place, keeping whatever was changed and not yet saved.
 */
add_action('admin_init', function () {
  if (!isset($_POST['ias_oneup_check']) || !current_user_can('manage_options')) return;
  check_admin_referer('ias_oneup_check');
  $dir = ias_oneup_directory_fetch(ias_actor());
  if (isset($dir['error'])) {
    ia_flash_set('<div class="notice notice-' . (ias_setting('master', false) ? 'error' : 'warning') . '"><p>' . esc_html($dir['error']) . '</p></div>');
  } else {
    ia_flash_set('<div class="notice notice-success"><p><strong>OneUp answered.</strong> ' . ias_oneup_directory_line() . ' Choose the category and the accounts under OneUp below, then press Save settings. Nothing was changed in OneUp.</p></div>');
  }
  ias_log('readback', 0, ['summary' => 'Look up accounts: ' . (isset($dir['error']) ? $dir['error'] : 'OneUp answered')], ias_actor());
  wp_safe_redirect(ias_admin_url(IAS_PAGE_SETTINGS) . '#ias-oneup-pick');
  exit;
});

add_action('wp_ajax_ias_oneup_lookup', function () {
  if (!current_user_can('manage_options') || !check_ajax_referer('ias_oneup_check', '_wpnonce', false)) wp_send_json_error(['message' => 'Not allowed — reload the page.'], 403);
  $dir = ias_oneup_directory_fetch(ias_actor());
  ias_log('readback', 0, ['summary' => 'Look up accounts: ' . (isset($dir['error']) ? $dir['error'] : 'OneUp answered')], ias_actor());
  if (isset($dir['error'])) wp_send_json_error(['message' => $dir['error']]);
  $chosen = [];
  foreach ((array) wp_unslash($_POST['chosen'] ?? []) as $k => $v) $chosen[sanitize_key((string) $k)] = sanitize_text_field((string) $v);
  wp_send_json_success(['fields' => ias_oneup_pick_options($chosen), 'summary' => ias_oneup_directory_line()]);
});

/** The page's script: the lists show the chosen category's accounts; Look up accounts fills them in place. */
add_action('admin_footer', function () {
  if (($_GET['page'] ?? '') !== IAS_PAGE_SETTINGS || !current_user_can('manage_options')) return;
  ?>
<script>
jQuery(function ($) {
  var $cat = $('#ias-cat');
  if (!$cat.length) return;
  function filter(changed) {
    var cat = String($cat.val() || '');
    $('.ias-acct-pick').each(function () {
      var $s = $(this);
      $s.find('optgroup').each(function () { var on = !cat || String($(this).data('cat')) === cat; this.hidden = !on; this.disabled = !on; });
      if ($s.find('option:selected').closest('optgroup').prop('disabled')) $s.val('');
      if (changed && !$s.val()) { // the category's only account of this kind, ready chosen (Save settings keeps it)
        var $only = $s.find('optgroup:not([disabled]) option');
        if ($only.length === 1) $s.val($only.val());
      }
    });
  }
  $cat.on('change', function () { filter(true); });
  filter(false);
  $('#ias-lookup').on('click', function (e) {
    e.preventDefault();
    var $b = $(this), was = $b.text(), chosen = { category: $cat.val() || '' };
    $('.ias-acct-pick').each(function () { chosen[this.id === 'ias-acct' ? 'facebook' : this.id.replace('ias-net-', '')] = $(this).val() || ''; });
    $b.prop('disabled', true).text('Asking OneUp…');
    $.post(ajaxurl, { action: 'ias_oneup_lookup', _wpnonce: $('#ias-oneup-lookup-form [name=_wpnonce]').val(), chosen: chosen })
      .done(function (r) {
        if (!r || !r.success) { $('#ias-oneup-found').html('<strong style="color:#b32d2e"></strong>').find('strong').text((r && r.data && r.data.message) || 'OneUp did not answer.'); return; }
        $.each(r.data.fields, function (id, html) { $('#' + id).html(html); });
        $('#ias-oneup-found').html(r.data.summary + ' <strong>Choose below, then Save settings.</strong>');
        filter(false);
      })
      .fail(function () { $('#ias-oneup-found').html('<strong style="color:#b32d2e">The site did not answer — try again, or reload the page.</strong>'); })
      .always(function () { $b.prop('disabled', false).text(was); });
  });
});
</script>
  <?php
});

add_action('admin_init', function () {
  if (!isset($_POST['ias_oneup_verify']) || !current_user_can('manage_options')) return;
  check_admin_referer('ias_oneup_verify');
  $ch = sanitize_key(wp_unslash((string) $_POST['ias_oneup_verify']));
  $ch = in_array($ch, ['gbp', 'youtube'], true) ? $ch : 'facebook';
  $r = ias_oneup_verify(ias_actor(), $ch);
  $label = ['gbp' => 'Verify for Google', 'youtube' => 'Verify for YouTube'][$ch] ?? 'Verify OneUp';
  ia_flash_set('<div class="notice notice-' . ($r['ok'] ? 'success' : 'warning') . '"><p><strong>' . $label . ': ' . ($r['ok'] ? 'every step passed.' : 'a step failed') . '</strong> The steps are below.</p></div>');
  wp_safe_redirect(ias_admin_url(IAS_PAGE_SETTINGS) . ($ch === 'facebook' ? '#ias-verify' : '#ias-verify-' . $ch));
  exit;
});

add_action('ias_settings_after_form', function () {
  $master = (bool) ias_setting('master', false);
  // Look up accounts (its button sits in the OneUp section above, form="ias-oneup-lookup-form").
  echo '<form method="post" id="ias-oneup-lookup-form">' . wp_nonce_field('ias_oneup_check', '_wpnonce', true, false) . '<input type="hidden" name="ias_oneup_check" value="1"></form>';
  echo '<div class="ias-card" style="max-width:760px"><h2 style="margin-top:0">OneUp connection</h2>';
  if (!$master) echo '<p class="description">The master switch is off, so the plugin asks OneUp nothing. Turn it on to look up the accounts and verify — nothing posts while every post type is off.</p>';
  $verify = function (string $ch) {
    $gbp = $ch === 'gbp';
    $yt = $ch === 'youtube';
    $why = $gbp ? ias_gbp_blockers_read() : ($yt ? ias_youtube_blockers_read() : ias_oneup_blockers(false));
    $ready = !$why;
    $passed = $gbp ? ias_gbp_verified() : ($yt ? ias_youtube_verified() : ias_oneup_verified());
    $html = '<form method="post" style="margin-bottom:18px">' . wp_nonce_field('ias_oneup_verify', '_wpnonce', true, false) . '<input type="hidden" name="ias_oneup_verify" value="' . esc_attr($ch) . '">'
      . '<button class="button' . ($passed || (($gbp || $yt) && !ias_oneup_verified()) ? '' : ' button-primary') . '"' . ($ready ? '' : ' disabled title="' . esc_attr(implode('; ', $why)) . '"') . ' onclick="return confirm(\'Save a test draft in OneUp (it never publishes), read it back and delete it?\')" data-ia-busy="Checking OneUp…">' . ($gbp ? 'Verify for Google' : ($yt ? 'Verify for YouTube' : 'Verify OneUp')) . '</button>'
      . ($passed ? ' ' . ia_ui_badge($gbp ? 'passed for this location' : ($yt ? 'passed for this channel' : 'passed for this page'), 'ok') : '') . '</form>';
    $v = get_option($gbp ? 'ias_gbp_verify' : ($yt ? 'ias_youtube_verify' : 'ias_oneup_verify'), []);
    if (is_array($v) && !empty($v['steps'])) {
      $html .= '<p style="margin-top:10px">Last run ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $v['at']))) . ' by ' . esc_html(ias_actor_name((string) $v['by'])) . ': ' . ($v['ok'] ? ia_ui_badge('passed', 'ok') : ia_ui_badge('a step failed', 'bad')) . '</p><table class="ias-kv">';
      foreach ((array) $v['steps'] as $st) {
        $badge = $st['ok'] === true ? ia_ui_badge('ok', 'ok') : ($st['ok'] === false ? ia_ui_badge('failed', 'bad') : ia_ui_badge('unclear', 'warn'));
        $html .= '<tr><th>' . esc_html((string) $st['label']) . '</th><td>' . $badge . ' ' . esc_html((string) $st['detail']) . '</td></tr>';
      }
      $html .= '</table>';
    }
    return '<div class="ias-verify-block" style="margin-bottom:18px">' . $html . '</div>';
  };
  echo '<h3 id="ias-verify">Verify OneUp</h3><p>Nothing is sent to Facebook for real until this has passed for the page chosen above. It checks every call the plugin makes, safely: it saves a <strong>draft</strong> for the page (drafts never publish — and if OneUp scheduled it as a real post anyway, Verify says so and takes it off), reads it back, compares its time with the site\'s, deletes it, and reads the scheduled, published and failed lists.</p>';
  echo $verify('facebook');
  if (ias_channel_settings('gbp')['on'] || get_option('ias_gbp_verify')) {
    echo '<h3 id="ias-verify-gbp">Verify for Google</h3><p>The same round trip for the Google Business Profile location: nothing goes to Google until it has passed for the location chosen above and <strong>Google posts</strong> is on.</p>';
    echo $verify('gbp');
  }
  if (ias_youtube_settings()['account'] !== '' || get_option('ias_youtube_verify')) {
    echo '<h3 id="ias-verify-youtube">Verify for YouTube</h3><p>The same round trip for the YouTube channel — a video post saved as a draft, with no file — and it sends a 42-byte test file to the upload address OneUp hands over (never posted), reads it back, checks a page on this site may upload there, and reads the channel\'s playlists. No video can be uploaded until it has passed for the channel chosen above, and none goes to YouTube until <strong>YouTube</strong> is on.</p>';
    echo $verify('youtube');
  }
  $rb = get_option('ias_readback_last', []);
  echo '<h3>Read-back</h3><p>' . (is_array($rb) && !empty($rb['at'])
    ? 'Last read ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $rb['at']))) . ': ' . ($rb['ok'] ? esc_html($rb['changes'] ? implode(', ', (array) $rb['changes']) : 'nothing new') : ia_ui_badge('failed', 'bad') . ' ' . esc_html((string) $rb['error']))
    : 'Every hour, once the master switch is on, the plugin reads back the page\'s scheduled, published and failed posts in OneUp.') . '</p>';
  echo '</div>';
});
