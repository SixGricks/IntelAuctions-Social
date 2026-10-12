<?php
/**
 * Intel Auctions Social — the listing panel.
 *
 * A box on each auction's edit screen listing that property's social posts —
 * its own and the merged ones it shares (same-day sales, the weekly roundup)
 * — with each one's day, status and a link into the calendar.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('ias_listing_panel_register')) {
  function ias_listing_panel_register(): void {
    if (!current_user_can('manage_options')) return;
    add_meta_box('ias-social-posts', 'Social posts (Facebook)', 'ias_listing_panel_render', ia_schema_post_type(), 'side', 'default');
  }
}
add_action('add_meta_boxes', 'ias_listing_panel_register');

if (!function_exists('ias_listing_panel_render')) {
  function ias_listing_panel_render($post): void {
    if (!($post instanceof WP_Post)) return;
    $posts = ias_db_ready() ? ias_posts_for_listing((int) $post->ID) : [];
    if (!$posts) {
      echo '<p class="description">No social posts for this property yet. They are planned once it is published: the Friday roundup while it is Recently Announced, then its Listing, Open House, Auction Week and Results posts.</p>';
    } else {
      echo '<table class="widefat striped" style="border:0"><tbody>';
      foreach ($posts as $p) {
        $when = ias_post_when($p);
        echo '<tr><td style="padding:4px 6px"><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '"><strong>' . esc_html(ias_post_type((string) $p['type'])['short']) . '</strong></a>'
          . (count($p['listing_ids']) > 1 ? ' <span class="description">(shared)</span>' : '')
          . '<br><span class="description">' . esc_html($when ? date_i18n(!empty($p['scheduled_at']) ? 'D M j, g:i a' : 'D M j', $when->getTimestamp() + $when->getOffset()) : 'no day yet') . '</span></td>'
          . '<td style="padding:4px 6px;text-align:right">' . ias_status_badge((string) $p['status']) . '</td></tr>';
      }
      echo '</tbody></table>';
    }
    echo '<p style="margin-bottom:0"><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR)) . '">Social Calendar →</a></p>';
    /** Later phases add the per-listing controls (Cancel posts, sale kind, parcels, brochure cover). */
    do_action('ias_listing_panel', $post, $posts);
  }
}
