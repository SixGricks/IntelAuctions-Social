<?php
/** Picking a post's photos (includes/photos.php). */
require_once IAS_PATH . 'includes/photos.php';

$g = [
  1 => ['q' => 9, 'kind' => 'aerial'], 2 => ['q' => 8, 'kind' => 'aerial'], 3 => ['q' => 7, 'kind' => 'exterior'],
  4 => ['q' => 9, 'kind' => 'interior'], 5 => ['q' => 6, 'kind' => 'interior'], 6 => ['q' => 7, 'kind' => 'barn'],
  7 => ['q' => 2, 'kind' => 'interior'], 8 => ['q' => 6, 'kind' => 'land'], 9 => ['q' => 5, 'kind' => 'interior'],
];
$l = ias_pick_photos($g, 'listing', []);
t_eq(1, $l[0], 'listing leads with the strongest aerial');
t_ok(!in_array(7, $l, true), 'a weak photo (2/10) is left out while better ones exist');
t_eq(['aerial', 'exterior', 'interior', 'barn', 'land'], array_values(array_unique(array_map(function ($id) use ($g) { return $g[$id]['kind']; }, array_slice($l, 0, 6)))), 'listing mixes kinds');

$o = ias_pick_photos($g, 'open_house', [1, 3, 4], [1, 3, 4]);
t_eq(5, $o[0], 'open house leads with an interior the listing post did not use');
t_ok(!array_intersect($o, [1, 3, 4]), 'open house skips the listing post\'s photos');
t_ok(count($o) >= 3 && count($o) <= 5, 'open house has 3–5 photos');

$a = ias_pick_photos($g, 'auction_week', [1, 4, 5]);
t_eq(2, $a[0], 'auction week leads with the strongest aerial not yet used');
$a = ias_pick_photos($g, 'auction_week', [1, 2]);
t_ok(!in_array($a[0], [1, 2], true), 'with every aerial used, it still leads with an unused photo');
t_eq([], ias_pick_photos([], 'listing', []), 'no photos, no picks');
t_eq(4, ias_listing_photo_budget(8, 1), '8 photos, one open house: the Listing post takes 4, leaving 3 + 2');
t_eq(10, ias_listing_photo_budget(30, 2), '30 photos: the Listing post takes 10');
t_eq(3, ias_listing_photo_budget(3, 1), 'only 3 photos: the Listing post takes all 3');
