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
  if ($push['dryrun'] || $push['deleted']) $bits[] = ($push['dryrun'] + $push['deleted']) . ' dry-run payloads logged';
  ias_log('run', 0, ['summary' => 'Plan now: ' . implode(', ', $bits)], ias_actor());
  ia_flash_set('<div class="notice notice-success"><p><strong>Planned.</strong> ' . esc_html(implode(', ', $bits)) . '. <span class="description">(' . (int) $sync['added'] . ' posts added to the plan.)</span></p></div>');
});

add_action('ias_calendar_before_grid', function (): void {
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
  echo '<div class="ias-cols"><div><div class="ias-card"><h3>Dropped in the last 30 days <span class="description">(' . count($dropped) . ')</span></h3>';
  if (!$dropped) echo '<p class="description">Nothing dropped. Only Open House and Auction Week posts can be.</p>';
  else {
    echo '<ul style="margin:0">';
    foreach (array_slice($dropped, 0, 30) as $p) {
      echo '<li><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '"><strong>' . esc_html(ias_post_type((string) $p['type'])['short']) . '</strong> · ' . esc_html(ias_post_subject($p)) . '</a><br><span class="description">' . esc_html((string) $p['note']) . '</span></li>';
    }
    echo '</ul>';
  }
  echo '</div></div><div><div class="ias-card"><h3>Running late or squeezed <span class="description">(' . count($late) . ')</span></h3>';
  if (!$late) echo '<p class="description">Every guaranteed post fits its window.</p>';
  else {
    echo '<ul style="margin:0">';
    foreach ($late as $p) {
      echo '<li><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '"><strong>' . esc_html(ias_post_type((string) $p['type'])['short']) . '</strong> · ' . esc_html(ias_post_subject($p)) . '</a><br><span class="description">' . esc_html((string) ($p['flags']['late'] ?? $p['flags']['relaxed'])) . '</span></li>';
    }
    echo '</ul>';
  }
  echo '</div></div></div>';
}, 20);

add_action('ias_post_view_side', function (array $p): void {
  if ($p['type'] === 'human') return;
  echo '<div class="ias-card"><h3>What would go to OneUp</h3>';
  $why = ias_push_ready($p);
  if ($why !== '') {
    echo '<p class="description">Nothing yet: ' . esc_html($why) . '.</p></div>';
    return;
  }
  $req = ias_render_facebook($p);
  echo '<p class="description">' . esc_html($req['method'] . ' ' . $req['url']) . ' — the key is added when it is sent. Not sent now: ' . esc_html(implode('; ', ias_push_blockers($p))) . '.</p>';
  echo '<div class="ias-json">' . esc_html((string) wp_json_encode($req['params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</div>';
  echo '</div>';
});

/* ---------------- OneUp connection check (Settings) ---------------- */

add_action('admin_init', function () {
  if (!isset($_POST['ias_oneup_check']) || !current_user_can('manage_options')) return;
  check_admin_referer('ias_oneup_check');
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

add_action('ias_settings_after_form', function () {
  echo '<div class="ias-card" style="max-width:760px"><h2 style="margin-top:0">OneUp connection</h2>';
  echo '<p>Ask OneUp for its categories and their accounts with the key in wp-config — a read-only check that changes nothing — so the ids above can be copied in.</p>';
  echo '<form method="post">' . wp_nonce_field('ias_oneup_check', '_wpnonce', true, false) . '<input type="hidden" name="ias_oneup_check" value="1">'
    . '<button class="button"' . (ias_oneup_key() === '' ? ' disabled title="No key in wp-config yet"' : '') . ' data-ia-busy="Asking OneUp…">Check connection</button></form></div>';
});
