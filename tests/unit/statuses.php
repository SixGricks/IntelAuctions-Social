<?php
/**
 * The calendar key (0.21.0, DJ, Oct 14, 2026: "Pushed and Published should not
 * match. Dropped, skipped, and Human should not match … red for dropped.
 * Yellow for needs a decision."): one colour per status, readable.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/post-types.php';

$st = ias_statuses();
$col = function (string $k, int $i) use ($st) { return strtolower((string) ($st[$k]['color'][$i] ?? '')); };
t_ok(!array_filter($st, function ($s) { return empty($s['color']) || count($s['color']) !== 3; }), 'every status has its colours: line, fill and text');
$lines = array_map(function ($s) { return strtolower($s['color'][0]); }, $st);
t_eq(count($lines), count(array_unique($lines)), 'no two statuses share a colour');
t_ok($col('pushed', 1) !== $col('published', 1) && $col('pushed', 0) !== $col('published', 0), 'Pushed and Published do not match');
t_ok(count(array_unique([$col('dropped', 0), $col('skipped', 0), $col('human', 0)])) === 3 && count(array_unique([$col('dropped', 1), $col('skipped', 1), $col('human', 1)])) === 3, 'Dropped, Skipped and Human do not match');
t_eq('#d63638', $col('dropped', 0), 'Dropped is red');
t_eq('#dba617', $col('held', 0), 'Needs a decision is yellow');
t_eq('#233a66', $col('published', 1), 'Published is Intel navy, solid');

// Readable: the words on their fill at least 4.5:1 (WCAG AA for small text).
$lum = function (string $hex) {
  $c = array_map(function ($h) { $v = hexdec($h) / 255; return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); }, str_split(ltrim($hex, '#'), 2));
  return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$low = [];
foreach ($st as $k => $s) {
  [$a, $b] = [$lum($s['color'][2]), $lum($s['color'][1])];
  $ratio = (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
  if ($ratio < 4.5) $low[] = $k . ' ' . round($ratio, 2);
}
t_eq([], $low, 'every status\'s words are readable on its fill (4.5:1 or better)');

$css = ias_status_chip_css();
t_ok(strpos($css, '.ias-chip.st-dropped{border-color:#d63638;background:#fcf0f1;color:#a51d1f;text-decoration:line-through}') !== false, 'a dropped post on the calendar: red, struck through');
t_ok(strpos($css, '.ias-chip.st-published{border-color:#233a66;background:#233a66;color:#ffffff}') !== false, 'a published one: solid navy, not struck');
t_ok(strpos($css, '.ias-chip.st-human{border:1px solid') !== false && strpos($css, '.ias-chip.st-notsent{border-left-style:dashed}') !== false, 'a person\'s own post outlined; Not sent dashed');
t_ok(strpos(ias_status_badge_style('held'), 'background:#fcf6dc;color:#6b5200') === 0, 'a badge carries its colours inline (so it looks the same on any screen)');
