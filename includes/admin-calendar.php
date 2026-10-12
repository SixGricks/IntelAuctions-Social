<?php
/**
 * Intel Auctions Social — Auctions → Social Calendar.
 *
 * The next 30 days at a glance, each post marked planned, waiting for
 * approval, approved, pushed, published, dropped or human, with the Pause
 * button and the switches across the top. A post opens in its own view
 * (`&post=ID`): the preview, its pictures, its window and score, and what has
 * happened to it.
 */

if (!defined('ABSPATH')) exit;

/* ---------------- Pause / resume (POST → redirect → GET) ---------------- */

if (!function_exists('ias_calendar_handle_post')) {
  function ias_calendar_handle_post(): void {
    if (!isset($_POST['ias_cal_action']) || !current_user_can('manage_options')) return;
    check_admin_referer('ias_calendar');
    $action = sanitize_key(wp_unslash($_POST['ias_cal_action']));
    $back   = ias_admin_url(IAS_PAGE_CALENDAR);

    if ($action === 'pause' || $action === 'resume') {
      $paused = $action === 'pause';
      ias_settings_update(['paused' => $paused]);
      ias_log('paused', 0, ['paused' => $paused], ias_actor());
      ia_flash_set('<div class="notice notice-success"><p>' . ($paused
        ? '<strong>Paused.</strong> Nothing goes to OneUp until you press Resume. Planning and drafts carry on.'
        : '<strong>Resumed.</strong> Pushes follow the switches again.') . '</p></div>');
    }

    /** Later phases add their own calendar actions here. */
    do_action('ias_calendar_action', $action);

    $to = isset($_POST['ias_back']) ? esc_url_raw(wp_unslash($_POST['ias_back'])) : '';
    wp_safe_redirect($to !== '' ? $to : $back);
    exit;
  }
}
add_action('admin_init', 'ias_calendar_handle_post');

/* ---------------- Render ---------------- */

if (!function_exists('ias_calendar_render')) {
  function ias_calendar_render(): void {
    if (!current_user_can('manage_options')) return;
    ias_admin_styles();

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if ($post_id > 0) {
      ias_calendar_render_post($post_id);
      return;
    }

    $paused = (bool) ias_setting('paused', false);
    $button = '<form method="post" style="display:inline">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
      . '<input type="hidden" name="ias_cal_action" value="' . ($paused ? 'resume' : 'pause') . '">'
      . '<button class="button ' . ($paused ? 'button-primary' : '') . '">' . ($paused ? '▶ Resume pushes' : '❚❚ Pause all pushes') . '</button></form>';

    ia_ui_header(
      'Social Calendar',
      'The next ' . (int) ias_setting('plan_days', 30) . ' days of Facebook posts for every listing. Click a post to see it. '
        . 'Sundays never carry a post; the shaded days are inside the 48-hour veto window, where nothing new is scheduled.',
      $button
    );
    echo ias_switches_strip();

    if (!ias_db_ready()) {
      echo '<div class="notice notice-warning"><p>The plugin\'s tables are not set up yet. Reload this page; if this stays, deactivate and activate the plugin.</p></div></div>';
      return;
    }

    $days  = max(7, (int) ias_setting('plan_days', 30));
    $today = ias_now()->setTime(0, 0);
    $last  = $today->modify('+' . ($days - 1) . ' days');
    $posts = ias_posts_query(['from' => $today->format('Y-m-d'), 'to' => $last->format('Y-m-d')]);

    $by_day = [];
    foreach ($posts as $p) {
      $when = ias_post_when($p);
      if ($when) $by_day[$when->format('Y-m-d')][] = $p;
    }

    echo '<div class="ias-legend">';
    foreach (['planned', 'waiting', 'approved', 'pushed', 'published', 'held', 'dropped', 'human'] as $st) echo ias_status_badge($st);
    echo '</div>';

    ias_calendar_grid($today, $last, $by_day);

    if (!$posts) {
      ia_ui_empty('🗓', 'Nothing planned yet',
        'Posts appear here once the plugin is planning them: a Listing post when a listing moves to Upcoming, Open House and Auction Week posts as their dates come into range, Results after a sale, and the Friday roundup of new announcements.');
    }

    /** Later phases add the lists below the grid (needs a decision, waiting, dropped, event cards). */
    do_action('ias_calendar_after_grid', $posts, $today, $last);

    echo '</div>';
  }
}

/** The month grid: Monday-first weeks from this week to the last planned day. */
if (!function_exists('ias_calendar_grid')) {
  function ias_calendar_grid(DateTimeImmutable $today, DateTimeImmutable $last, array $by_day): void {
    $floor = ias_now()->modify('+' . (int) ias_setting('lead_hours', 48) . ' hours');
    $cap   = (int) ias_setting('daily_cap', 3);
    $start = $today->modify('-' . ((int) $today->format('N') - 1) . ' days'); // Monday of this week
    $end   = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');   // Sunday of the last week

    echo '<div class="ias-cal">';
    foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d) echo '<div class="ias-cal-head">' . esc_html($d) . '</div>';
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
      $key = $day->format('Y-m-d');
      if ($day < $today || $day > $last) {
        echo '<div class="ias-day blank"></div>';
        continue;
      }
      $classes = ['ias-day'];
      if ($day->format('N') === '7') $classes[] = 'sun';
      if ($key === $today->format('Y-m-d')) $classes[] = 'today';
      if ($day->setTime(23, 59) < $floor) $classes[] = 'floor';
      $list  = $by_day[$key] ?? [];
      usort($list, function ($a, $b) { return strcmp((string) $a['scheduled_at'], (string) $b['scheduled_at']); });
      $count = count(array_filter($list, function ($p) { return in_array($p['status'], array_merge(ias_live_statuses(), ['published', 'human']), true); }));

      echo '<div class="' . esc_attr(implode(' ', $classes)) . '">';
      echo '<div class="ias-day-date"><span>' . esc_html($day->format('D M j')) . '</span>';
      if ($day->format('N') === '7') echo '<span class="n">no posts</span>';
      elseif ($count) echo '<span class="n">' . (int) $count . '/' . (int) $cap . '</span>';
      echo '</div>';
      foreach ($list as $p) echo ias_calendar_chip($p);
      echo '</div>';
    }
    echo '</div>';
  }
}

if (!function_exists('ias_calendar_chip')) {
  function ias_calendar_chip(array $p): string {
    $def  = ias_post_type((string) $p['type']);
    $time = !empty($p['scheduled_at']) ? date_i18n('g:i a', strtotime((string) $p['scheduled_at'])) : 'unscheduled';
    $statuses = ias_statuses();
    $title = ($statuses[$p['status']]['label'] ?? $p['status']) . ' · score ' . number_format((float) $p['score'], 0);
    return '<a class="ias-chip st-' . esc_attr((string) $p['status']) . '" href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '" title="' . esc_attr($title) . '">'
      . '<span class="t">' . esc_html($def['short']) . '</span> ' . esc_html($time)
      . '<span class="s">' . esc_html(ias_post_subject($p)) . '</span></a>';
  }
}

/* ---------------- One post ---------------- */

if (!function_exists('ias_calendar_render_post')) {
  function ias_calendar_render_post(int $id): void {
    $p = ias_post_get($id);
    $back = '<a class="button" href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR)) . '">← Calendar</a>';
    if (!$p) {
      ia_ui_header('Social post', '', $back);
      echo '<div class="notice notice-error"><p>No such post.</p></div></div>';
      return;
    }
    $def = ias_post_type((string) $p['type']);
    ia_ui_header($def['label'] . ': ' . ias_post_subject($p), '', $back);
    echo ias_switches_strip();

    echo '<div class="ias-cols"><div>';
    /** The post's preview and the things a person can do with it (later phases). */
    do_action('ias_post_view_main', $p);
    if (!has_action('ias_post_view_main')) {
      $text = (string) ($p['content']['text'] ?? '');
      echo '<div class="ias-card"><h2>Preview</h2>';
      echo $text !== '' ? '<div class="ias-preview">' . esc_html($text) . '</div>' : '<p class="description">No draft written yet.</p>';
      echo '</div>';
    }
    echo '</div><div>';
    ias_post_facts_card($p);
    ias_post_trail_card($p);
    echo '</div></div></div>';
  }
}

/** The post's numbers: status, lane, window, score, schedule, listings. */
if (!function_exists('ias_post_facts_card')) {
  function ias_post_facts_card(array $p): void {
    $def = ias_post_type((string) $p['type']);
    $fmt = function ($d) { return $d ? date_i18n('D M j', strtotime((string) $d)) : '—'; };
    echo '<div class="ias-card"><h3>About this post</h3><table class="ias-kv">';
    echo '<tr><th>Status</th><td>' . ias_status_badge((string) $p['status']) . '</td></tr>';
    echo '<tr><th>Lane</th><td>' . esc_html($p['lane'] === 'A' ? 'A — approve first (email)' : ($p['lane'] === 'B' ? 'B — scheduled, 48-hour veto window' : '—')) . '</td></tr>';
    echo '<tr><th>Window</th><td>' . esc_html($fmt($p['window_start']) . ' → ' . $fmt($p['window_ideal']) . ' → ' . ($p['window_end'] ? $fmt($p['window_end']) : 'whenever approved')) . '</td></tr>';
    echo '<tr><th>Scheduled</th><td>' . esc_html($p['scheduled_at'] ? date_i18n('D M j, g:i a', strtotime((string) $p['scheduled_at'])) : 'not yet') . '</td></tr>';
    echo '<tr><th>Priority</th><td>' . esc_html(number_format((float) $p['score'], 1)) . ' <span class="description">(weight ' . (int) $p['weight'] . ($p['guaranteed'] ? ', guaranteed' : ', can be dropped') . ')</span></td></tr>';
    if ($p['county'] !== '') echo '<tr><th>County</th><td>' . esc_html((string) $p['county']) . '</td></tr>';
    if ($p['division'] !== '') echo '<tr><th>Division</th><td>' . esc_html(ias_division_label((string) $p['division'])) . '</td></tr>';
    if ($p['oneup_post_id'] !== '') echo '<tr><th>OneUp id</th><td><code>' . esc_html((string) $p['oneup_post_id']) . '</code></td></tr>';
    $links = [];
    foreach ((array) $p['listing_ids'] as $lid) {
      $links[] = '<a href="' . esc_url((string) get_edit_post_link((int) $lid, '')) . '">' . esc_html(html_entity_decode(get_the_title((int) $lid), ENT_QUOTES, 'UTF-8')) . '</a>';
    }
    if ($links) echo '<tr><th>' . (count($links) > 1 ? 'Listings' : 'Listing') . '</th><td>' . implode('<br>', $links) . '</td></tr>';
    if (!empty($p['note'])) echo '<tr><th>Note</th><td>' . esc_html((string) $p['note']) . '</td></tr>';
    echo '</table></div>';
  }
}

/** What has happened to the post, newest last. */
if (!function_exists('ias_post_trail_card')) {
  function ias_post_trail_card(array $p): void {
    $trail = isset($p['flags']['trail']) && is_array($p['flags']['trail']) ? $p['flags']['trail'] : [];
    echo '<div class="ias-card"><h3>History</h3>';
    if (!$trail) {
      echo '<p class="description">Nothing yet.</p></div>';
      return;
    }
    echo '<ul style="margin:0">';
    foreach (array_reverse($trail) as $t) {
      echo '<li><span class="description">' . esc_html(date_i18n('M j, g:i a', strtotime((string) $t['at']))) . ' · ' . esc_html((string) $t['by']) . '</span><br>' . esc_html((string) $t['what']) . '</li>';
    }
    echo '</ul></div>';
  }
}

/** Division keys → the names the team uses. */
if (!function_exists('ias_division_label')) {
  function ias_division_label(string $division): string {
    $labels = ['farm_home' => 'Farm & Home', 'investment_rec' => 'Investment & Recreation', 'intel' => 'Intel general'];
    return $labels[$division] ?? $division;
  }
}
