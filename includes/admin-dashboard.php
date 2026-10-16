<?php
/**
 * Intel Auctions Social — the WordPress Dashboard widget (0.24.0).
 *
 * DJ, Oct 15, 2026: "a few wordpress dashboards on /wp-admin showing …
 * some important social media metrics."
 *
 * One box on /wp-admin, Social — this week: whether posting is on, this
 * week's posts by where they stand (out, ahead, waiting for approval, needs
 * a decision, failed — each a link to the calendar), how the last 30 days
 * did on Facebook (the numbers stats.php read from OneUp's analytics, added
 * up) and the visits they brought to the site (summary.php, when Google
 * Analytics can be read), and the next three posts going out.
 *
 * Read-only: nothing here calls OneUp, Claude or Google — the numbers are
 * the ones already on the posts. The toolkit's Dashboard boxes (4.71.0) sit
 * beside it and share its styles; with an older toolkit the box carries its
 * own.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('ias_dashboard_register')) {
  function ias_dashboard_register(): void {
    if (!current_user_can('manage_options') || !function_exists('ias_posts_query') || !apply_filters('ias_dashboard_show', true)) return;
    wp_add_dashboard_widget('ias_wpdash', 'Social — this week', 'ias_dashboard_widget');
  }
}
add_action('wp_dashboard_setup', 'ias_dashboard_register');

/** Monday and Sunday of the week $now is in, as Y-m-d. */
if (!function_exists('ias_dashboard_week')) {
  function ias_dashboard_week(DateTimeImmutable $now): array {
    $mon = $now->modify('monday this week')->setTime(0, 0);
    if ($mon > $now) $mon = $mon->modify('-7 days'); // a Sunday: PHP's "monday this week" is tomorrow
    return [$mon->format('Y-m-d'), $mon->modify('+6 days')->format('Y-m-d')];
  }
}

/**
 * The numbers the box shows, pure given the posts and the clock (so the
 * tests can check them): this week's counts, the last 30 days on Facebook,
 * and the next posts.
 */
if (!function_exists('ias_dashboard_numbers')) {
  function ias_dashboard_numbers(array $week_posts, array $recent_posts, array $ahead_posts, DateTimeImmutable $now): array {
    $n = ['out' => 0, 'ahead' => 0, 'waiting' => 0, 'held' => 0, 'failed' => 0, 'off' => 0];
    $now_sql = $now->format('Y-m-d H:i:s');
    foreach ($week_posts as $p) {
      $st = (string) $p['status'];
      if ($st === 'published') { $n['out']++; continue; }
      if ($st === 'waiting') { $n['waiting']++; continue; }
      if ($st === 'held') { $n['held']++; continue; }
      if ($st === 'failed') { $n['failed']++; continue; }
      if (in_array($st, ['dropped', 'skipped', 'cancelled'], true)) { $n['off']++; continue; }
      if (in_array($st, ['planned', 'approved', 'pushed'], true) && (string) ($p['scheduled_at'] ?? '') >= $now_sql) $n['ahead']++;
    }
    $fb = ['counted' => 0, 'reactions' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0, 'views' => 0, 'published' => 0];
    foreach ($recent_posts as $p) {
      if ((string) $p['status'] !== 'published') continue;
      $fb['published']++;
      $st = function_exists('ias_post_stats') ? ias_post_stats($p) : null;
      if (!$st) continue;
      $fb['counted']++;
      foreach (['reactions', 'comments', 'shares', 'clicks', 'views'] as $m) $fb[$m] += (int) ($st[$m] ?? 0);
    }
    $fb['rate'] = function_exists('ias_stats_rate') ? ias_stats_rate($fb) : null;
    $next = [];
    foreach ($ahead_posts as $p) {
      if (!in_array((string) $p['status'], ['planned', 'waiting', 'held', 'approved', 'pushed'], true)) continue;
      if ((string) ($p['scheduled_at'] ?? '') < $now_sql) continue;
      $next[] = $p;
      if (count($next) >= 3) break;
    }
    return ['week' => $n, 'fb' => $fb, 'next' => $next];
  }
}

/** What a post is about, in a few words: "Listing · 40 Steady Lane", "Results · Strasburg". */
if (!function_exists('ias_dashboard_post_words')) {
  function ias_dashboard_post_words(array $p): string {
    $type = function_exists('ias_post_type') ? (string) (ias_post_type((string) $p['type'])['short'] ?? $p['type']) : (string) $p['type'];
    $about = '';
    $lid = (int) ($p['listing_id'] ?? 0);
    if ($lid > 0) $about = html_entity_decode((string) get_the_title($lid), ENT_QUOTES, 'UTF-8');
    if ($about === '' && !empty($p['listing_ids'])) {
      $ids = array_values(array_filter(array_map('intval', explode(',', (string) $p['listing_ids']))));
      if ($ids) $about = html_entity_decode((string) get_the_title($ids[0]), ENT_QUOTES, 'UTF-8') . (count($ids) > 1 ? ' + ' . (count($ids) - 1) . ' more' : '');
    }
    if ($about === '' && !empty($p['content']['text'])) $about = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $p['content']['text'])), 0, 48) . '…';
    return $about !== '' ? $type . ' · ' . $about : $type;
  }
}

if (!function_exists('ias_dashboard_widget')) {
  function ias_dashboard_widget(): void {
    $now = ias_now();
    [$mon, $sun] = ias_dashboard_week($now);
    $week = ias_posts_query(['from' => $mon, 'to' => $sun, 'limit' => 500]);
    $recent = ias_posts_query(['status' => 'published', 'from' => $now->modify('-30 days')->format('Y-m-d'), 'to' => $now->format('Y-m-d'), 'limit' => 1000]);
    $ahead = ias_posts_query(['status' => ['planned', 'waiting', 'held', 'approved', 'pushed'], 'from' => $now->format('Y-m-d'), 'to' => $now->modify('+21 days')->format('Y-m-d'), 'limit' => 60]);
    $d = ias_dashboard_numbers($week, $recent, $ahead, $now);
    $cal = function (array $args = []): string { return esc_url(ias_admin_url(IAS_PAGE_CALENDAR, $args)); };
    $labels = function_exists('ias_statuses') ? ias_statuses() : [];

    // With a toolkit before 4.71.0 the Dashboard has none of the toolkit's styles: the few this box needs.
    if (!defined('IA_TOOLKIT_VERSION') || version_compare((string) IA_TOOLKIT_VERSION, '4.71.0', '<')) {
      echo '<style>.ia-wd{font-size:13px}.ia-wd p{margin:0 0 8px}.ia-wd-h{margin:10px 0 4px;font-weight:600}.ia-wd-h:first-child{margin-top:0}.ia-wd-nums{display:grid;grid-template-columns:repeat(auto-fit,minmax(84px,1fr));gap:6px;margin:4px 0 10px}.ia-wd-num-box{display:block;padding:6px 4px;border:1px solid #dcdcde;border-radius:6px;text-align:center;text-decoration:none;color:#1d2327}.ia-wd-num-box strong{display:block;font-size:18px}.ia-wd-num-box span{display:block;font-size:11px;color:#646970}.ia-wd-num-box.is-warn strong{color:#996800}.ia-wd-num-box.is-bad strong{color:#b32d2e}.ia-wd-num-box.is-ok strong{color:#00753a}.ia-wd-num-box.is-info strong{color:#2271b1}.ia-wd-list{margin:0 0 8px;padding:0;list-style:none}.ia-wd-row{display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-top:1px solid #f0f0f1}.ia-wd-row:first-child{border-top:0}.ia-wd-main{display:flex;flex-direction:column;gap:1px}.ia-wd-main>a{font-weight:600;text-decoration:none}.ia-wd-when,.ia-wd-muted{font-size:12px;color:#646970}.ia-wd-ok,.ia-wd-good{color:#00753a}.ia-wd-warn{color:#996800}.ia-wd-foot{margin:6px 0 0;padding-top:8px;border-top:1px solid #f0f0f1;font-size:12px}.ia-wd-foot a{text-decoration:none}.ia-wd-dot{color:#c3c4c7}</style>';
    }

    echo '<div class="ia-wd ias-wd">';
    $on = (bool) ias_setting('master', false);
    $paused = (bool) ias_setting('paused', false);
    echo '<p class="ia-wd-switch">' . ($paused ? '<span class="ia-wd-warn">⏸ Paused</span> — nothing goes out until Resume on the calendar.'
      : ($on ? '<span class="ia-wd-ok">● Posting is on.</span>' : '<span class="ia-wd-muted">○ Posting is off</span> — nothing reaches Facebook until the master switch is on in Settings.')) . '</p>';

    echo '<p class="ia-wd-h">This week (' . esc_html(date_i18n('M j', strtotime($mon)) . ' – ' . date_i18n('M j', strtotime($sun))) . ')</p>';
    $w = $d['week'];
    echo '<div class="ia-wd-nums">';
    $boxes = [
      ['out', 'out', $w['out'], 'ok'], ['ahead', 'scheduled ahead', $w['ahead'], 'info'],
      ['waiting', 'waiting for approval', $w['waiting'], $w['waiting'] ? 'warn' : ''], ['held', 'need a decision', $w['held'], $w['held'] ? 'warn' : ''],
      ['failed', 'failed', $w['failed'], $w['failed'] ? 'bad' : ''],
    ];
    foreach ($boxes as [$key, $words, $n, $tone]) {
      echo '<a class="ia-wd-num-box' . ($tone !== '' && $n > 0 ? ' is-' . $tone : '') . '" href="' . $cal(['status' => $key === 'out' ? 'published' : $key]) . '"><strong>' . (int) $n . '</strong><span>' . esc_html($words) . '</span></a>';
    }
    echo '</div>';

    $fb = $d['fb'];
    echo '<p class="ia-wd-h">Last 30 days on Facebook</p>';
    if ($fb['published'] === 0) {
      echo '<p class="ia-wd-muted">No posts went out in the last 30 days.</p>';
    } elseif ($fb['counted'] === 0) {
      echo '<p class="ia-wd-muted">' . (int) $fb['published'] . ' ' . ($fb['published'] === 1 ? 'post' : 'posts') . ' went out; Facebook\'s numbers are read once a day from OneUp\'s analytics' . (ias_setting('stats_on', true) ? ' and have not arrived yet.' : ', and that read is switched off in Settings.') . '</p>';
    } else {
      echo '<div class="ia-wd-nums">';
      foreach ([['views', 'views', 'info'], ['reactions', 'reactions', ''], ['comments', 'comments', ''], ['shares', 'shares', ''], ['clicks', 'clicks', '']] as [$k, $words, $tone]) {
        echo '<span class="ia-wd-num-box' . ($tone ? ' is-' . $tone : '') . '"><strong>' . esc_html(number_format((int) $fb[$k])) . '</strong><span>' . esc_html($words) . '</span></span>';
      }
      echo '</div>';
      echo '<p class="ia-wd-muted">' . (int) $fb['counted'] . ' of ' . (int) $fb['published'] . ' posts have numbers' . ($fb['rate'] !== null ? ' · <strong>' . esc_html(number_format((float) $fb['rate'], 1)) . '%</strong> engagement' : '') . '.</p>';
    }
    // This month's visits as the Log's monthly summary read them (cached six hours) — never a Google call from here (0.24.1).
    $visits = function_exists('ias_summary_visits') ? ias_summary_visits($now->format('Y-m-01'), $now->format('Y-m-d'), true) : null;
    if (is_array($visits)) {
      $total = array_sum(array_map('intval', (array) ($visits['by_campaign'] ?? [])));
      echo '<p class="ia-wd-muted">Visits to the site from social posts this month: <strong>' . esc_html(number_format($total)) . '</strong>.</p>';
    }

    echo '<p class="ia-wd-h">Next up</p>';
    if (!$d['next']) {
      echo '<p class="ia-wd-muted">Nothing scheduled in the next three weeks.</p>';
    } else {
      echo '<ul class="ia-wd-list">';
      foreach ($d['next'] as $p) {
        $st = (string) $p['status'];
        $label = (string) ($labels[$st]['label'] ?? ucfirst($st));
        $tone = in_array($st, ['waiting', 'held'], true) ? 'ia-wd-warn' : 'ia-wd-good';
        echo '<li class="ia-wd-row"><span class="ia-wd-main"><a href="' . $cal(['post' => (int) $p['id']]) . '">' . esc_html(ias_dashboard_post_words($p)) . '</a>'
          . '<span class="ia-wd-when">' . esc_html(date_i18n('D, M j, g:i a', strtotime((string) $p['scheduled_at']))) . ' · <span class="' . $tone . '">' . esc_html($label) . '</span></span></span></li>';
      }
      echo '</ul>';
    }
    echo '<p class="ia-wd-foot"><a href="' . $cal() . '">Social Calendar →</a> <span class="ia-wd-dot">·</span> <a href="' . esc_url(ias_admin_url(IAS_PAGE_LOG)) . '">Log →</a></p>';
    echo '</div>';
  }
}
