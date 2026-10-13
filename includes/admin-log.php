<?php
/**
 * Intel Auctions Social — Auctions → Social Calendar → Log.
 *
 * Every generate, approve, push, edit read-back, drop and cancel, newest
 * first, read from the toolkit's event log (`ias.*` events). `?event=dryrun`
 * narrows to one kind — the dry-run payloads are the exact requests that
 * would have gone to OneUp, shown in full.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('ias_log_render')) {
  function ias_log_render(): void {
    if (!current_user_can('manage_options')) return;
    ias_admin_styles();
    $kinds = ias_log_events();
    $event = isset($_GET['event']) ? sanitize_key(wp_unslash($_GET['event'])) : '';
    if (!isset($kinds[$event])) $event = '';

    ia_ui_header('Social Log', 'What the plugin did, newest first — kept in the toolkit\'s event log, which holds the most recent 5,000 entries across both plugins.');
    echo ias_switches_strip();
    /** The monthly summary (summary.php). */
    do_action('ias_log_top');

    echo '<form method="get" style="margin:8px 0 12px">';
    echo '<input type="hidden" name="post_type" value="' . esc_attr(ia_schema_post_type()) . '"><input type="hidden" name="page" value="' . esc_attr(IAS_PAGE_LOG) . '">';
    echo '<label>Show <select name="event"><option value="">everything</option>';
    foreach ($kinds as $key => $label) echo '<option value="' . esc_attr($key) . '" ' . selected($event, $key, false) . '>' . esc_html($key . ' — ' . $label) . '</option>';
    echo '</select></label> <button class="button">Filter</button></form>';

    $rows = ias_log_recent($event, 200);
    if (!$rows) {
      ia_ui_empty('📜', 'Nothing logged yet', $event !== '' ? 'No <code>' . esc_html($event) . '</code> entries yet.' : 'Entries appear as the plugin plans, drafts and pushes posts.');
      echo '</div>';
      return;
    }

    echo '<table class="widefat striped"><thead><tr><th style="width:150px">When</th><th style="width:120px">What</th><th>Listing</th><th>Details</th><th style="width:90px">Who</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $meta = (array) $row['meta'];
      $listing = (int) $row['object_id'];
      $post = isset($meta['post']) ? (int) $meta['post'] : 0;
      echo '<tr>';
      echo '<td>' . esc_html(date_i18n('M j, g:i:s a', strtotime((string) $row['created_at']))) . '</td>';
      echo '<td><code>' . esc_html((string) $row['short']) . '</code></td>';
      echo '<td>' . ($listing > 0 ? '<a href="' . esc_url((string) get_edit_post_link($listing, '')) . '">' . esc_html(html_entity_decode(get_the_title($listing), ENT_QUOTES, 'UTF-8') ?: '#' . $listing) . '</a>' : '—')
        . ($post > 0 ? '<br><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => $post])) . '">post #' . $post . '</a>' : '') . '</td>';
      echo '<td>' . ias_log_meta_html($row) . '</td>';
      echo '<td>' . esc_html((string) $row['actor']) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
  }
}

/** One entry's meta: the summary line, and the full payload for a dry run. */
if (!function_exists('ias_log_meta_html')) {
  function ias_log_meta_html(array $row): string {
    $meta = (array) $row['meta'];
    $out = '';
    if (!empty($meta['summary'])) $out .= esc_html((string) $meta['summary']);
    $rest = $meta;
    unset($rest['summary'], $rest['post'], $rest['payload']);
    if ($rest) {
      $out .= ($out !== '' ? '<br>' : '') . '<span class="description">' . esc_html(mb_substr(wp_json_encode($rest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 400)) . '</span>';
    }
    if (isset($meta['payload'])) {
      $out .= '<details><summary>Payload</summary><div class="ias-json">'
        . esc_html((string) wp_json_encode($meta['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</div></details>';
    }
    return $out !== '' ? $out : '—';
  }
}
