<?php
/**
 * From DJ's first look at a real draft (Narvon Country Home, Oct 13): the
 * Listing post opened with two near-identical front exteriors; four of its
 * ten pictures were ones Claude had not graded yet (they carried the site's
 * SEO alt text); and the sale's date with no time reached Claude as
 * "12:00 am", which held the draft. Real JPEGs through GD here.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30]);

// ---- The same view twice ----
$front = ias_t_photo('Narvon front');
$twin  = ias_t_photo_twin($front, 'Narvon front again');
$other = ias_t_photo('Narvon kitchen');
$hf = ias_photo_dhash($front);
t_ok((bool) preg_match('/^[0-9a-f]{16}$/', $hf), 'a photo gets a fingerprint (' . $hf . ')');
t_eq($hf, (string) get_post_meta($front, '_ias_dhash', true), '…kept, so it is worked out once');
t_ok(ias_dhash_same_view($hf, ias_photo_dhash($twin)), 'a second shot of the same view, a step over and brighter, is the same view (' . ias_dhash_distance($hf, ias_photo_dhash($twin)) . ' bits apart)');
t_ok(!ias_dhash_same_view($hf, ias_photo_dhash($other)), 'a different picture is not (' . ias_dhash_distance($hf, ias_photo_dhash($other)) . ' bits apart)');

$gal = [$front, $twin, $other];
for ($i = 1; $i <= 7; $i++) $gal[] = ias_t_photo('Narvon ' . $i);
$n = ias_t_listing(['title' => 'Narvon Country Home', 'address' => '5757 Division Hwy, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 00:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$275,000', 'gallery' => $gal]);
ias_sync();
ias_drafts_run(30);
$lp = ias_post_by_slot('listing:' . $n);
$ids = array_map('intval', (array) $lp['images_used']);
t_ok(count($ids) >= 3, 'the Listing post has its pictures (' . count($ids) . ')');
t_ok(!(in_array($front, $ids, true) && in_array($twin, $ids, true)), 'it never carries both shots of the same view');

// ---- Only graded photos when the gallery is graded in part ----
$big = [];
for ($i = 1; $i <= 22; $i++) $big[] = ias_t_photo('Big gallery ' . $i);
$b = ias_t_listing(['title' => '9 Big Gallery Road', 'address' => '9 Big Gallery Road, Gap, PA 17527, USA', 'town' => 'Gap', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-27 11:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$400,000', 'gallery' => $big]);
ias_photos_grade(ias_listing_facts($b), 1); // one batch: the first 8 of 22
$graded = array_values(array_filter($big, function ($att) { return ias_photo_grade((int) $att) !== null; }));
t_eq(8, count($graded), 'one grading call grades the first 8 of a 22-photo gallery');
$pool = array_keys(ias_photo_grades($big));
sort($pool);
t_eq($graded, $pool, 'picking chooses among the 8 graded photos only (their kind and alt text are known), not the 14 ungraded');
t_eq(3, count(ias_photo_grades(array_slice($big, 8, 3))), 'a gallery with none graded yet is still picked from');

// ---- A date with no time is not "12:00 am" ----
$facts = ias_facts_for_prompt(ias_listing_facts($n));
t_eq('', $facts['auction_time'] ?? '', 'a sale with a date and no time: no auction time is given to Claude');
t_eq('Saturday, November 28, 2026', $facts['auction_date'] ?? '', '…the date still is');
t_eq('11:00 am', ias_facts_for_prompt(ias_listing_facts($b))['auction_time'] ?? '', 'a sale with a time keeps it');
t_ok(strpos((string) $lp['content']['text'], '12:00 am') === false, 'the draft does not say 12:00 am');

ias_t_claude_off();
