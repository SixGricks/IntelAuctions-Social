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
          . '<td style="padding:4px 6px;text-align:right">' . ias_status_badge((string) $p['status']) . (!empty($p['flags']['dry_run']) ? '<br><span class="description">dry run</span>' : '') . '</td></tr>';
      }
      echo '</tbody></table>';
    }
    echo '<p style="margin-bottom:0"><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR)) . '">Social Calendar →</a></p>';
    /** Later phases add the per-listing controls (Cancel posts, sale kind, parcels, brochure cover). */
    do_action('ias_listing_panel', $post, $posts);
  }
}

/* ---------------- What the site has no field for ---------------- */

/**
 * Three things the posts need that the listing has no field for: whether it
 * is real estate or a liquidation / contents sale (its Results post is then a
 * thank-you with no price), the parcels of a multi-parcel sale (the posts lead
 * with them), and the brochure cover (the roundup collage, and the picture for
 * a listing whose photos never arrived). Saved with the auction.
 */
add_action('ias_listing_panel', function ($post) {
  if (!($post instanceof WP_Post)) return;
  $id = (int) $post->ID;
  $kind = (string) get_post_meta($id, '_ias_sale_kind', true);
  $guess = ias_sale_kind($id, html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'), null, false);
  $cover = (int) get_post_meta($id, '_ias_brochure_cover', true);
  $field_cover = ias_attachment_ids(ias_field(ias_acf_field_brochure(), $id));
  wp_nonce_field('ias_listing_panel', 'ias_listing_nonce');
  echo '<hr><p style="margin:8px 0 4px"><strong>For the posts</strong></p>';
  echo '<p style="margin:4px 0"><label>Sale kind<br><select name="ias_sale_kind" style="width:100%">'
    . '<option value="">Decide from the title (' . esc_html($guess === 'liquidation' ? 'liquidation' : 'real estate') . ')</option>'
    . '<option value="real_estate" ' . selected($kind, 'real_estate', false) . '>Real estate</option>'
    . '<option value="liquidation" ' . selected($kind, 'liquidation', false) . '>Liquidation / contents (no price on Results)</option>'
    . '</select></label></p>';
  echo '<p style="margin:4px 0"><label>Parcels <span class="description">(multi-parcel sales, one per line: name | size | starting bid)</span><br>'
    . '<textarea name="ias_parcels" rows="3" style="width:100%" placeholder="Parcel 1 | 45 acres | $250,000">' . esc_textarea((string) get_post_meta($id, '_ias_parcels', true)) . '</textarea></label></p>';
  echo '<p style="margin:4px 0"><label>Brochure cover <span class="description">(image or PDF id from the Media Library' . ($field_cover ? '; the brochure field has #' . (int) $field_cover[0] : '') . ')</span><br>'
    . '<input type="number" min="0" name="ias_brochure_cover" value="' . ($cover > 0 ? $cover : '') . '" style="width:100%" placeholder="' . ($field_cover ? (int) $field_cover[0] : 'none — a branded card stands in') . '"></label></p>';
  if ($cover > 0) {
    $thumb = wp_get_attachment_image_src($cover, 'thumbnail');
    if ($thumb) echo '<img src="' . esc_url((string) $thumb[0]) . '" alt="" style="max-width:80px;border:1px solid #dcdcde">';
  }
}, 10, 1);

if (!function_exists('ias_listing_panel_save')) {
  function ias_listing_panel_save($post_id): void {
    $post_id = (int) $post_id;
    if (!isset($_POST['ias_listing_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ias_listing_nonce'])), 'ias_listing_panel')) return;
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || !current_user_can('manage_options')) return;
    if (get_post_type($post_id) !== ia_schema_post_type()) return;

    $kind = sanitize_key(wp_unslash($_POST['ias_sale_kind'] ?? ''));
    if (in_array($kind, ['real_estate', 'liquidation'], true)) update_post_meta($post_id, '_ias_sale_kind', $kind);
    else delete_post_meta($post_id, '_ias_sale_kind');

    $parcels = trim(sanitize_textarea_field(wp_unslash($_POST['ias_parcels'] ?? '')));
    if ($parcels !== '') update_post_meta($post_id, '_ias_parcels', $parcels);
    else delete_post_meta($post_id, '_ias_parcels');

    $cover = (int) ($_POST['ias_brochure_cover'] ?? 0);
    if ($cover > 0 && get_post_type($cover) === 'attachment') update_post_meta($post_id, '_ias_brochure_cover', $cover);
    else delete_post_meta($post_id, '_ias_brochure_cover');
  }
}
add_action('save_post', 'ias_listing_panel_save', 20);
