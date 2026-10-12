<?php
/**
 * Test-only stand-in for what intelauctions.com registers itself (ACF CPTs,
 * taxonomies and fields). get_field() reads the meta ACF would have saved.
 */
add_action('init', function () {
  register_post_type('auction', ['public' => true, 'label' => 'Auctions', 'supports' => ['title', 'editor', 'thumbnail', 'excerpt'], 'taxonomies' => ['category'], 'show_in_menu' => true, 'has_archive' => true]);
  register_post_type('team-member', ['public' => true, 'label' => 'Team Members', 'supports' => ['title', 'thumbnail']]);
  register_post_type('town', ['public' => true, 'label' => 'Towns']);
  register_taxonomy('role', 'team-member', ['label' => 'Roles']);
  register_taxonomy_for_object_type('category', 'auction');
}, 0);
if (!function_exists('get_field')) {
  function get_field($name, $post_id = false, $format = true) {
    $post_id = $post_id ?: get_the_ID();
    $v = get_post_meta((int) $post_id, $name, true);
    if ($name === 'gallery' && is_string($v) && $v !== '') return array_map('intval', explode(',', $v));
    return $v;
  }
  function update_field($name, $value, $post_id = false) { return update_post_meta((int) $post_id, $name, $value); }
  function get_field_object($name, $post_id = false) {
    return metadata_exists('post', (int) $post_id, $name) ? ['key' => 'field_' . $name, 'name' => $name, 'type' => 'text'] : false;
  }
}
