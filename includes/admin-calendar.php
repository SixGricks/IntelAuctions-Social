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

    /** Later phases add buttons beside Pause (Plan now). */
    $button = (string) apply_filters('ias_calendar_header_actions', $button);
    ia_ui_header(
      'Social Calendar',
      'The next ' . (int) ias_setting('plan_days', 30) . ' days of posts for every listing. Click a post to see it. '
        . 'Sundays never carry a post; the shaded days are inside the ' . (int) ias_setting('lead_hours', 48) . "\u{2011}hour veto window, where nothing new is scheduled.",
      $button
    );
    ias_header_end();
    echo ias_switches_strip(true);
    /** A line about the last plan (Phase 3). */
    do_action('ias_calendar_before_grid');

    if (!ias_db_ready()) {
      echo '<div class="notice notice-warning"><p>The plugin\'s tables are not set up yet. Reload this page; if this stays, deactivate and activate the plugin.</p></div></div>';
      return;
    }

    $days  = max(7, (int) ias_setting('plan_days', 30));
    $today = ias_now()->setTime(0, 0);
    $last  = $today->modify('+' . ($days - 1) . ' days');
    // Whole weeks: this week's earlier days (what went out) and the last week's days past the plan.
    $from  = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
    $to    = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');
    $shown = ias_posts_query(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
    $posts = [];
    $by_day = [];
    foreach ($shown as $p) {
      $when = ias_post_when($p);
      if (!$when) continue;
      $by_day[$when->format('Y-m-d')][] = $p;
      if ($when >= $today && $when->setTime(0, 0) <= $last) $posts[] = $p;
    }

    // The key: only the states on the calendar now.
    $present = [];
    foreach ($shown as $p) $present[ias_post_shown_status($p)] = true;
    $keys = array_values(array_filter(['planned', 'waiting', 'approved', 'pushed', 'published', 'held', 'failed', 'dropped', 'skipped', 'cancelled', 'human'], function ($st) use ($present) { return isset($present[$st]); }));
    if ($keys) echo '<div class="ias-legend"><span class="description">Key:</span> ' . implode('', array_map('ias_status_badge', $keys)) . '</div>';

    ias_calendar_grid($today, $last, $by_day);

    if (!$posts) {
      ia_ui_empty('🗓', 'Nothing planned yet',
        'Posts appear here once the plugin is planning them: a Listing post when a listing moves to Upcoming, Open House and Auction Week posts as their dates come into range, Results after a sale, and the Friday roundup of new announcements.');
    }

    /** Later phases add the lists below the grid (needs a decision, waiting, dropped). */
    do_action('ias_calendar_after_grid', $posts, $today, $last);

    echo '</div>';
  }
}

/**
 * The grid: Monday-first weeks from this week to the last planned day, whole
 * weeks — this week's earlier days show what went out, the last week's days
 * past the plan are faint. On a phone it is a list of the days that carry
 * posts, each run of empty days one line (`.ias-cal-gap`, hidden on wider
 * screens).
 */
if (!function_exists('ias_calendar_grid')) {
  function ias_calendar_grid(DateTimeImmutable $today, DateTimeImmutable $last, array $by_day): void {
    $floor = ias_now()->modify('+' . (int) ias_setting('lead_hours', 48) . ' hours');
    $cap   = (int) ias_setting('daily_cap', 3);
    $start = $today->modify('-' . ((int) $today->format('N') - 1) . ' days'); // Monday of this week
    $end   = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');   // Sunday of the last week
    $run = [];
    $gap = function () use (&$run) {
      // A run of empty days, for the phone's list: Sundays at its ends say nothing (they never carry a post).
      while ($run && $run[0]->format('N') === '7') array_shift($run);
      while ($run && end($run)->format('N') === '7') array_pop($run);
      $html = '';
      if ($run) {
        $a = $run[0]; $b = end($run);
        $html = '<div class="ias-cal-gap">' . esc_html($a->format('D M j') . ($a == $b ? '' : ' – ' . $b->format('D M j'))) . ' · nothing planned</div>';
      }
      $run = [];
      return $html;
    };

    echo '<div class="ias-cal">';
    foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d) echo '<div class="ias-cal-head' . ($d === 'Sun' ? ' sun' : '') . '">' . esc_html($d) . '</div>';
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
      $key = $day->format('Y-m-d');
      $list = $by_day[$key] ?? [];
      usort($list, function ($a, $b) { return strcmp((string) $a['scheduled_at'], (string) $b['scheduled_at']); });
      $past = $day < $today;
      $beyond = $day > $last;
      $is_today = $key === $today->format('Y-m-d');
      if (!$past && !$beyond && !$is_today && !$list) $run[] = $day;
      elseif (!$past) echo $gap();

      $classes = ['ias-day'];
      if ($day->format('N') === '7') $classes[] = 'sun';
      if ($past) $classes[] = 'past';
      if ($beyond) $classes[] = 'beyond';
      if ($is_today) $classes[] = 'today';
      if ($list) $classes[] = 'has';
      if (!$past && $day->setTime(23, 59) < $floor) $classes[] = 'floor';
      $count = count(array_filter($list, function ($p) { return in_array($p['status'], array_merge(ias_live_statuses(), ['published', 'human']), true); }));
      $title = $day->format('N') === '7' ? 'Never a post on Sunday' : ($past ? 'Earlier this week' : ($beyond ? 'Past the ' . (int) ias_setting('plan_days', 30) . ' days the plan looks ahead' : ''));

      echo '<div class="' . esc_attr(implode(' ', $classes)) . '"' . ($title !== '' ? ' title="' . esc_attr($title) . '"' : '') . '>';
      echo '<div class="ias-day-date"><span>' . ($is_today ? '<b class="ias-today">Today</b> ' : '') . esc_html($day->format('D M j')) . '</span>';
      if ($count && !$past && $day->format('N') !== '7') echo '<span class="n" title="' . esc_attr($count . ' of the ' . $cap . ' posts a day') . '">' . (int) $count . '/' . (int) $cap . '</span>';
      echo '</div>';
      foreach ($list as $p) echo ias_calendar_chip($p);
      echo '</div>';
    }
    echo $gap();
    echo '</div>';
  }
}

if (!function_exists('ias_calendar_chip')) {
  function ias_calendar_chip(array $p): string {
    $def  = ias_post_type((string) $p['type']);
    $timed = !empty($p['scheduled_at']);
    $time = $timed ? date_i18n('g:i a', strtotime((string) $p['scheduled_at'])) : 'no time yet';
    $statuses = ias_statuses();
    $shown = ias_post_shown_status($p);
    $title = ($statuses[$shown]['label'] ?? $shown) . (!empty($p['flags']['dry_run']) ? ' in the dry run (nothing was sent)' : '') . (!empty($p['locked']) ? ' · edited in OneUp' : '')
      . ($timed ? '' : ' · not placed on the plan yet: shown on its best day') . ' · score ' . number_format((float) $p['score'], 0);
    return '<a class="ias-chip st-' . esc_attr($shown) . ($timed ? '' : ' untimed') . '" href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '" title="' . esc_attr($title) . '">'
      . '<span class="t">' . esc_html($def['short']) . '</span> <span class="tm">' . esc_html($time) . '</span>'
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
      ias_header_end();
      echo '<div class="notice notice-error"><p>No such post.</p></div></div>';
      return;
    }
    $def = ias_post_type((string) $p['type']);
    // One line under the title: its state, when it goes out, where, and the listing.
    $bits = [ias_status_badge(ias_post_shown_status($p))];
    $bits[] = !empty($p['scheduled_at']) ? 'Goes out <strong>' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $p['scheduled_at']))) . '</strong>' : 'Not scheduled yet';
    if ((string) $p['county'] !== '') $bits[] = esc_html((string) $p['county']);
    if ((string) $p['division'] !== '') $bits[] = esc_html(ias_division_label((string) $p['division']));
    foreach (array_slice((array) $p['listing_ids'], 0, 3) as $lid) {
      $bits[] = '<a href="' . esc_url((string) get_edit_post_link((int) $lid, '')) . '" title="Edit the listing">' . esc_html(html_entity_decode(get_the_title((int) $lid), ENT_QUOTES, 'UTF-8')) . '</a>';
    }
    ia_ui_header($def['short'] . ' post · ' . ias_post_subject($p), implode(' · ', $bits), $back);
    ias_header_end();
    echo ias_switches_strip(true);
    /** The bar across the top: what this post is waiting for, and the buttons that settle it. */
    do_action('ias_post_view_bar', $p);

    echo '<div class="ias-post"><div class="ias-post-edit">';
    /** The left column: the words and the pictures, to edit. */
    do_action('ias_post_view_main', $p);
    if (!has_action('ias_post_view_main')) {
      $text = (string) ($p['content']['text'] ?? '');
      echo '<div class="ias-card"><h2>Preview</h2>' . ($text !== '' ? '<div class="ias-preview">' . esc_html($text) . '</div>' : '<p class="description">No draft written yet.</p>') . '</div>';
    }
    echo '</div><div class="ias-post-preview">';
    /** The middle: the post as each network would show it. */
    do_action('ias_post_view_preview', $p);
    echo '</div><div class="ias-post-side">';
    ias_post_facts_card($p);
    /** The right: approval, what would go to OneUp, and the history. */
    do_action('ias_post_view_side', $p);
    ias_post_trail_card($p);
    echo '</div></div></div>';
  }
}

/**
 * WordPress puts a notice after the first .wp-header-end. The toolkit's
 * header prints one from 4.67.0; with an older toolkit, the page does.
 */
if (!function_exists('ias_header_end')) {
  function ias_header_end(): void {
    if (!defined('IA_TOOLKIT_VERSION') || version_compare((string) IA_TOOLKIT_VERSION, '4.67.0', '<')) echo '<hr class="wp-header-end">';
  }
}

/** The post's facts, in the words a person uses. */
if (!function_exists('ias_post_facts_card')) {
  function ias_post_facts_card(array $p): void {
    $day = function ($d) { return $d ? date_i18n('D M j', strtotime((string) $d)) : ''; };
    echo '<div class="ias-card"><h3>About this post</h3><table class="ias-kv">';
    if (!empty($p['flags']['dry_run'])) echo '<tr><th>Sent</th><td>' . ias_status_badge('published') . ' <span class="description">in the dry run — nothing was sent to OneUp</span></td></tr>';
    if ($p['lane'] === 'A') $approval = 'Needed — emailed to ' . (implode(' and ', array_map('ias_person_name', ias_approvers())) ?: 'the approvers');
    elseif ($p['lane'] === 'B') $approval = 'Not needed — ' . (int) ias_setting('lead_hours', 48) . ' hours to veto it in OneUp';
    else $approval = '';
    if ($approval !== '') echo '<tr><th>Approval</th><td>' . esc_html($approval) . '</td></tr>';
    $start = $day($p['window_start']); $ideal = $day($p['window_ideal']); $end = $day($p['window_end']);
    if ($start === '' && $ideal === '' && $end === '') $can = 'Not set yet';
    elseif ($end === '') $can = 'From ' . ($start ?: $ideal) . ($ideal !== '' ? ' (best ' . $ideal . ')' : '') . ($p['lane'] === 'A' ? ', once approved' : '');
    else $can = ($start ?: $ideal) . ' – ' . $end . ($ideal !== '' ? ' (best ' . $ideal . ')' : '');
    echo '<tr><th>Can go</th><td>' . esc_html($can) . '</td></tr>';
    echo '<tr><th>Goes out</th><td>' . esc_html($p['scheduled_at'] ? date_i18n('D M j, g:i a', strtotime((string) $p['scheduled_at'])) : 'Not scheduled yet') . '</td></tr>';
    echo '<tr><th>Priority</th><td><span title="' . esc_attr('Weight ' . (int) $p['weight'] . ' × the starting-bid multiplier') . '">' . esc_html(number_format((float) $p['score'], 0)) . '</span> · ' . esc_html($p['guaranteed'] ? 'guaranteed a place' : 'can be dropped') . '</td></tr>';
    if ($p['oneup_post_id'] !== '') echo '<tr><th>OneUp id</th><td><code>' . esc_html((string) $p['oneup_post_id']) . '</code></td></tr>';
    $links = [];
    foreach ((array) $p['listing_ids'] as $lid) {
      $links[] = '<a href="' . esc_url((string) get_edit_post_link((int) $lid, '')) . '">' . esc_html(html_entity_decode(get_the_title((int) $lid), ENT_QUOTES, 'UTF-8')) . '</a>';
    }
    if ($links) echo '<tr><th>' . (count($links) > 1 ? 'Listings' : 'Listing') . '</th><td>' . implode('<br>', $links) . '</td></tr>';
    // The note repeats the bar when the post is held, stale, waiting for photos or for a yes.
    $in_bar = $p['status'] === 'held' || !empty($p['flags']['stale']) || !empty($p['flags']['needs']) || in_array($p['status'], ['waiting', 'skipped', 'cancelled', 'dropped', 'failed'], true);
    if (!empty($p['note']) && !$in_bar) echo '<tr><th>Note</th><td>' . esc_html((string) $p['note']) . '</td></tr>';
    echo '</table></div>';
  }
}

/** What has happened to the post, newest first — eight, then the rest folded away. */
if (!function_exists('ias_post_trail_card')) {
  function ias_post_trail_card(array $p): void {
    $trail = isset($p['flags']['trail']) && is_array($p['flags']['trail']) ? array_reverse($p['flags']['trail']) : [];
    echo '<div class="ias-card"><h3>History</h3>';
    if (!$trail) {
      echo '<p class="description">Nothing yet.</p></div>';
      return;
    }
    $li = function (array $t) { return '<li><span class="description">' . esc_html(date_i18n('M j, g:i a', strtotime((string) $t['at']))) . ' · ' . esc_html(ias_actor_name((string) $t['by'])) . '</span><br>' . esc_html((string) $t['what']) . '</li>'; };
    echo '<ul class="ias-trail">' . implode('', array_map($li, array_slice($trail, 0, 8))) . '</ul>';
    if (count($trail) > 8) echo '<details><summary>Show all ' . count($trail) . '</summary><ul class="ias-trail">' . implode('', array_map($li, array_slice($trail, 8))) . '</ul></details>';
    echo '</div>';
  }
}
