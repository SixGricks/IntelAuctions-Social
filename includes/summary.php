<?php
/**
 * Intel Auctions Social — the monthly summary: which post types and divisions
 * earn their slots.
 *
 * For a month: how many posts of each type and division went out, were
 * dropped, skipped, cancelled or failed, and ran late — and, when the toolkit
 * can reach Google Analytics through Site Kit, the visits each brought to the
 * site. Every link a post carries is tagged `utm_campaign=social_<type>` and
 * `utm_content=p<post id>` (content.php), so GA4's session campaign and
 * manual ad content name the post type and the very post; the toolkit's
 * `ia_stats_ga4()` runs the report (an optional helper: without Site Kit the
 * summary shows the counts alone).
 *
 * Shown at the top of the Log screen (this month and last), and last month's
 * goes in the first weekly email of each month.
 */

if (!defined('ABSPATH')) exit;

/**
 * Site visits from social posts, $from–$to (Y-m-d): ['by_campaign' =>
 * ['social_listing' => n, …], 'by_post' => [post id => n]], or null when
 * Google Analytics cannot be read. Cached six hours; the `ias_summary_visits`
 * filter can answer instead.
 */
if (!function_exists('ias_summary_visits')) {
  function ias_summary_visits(string $from, string $to): ?array {
    $pre = apply_filters('ias_summary_visits', null, $from, $to);
    if (is_array($pre)) return $pre;
    if (!function_exists('ia_sitekit_ready') || !ia_sitekit_ready() || !function_exists('ia_stats_ga4') || !function_exists('ia_stats_ga4_rows')) return null;
    $key = 'ias_visits_' . md5($from . $to);
    $cached = get_transient($key);
    if (is_array($cached)) return $cached;
    $data = ia_stats_ga4(['start' => $from, 'end' => $to], ['sessionCampaignName', 'sessionManualAdContent'], ['sessions'], '', 1000);
    $rows = ia_stats_ga4_rows($data);
    if ($rows === null) return null;
    $out = ['by_campaign' => [], 'by_post' => []];
    foreach ($rows as [$dims, $mets]) {
      $campaign = (string) ($dims[0] ?? '');
      if (strpos($campaign, 'social_') !== 0) continue;
      $n = (int) round((float) ($mets[0] ?? 0));
      $out['by_campaign'][$campaign] = ($out['by_campaign'][$campaign] ?? 0) + $n;
      if (preg_match('/^p(\d+)$/', (string) ($dims[1] ?? ''), $m)) $out['by_post'][(int) $m[1]] = ($out['by_post'][(int) $m[1]] ?? 0) + $n;
    }
    set_transient($key, $out, 6 * HOUR_IN_SECONDS);
    return $out;
  }
}

/**
 * The month's numbers ($ym = 'Y-m'): ['month', 'types' => [type => row],
 * 'divisions' => [division => row], 'human' => n, 'visits' => bool]. A row:
 * out, dry_run, upcoming, dropped, skipped, cancelled, failed, late, visits.
 */
if (!function_exists('ias_summary_month')) {
  function ias_summary_month(string $ym): array {
    $first = $ym . '-01';
    $last = date('Y-m-t', strtotime($first));
    $blank = ['out' => 0, 'dry_run' => 0, 'upcoming' => 0, 'dropped' => 0, 'skipped' => 0, 'cancelled' => 0, 'failed' => 0, 'late' => 0, 'visits' => 0];
    $out = ['month' => $ym, 'types' => [], 'divisions' => [], 'human' => 0, 'visits' => false];
    foreach (ias_post_types() as $type => $def) if ($type !== 'human') $out['types'][$type] = $blank;
    $visits = ias_summary_visits($first, min($last, ias_now()->format('Y-m-d')));
    $out['visits'] = $visits !== null;

    foreach (ias_posts_query(['from' => $first, 'to' => $last, 'limit' => 2000]) as $p) {
      if ($p['type'] === 'human') { $out['human']++; continue; }
      $type = (string) $p['type'];
      $div = (string) $p['division'] !== '' ? (string) $p['division'] : 'intel';
      if (!isset($out['types'][$type])) $out['types'][$type] = $blank;
      if (!isset($out['divisions'][$div])) $out['divisions'][$div] = $blank;
      $st = (string) $p['status'];
      $col = $st === 'published' ? (!empty($p['flags']['dry_run']) ? 'dry_run' : 'out')
        : (in_array($st, ['dropped', 'skipped', 'cancelled', 'failed'], true) ? $st : 'upcoming');
      $out['types'][$type][$col]++;
      $out['divisions'][$div][$col]++;
      if (!empty($p['flags']['late']) && $st === 'published') { $out['types'][$type]['late']++; $out['divisions'][$div]['late']++; }
      $v = (int) ($visits['by_post'][(int) $p['id']] ?? 0);
      $out['types'][$type]['visits'] += $v;
      $out['divisions'][$div]['visits'] += $v;
    }
    // A campaign's visits that carried no post id (an old link, a shared one) still count for its type.
    foreach ((array) ($visits['by_campaign'] ?? []) as $campaign => $n) {
      $type = substr((string) $campaign, 7);
      if (!isset($out['types'][$type])) continue;
      $out['types'][$type]['visits'] = max($out['types'][$type]['visits'], (int) $n);
    }
    return $out;
  }
}

/** One month's summary as HTML tables (inline styles, so it also works in an email). */
if (!function_exists('ias_summary_html')) {
  function ias_summary_html(string $ym, bool $email = false): string {
    $s = ias_summary_month($ym);
    $cols = ['out' => 'Went out', 'upcoming' => 'Still to come', 'dropped' => 'Dropped', 'skipped' => 'Skipped', 'cancelled' => 'Cancelled', 'failed' => 'Failed', 'late' => 'Ran late'];
    if ($s['visits']) $cols['visits'] = 'Site visits';
    $any_dry = array_sum(array_column($s['types'], 'dry_run')) > 0;
    if ($any_dry) $cols = ['dry_run' => 'Out in the dry run'] + $cols;
    $th = 'style="text-align:left;padding:4px 10px 4px 0;border-bottom:1px solid #dcdcde;font-size:13px"';
    $td = 'style="padding:4px 10px 4px 0;font-size:13px"';
    $table = function (string $first, array $rows, callable $label) use ($cols, $th, $td) {
      $html = '<table style="border-collapse:collapse;margin:4px 0 12px"><thead><tr><th ' . $th . '>' . esc_html($first) . '</th>';
      foreach ($cols as $c) $html .= '<th ' . $th . '>' . esc_html($c) . '</th>';
      $html .= '</tr></thead><tbody>';
      foreach ($rows as $key => $row) {
        if (!array_sum($row)) continue;
        $html .= '<tr><td ' . $td . '>' . esc_html($label((string) $key)) . '</td>';
        foreach (array_keys($cols) as $c) $html .= '<td ' . $td . '>' . (int) $row[$c] . '</td>';
        $html .= '</tr>';
      }
      return $html . '</tbody></table>';
    };
    $has = (bool) array_filter($s['types'], 'array_sum');
    $html = '';
    if (!$has) return '<p style="font-size:13px;color:#646970">No social posts in ' . esc_html(date_i18n('F Y', strtotime($ym . '-01'))) . '.</p>';
    $html .= $table('Post type', $s['types'], function ($t) { return ias_post_type($t)['label']; });
    $html .= $table('Division', $s['divisions'], function ($d) { return ias_division_label($d); });
    $notes = [];
    if ($s['human']) $notes[] = $s['human'] . ' post' . ($s['human'] === 1 ? '' : 's') . ' by a person on the page as well.';
    $notes[] = $s['visits'] ? 'Site visits come from Google Analytics (Site Kit, through the toolkit): sessions that arrived on a post\'s tagged link.' : 'Connect Site Kit (Google Analytics) in the toolkit to see the visits each post type brings.';
    $html .= '<p style="font-size:12px;color:#646970;margin-top:0">' . esc_html(implode(' ', $notes)) . '</p>';
    return $html;
  }
}

/** At the top of the Log screen: this month and last. */
add_action('ias_log_top', function () {
  $now = ias_now();
  $this_month = $now->format('Y-m');
  $last_month = $now->modify('first day of last month')->format('Y-m');
  echo '<div class="ias-card" style="max-width:1100px"><h2 style="margin-top:4px">Monthly summary</h2><p class="description">Which post types and divisions earn their slots.</p>';
  foreach ([$this_month => 'So far this month', $last_month => 'Last month'] as $ym => $label) {
    echo '<h3>' . esc_html($label . ' — ' . date_i18n('F Y', strtotime($ym . '-01'))) . '</h3><div class="ias-summary-scroll">' . ias_summary_html($ym) . '</div>';
  }
  echo '</div>';
});
