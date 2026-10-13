<?php
/**
 * Two open-house sessions on the same day share that day's Open House post
 * (the spec's "each open house date"), and the brief Claude writes it from
 * gives both sessions' times — neither is lost under the other.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
$twice = ias_t_listing(['title' => '14 Twice Road', 'address' => '14 Twice Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-21 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$400,000',
  'gallery' => [ias_t_photo('Twice 1'), ias_t_photo('Twice 2'), ias_t_photo('Twice 3')],
  'oh' => [['2026-11-14 16:00', 2], ['2026-11-14 10:00', 2]]]);
ias_sync();
$posts = array_values(array_filter(ias_posts_for_listing($twice), function ($p) { return $p['type'] === 'open_house'; }));
t_eq(1, count($posts), 'two sessions on Saturday: one Open House post');
$brief = ias_type_brief($posts[0], ias_post_facts($posts[0]));
t_ok(strpos($brief, 'Saturday, November 14, 10:00 am – 12:00 pm (2 Hours)') !== false, 'its brief gives the morning session with the date');
t_ok(strpos($brief, 'and again 4:00 pm – 6:00 pm (2 Hours)') !== false, '…and the afternoon session after it');
t_ok(strpos($brief, 'both sessions') !== false, '…and asks for both time ranges');
