<?php
/**
 * Three Sold pictures that take turns (0.26.0, DJ, Oct 15, 2026: "Create 2 other 'Sold' templates for images to
 * switch between them so they are not all identical").
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/content.php';
require_once IAS_PATH . 'includes/graphics.php';

t_eq(['photo', 'panel', 'stamp'], array_keys(ias_sold_layouts()), 'three Sold layouts: photo and price, navy panel, sold badge');
t_eq('stamp', ias_sold_layout_for('stamp', 1), 'the badge is for one sale');
t_eq('panel', ias_sold_layout_for('stamp', 2), '…two or three same-day sales get the panel instead');
t_eq('photo', ias_sold_layout_for('nonsense', 1), 'an unknown layout is the first one');

// In turn: each new picture takes the least used, never the last one's.
$hist = [];
$seq = [];
for ($i = 0; $i < 9; $i++) {
  $l = ias_choose_sold_layout(array_count_values($hist), (string) ($hist[0] ?? ''), 1);
  array_unshift($hist, $l);
  $seq[] = $l;
}
t_eq(['photo', 'panel', 'stamp', 'photo', 'panel', 'stamp', 'photo', 'panel', 'stamp'], $seq, 'nine Results pictures in a row: the three take turns, never two alike in a row');
t_eq('panel', ias_choose_sold_layout(['photo' => 1, 'panel' => 1, 'stamp' => 0], 'photo', 2), 'two sales: never the badge, and not the last one\'s look');
t_eq('stamp', ias_choose_sold_layout(['stamp' => 5], 'stamp', 1, 'stamp'), 'a post rewritten keeps the look its picture had');
t_eq('photo', ias_choose_sold_layout([], '', 3, 'stamp'), '…unless it can no longer take it (it now shows three sales)');
