<?php
/**
 * Intel Auctions Social — the pictures the plugin draws itself (GD).
 *
 *   Results graphic  — three layouts that take turns (0.26.0, DJ: "Create 2
 *                      other 'Sold' templates for images to switch between
 *                      them so they are not all identical"): *photo* — the
 *                      featured photo, a dark gradient up from the bottom,
 *                      "SOLD" and the sale price in white in the brand font,
 *                      the street and the date; *panel* — the photo above a
 *                      navy panel carrying "SOLD AT AUCTION", the price and the
 *                      place; *stamp* — the photo dimmed behind a framed navy
 *                      badge, "SOLD" over the price. Two or three same-day
 *                      sales share one picture, side by side (photo or panel;
 *                      a stamp is for one sale). 1080×1080.
 *   Roundup collage  — "New this week": the new listings' brochure covers on
 *                      Intel navy; a listing with no cover yet gets a branded
 *                      card (street, town, date) in its place. 1080×1080.
 *   Branded card     — that card on its own, also the cover for a late listing
 *                      with neither photos nor a brochure.
 *
 * The font is the brand font chosen under Settings (a .ttf/.otf in the Media
 * Library), else the bundled Inter (SIL Open Font License, assets/fonts/). The
 * files go to uploads/intel-social/graphics/ and are named after the post and
 * a hash of what they show, so a redraw with the same facts reuses the file.
 */

if (!defined('ABSPATH')) exit;

if (!defined('IAS_NAVY'))  define('IAS_NAVY', [35, 58, 102]);   // #233a66
if (!defined('IAS_DEEP'))  define('IAS_DEEP', [15, 29, 56]);    // #0f1d38
if (!defined('IAS_SLATE')) define('IAS_SLATE', [96, 128, 143]); // #60808f
if (!defined('IAS_MIST'))  define('IAS_MIST', [207, 224, 234]); // #cfe0ea

/** Can this server draw? (GD with FreeType; the `ias_gd_ready` filter can say no, as the tests do.) */
if (!function_exists('ias_gd_ready')) {
  function ias_gd_ready(): bool {
    return (bool) apply_filters('ias_gd_ready', function_exists('imagecreatetruecolor') && function_exists('imagettftext'));
  }
}

/** The font file for 'bold' (prices, headings) or 'medium' (lines of text). */
if (!function_exists('ias_font_path')) {
  function ias_font_path(string $weight = 'bold'): string {
    $brand = (int) ias_setting('brand_font_id', 0);
    if ($brand > 0) {
      $file = (string) get_attached_file($brand);
      if ($file !== '' && is_readable($file) && preg_match('/\.(ttf|otf)$/i', $file)) return $file;
    }
    return IAS_PATH . 'assets/fonts/' . ($weight === 'bold' ? 'InterDisplay-Bold.otf' : 'Inter-Medium.otf');
  }
}

/** Open an image file into GD (jpeg, png, webp, gif), or null. */
if (!function_exists('ias_gd_open')) {
  function ias_gd_open(string $path) {
    if ($path === '' || !is_readable($path)) return null;
    $info = @getimagesize($path);
    if (!$info) return null;
    switch ($info[2]) {
      case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($path); break;
      case IMAGETYPE_PNG:  $im = @imagecreatefrompng($path); break;
      case IMAGETYPE_GIF:  $im = @imagecreatefromgif($path); break;
      case IMAGETYPE_WEBP: $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
      default: $im = false;
    }
    return $im ?: null;
  }
}

/** Scale-and-crop $src to fill a $w×$h area of $dst at ($x, $y) (CSS object-fit: cover). */
if (!function_exists('ias_gd_cover')) {
  function ias_gd_cover($dst, $src, int $x, int $y, int $w, int $h): void {
    $sw = imagesx($src); $sh = imagesy($src);
    $scale = max($w / $sw, $h / $sh);
    $cw = (int) round($w / $scale); $ch = (int) round($h / $scale);
    imagecopyresampled($dst, $src, $x, $y, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $w, $h, $cw, $ch);
  }
}

/** Scale $src to fit inside a $w×$h area of $dst, centred (object-fit: contain). */
if (!function_exists('ias_gd_contain')) {
  function ias_gd_contain($dst, $src, int $x, int $y, int $w, int $h): void {
    $sw = imagesx($src); $sh = imagesy($src);
    $scale = min($w / $sw, $h / $sh);
    $dw = (int) round($sw * $scale); $dh = (int) round($sh * $scale);
    imagecopyresampled($dst, $src, $x + (int) (($w - $dw) / 2), $y + (int) (($h - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
  }
}

/** A vertical gradient of $rgb from transparent at $y0 to $alpha_end (0–1 opacity) at $y1. */
if (!function_exists('ias_gd_gradient')) {
  function ias_gd_gradient($im, int $x0, int $x1, int $y0, int $y1, array $rgb, float $alpha_end): void {
    imagealphablending($im, true);
    $span = max(1, $y1 - $y0);
    for ($y = $y0; $y <= $y1; $y++) {
      $t = ($y - $y0) / $span;
      $opacity = $alpha_end * (1 - (1 - $t) * (1 - $t)); // ease-out: the band under the text is the darkest
      $c = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], (int) round(127 * (1 - $opacity)));
      imageline($im, $x0, $y, $x1, $y, $c);
    }
  }
}

/** Width of $text at $size in $font. */
if (!function_exists('ias_gd_text_width')) {
  function ias_gd_text_width(string $text, string $font, float $size): int {
    $box = imagettfbbox($size, 0, $font, $text);
    return $box ? (int) abs($box[2] - $box[0]) : 0;
  }
}

/** The largest size ≤ $max at which $text fits $width (never below $min). */
if (!function_exists('ias_gd_fit')) {
  function ias_gd_fit(string $text, string $font, float $max, int $width, float $min = 12): float {
    $size = $max;
    while ($size > $min && ias_gd_text_width($text, $font, $size) > $width) $size -= 2;
    return $size;
  }
}

/** Shorten $text with "…" until it fits $width at $size. */
if (!function_exists('ias_gd_ellipsize')) {
  function ias_gd_ellipsize(string $text, string $font, float $size, int $width): string {
    if (ias_gd_text_width($text, $font, $size) <= $width) return $text;
    while (mb_strlen($text) > 1 && ias_gd_text_width($text . '…', $font, $size) > $width) $text = mb_substr($text, 0, -1);
    return rtrim($text) . '…';
  }
}

/** Word-wrap $text into lines no wider than $width (at most $max_lines, the last ellipsized). */
if (!function_exists('ias_gd_wrap')) {
  function ias_gd_wrap(string $text, string $font, float $size, int $width, int $max_lines = 3): array {
    $lines = []; $line = '';
    foreach (preg_split('/\s+/', trim($text)) as $word) {
      $try = $line === '' ? $word : $line . ' ' . $word;
      if ($line !== '' && ias_gd_text_width($try, $font, $size) > $width) { $lines[] = $line; $line = $word; }
      else $line = $try;
    }
    if ($line !== '') $lines[] = $line;
    if (count($lines) > $max_lines) {
      $lines = array_slice($lines, 0, $max_lines);
      $lines[$max_lines - 1] = ias_gd_ellipsize($lines[$max_lines - 1] . '…', $font, $size, $width);
    }
    return $lines;
  }
}

if (!function_exists('ias_gd_text')) {
  function ias_gd_text($im, string $text, string $font, float $size, int $x, int $baseline, array $rgb, float $opacity = 1.0): void {
    $c = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], (int) round(127 * (1 - $opacity)));
    imagettftext($im, $size, 0, $x, $baseline, $c, $font, $text);
  }
}

/** The small "INTEL AUCTIONS" mark in a corner. */
if (!function_exists('ias_gd_mark')) {
  function ias_gd_mark($im, int $x, int $y, float $size = 20): void {
    $font = ias_font_path('bold');
    $text = 'INTEL AUCTIONS';
    $w = ias_gd_text_width($text, $font, $size);
    $pad = (int) round($size * 0.7);
    $bg = imagecolorallocatealpha($im, IAS_NAVY[0], IAS_NAVY[1], IAS_NAVY[2], 10);
    imagefilledrectangle($im, $x, $y, $x + $w + 2 * $pad, $y + (int) round($size * 2.1), $bg);
    ias_gd_text($im, $text, $font, $size, $x + $pad, $y + (int) round($size * 1.5), [255, 255, 255]);
  }
}

/** Save a GD image as JPEG under graphics/; returns [path, url]. */
if (!function_exists('ias_gd_save')) {
  function ias_gd_save($im, string $name): array {
    [$dir, $url] = ias_upload_dir('graphics');
    $file = sanitize_file_name($name) . '.jpg';
    imageinterlace($im, true);
    imagejpeg($im, $dir . '/' . $file, 88);
    imagedestroy($im);
    return [$dir . '/' . $file, $url . '/' . $file];
  }
}

/** A plain photo or a navy fill when there is none. */
if (!function_exists('ias_gd_photo_or_navy')) {
  function ias_gd_photo_or_navy($dst, int $att, int $x, int $y, int $w, int $h): void {
    $src = $att > 0 ? ias_gd_open(ias_attachment_path($att)) : null;
    if ($src) {
      ias_gd_cover($dst, $src, $x, $y, $w, $h);
      imagedestroy($src);
      return;
    }
    imagefilledrectangle($dst, $x, $y, $x + $w - 1, $y + $h - 1, imagecolorallocate($dst, IAS_NAVY[0], IAS_NAVY[1], IAS_NAVY[2]));
  }
}

/** The Results graphic's layouts, in turn order: key => label. */
if (!function_exists('ias_sold_layouts')) {
  function ias_sold_layouts(): array {
    return ['photo' => 'Photo and price', 'panel' => 'Navy panel', 'stamp' => 'Sold badge'];
  }
}

/** A layout that can show $n sales: a stamp is drawn for one sale only (two or three get the panel). Pure. */
if (!function_exists('ias_sold_layout_for')) {
  function ias_sold_layout_for(string $layout, int $n): string {
    if (!isset(ias_sold_layouts()[$layout])) $layout = 'photo';
    return $layout === 'stamp' && $n > 1 ? 'panel' : $layout;
  }
}

/** Text centred on $cx. */
if (!function_exists('ias_gd_text_center')) {
  function ias_gd_text_center($im, string $text, string $font, float $size, int $cx, int $baseline, array $rgb, float $opacity = 1.0): void {
    ias_gd_text($im, $text, $font, $size, (int) round($cx - ias_gd_text_width($text, $font, $size) / 2), $baseline, $rgb, $opacity);
  }
}

/**
 * The Results graphic. $items: one to three sales, each
 * ['photo' => attachment id, 'price' => '$425,000', 'street' => …, 'place' => 'Narvon, PA'],
 * $sold_on 'November 18, 2026', $layout one of ias_sold_layouts(). Returns ['path','url','alt','layout']
 * or null without GD.
 */
if (!function_exists('ias_graphic_results')) {
  function ias_graphic_results(array $items, string $sold_on, string $name, string $layout = 'photo'): ?array {
    if (!ias_gd_ready() || !$items) return null;
    if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('image');
    $items = array_slice(array_values($items), 0, 3);
    $n = count($items);
    $layout = ias_sold_layout_for($layout, $n);
    if ($layout === 'panel') return ias_graphic_results_panel($items, $sold_on, $name);
    if ($layout === 'stamp') return ias_graphic_results_stamp($items[0], $sold_on, $name);
    $W = 1080; $H = 1080;
    $im = imagecreatetruecolor($W, $H);
    $bold = ias_font_path('bold');
    $med  = ias_font_path('medium');
    $strip = (int) floor($W / $n);

    foreach ($items as $i => $it) {
      $x = $i * $strip;
      $w = $i === $n - 1 ? $W - $x : $strip;
      ias_gd_photo_or_navy($im, (int) $it['photo'], $x, 0, $w, $H);
    }
    ias_gd_gradient($im, 0, $W, (int) ($H * 0.36), $H, IAS_DEEP, 0.9);
    if ($n > 1) {
      $line = imagecolorallocatealpha($im, 255, 255, 255, 40);
      for ($i = 1; $i < $n; $i++) imagefilledrectangle($im, $i * $strip - 2, 0, $i * $strip + 1, $H, $line);
    }

    $margin = $n === 1 ? 64 : 34;
    // One price size for every strip, so the prices and their SOLD labels line up.
    $psize = $n === 1 ? 150 : ($n === 2 ? 92 : 64);
    foreach ($items as $i => $it) {
      $width = ($i === $n - 1 ? $W - $i * $strip : $strip) - 2 * $margin;
      $psize = min($psize, ias_gd_fit((string) $it['price'], $bold, $psize, $width, 28));
    }
    foreach ($items as $i => $it) {
      $x = $i * $strip + $margin;
      $width = ($i === $n - 1 ? $W - $i * $strip : $strip) - 2 * $margin;
      $price = (string) $it['price'];
      $base = $n === 1 ? 905 : 900;
      $box = imagettfbbox($psize, 0, $bold, '$0123456789');
      $cap = $box ? (int) abs($box[7]) : (int) round($psize * 0.75); // height above the baseline
      ias_gd_text($im, 'SOLD', $bold, $n === 1 ? 34 : 26, $x, $base - $cap - ($n === 1 ? 26 : 18), IAS_MIST);
      ias_gd_text($im, $price, $bold, $psize, $x, $base, [255, 255, 255]);
      $ssize = $n === 1 ? 38 : 26;
      ias_gd_text($im, ias_gd_ellipsize((string) $it['street'], $med, $ssize, $width), $med, $ssize, $x, $n === 1 ? 975 : 955, [255, 255, 255]);
      $place = (string) ($it['place'] ?? '');
      if ($place !== '' && $n > 1) ias_gd_text($im, ias_gd_ellipsize($place, $med, 22, $width), $med, 22, $x, 995, IAS_MIST, 0.95);
    }
    ias_gd_mark($im, $n === 1 ? 64 : 34, 48, 20);
    if ($n === 1) {
      $foot = !empty($items[0]['place']) ? $items[0]['place'] . '  ·  Sold ' . $sold_on : 'Sold ' . $sold_on;
      ias_gd_text($im, ias_gd_ellipsize($foot, $med, 28, $W - 128), $med, 28, 64, 1025, IAS_MIST, 0.95);
    } elseif ($sold_on !== '') {
      // Shared sale day: under the mark, inside the first strip.
      $short = strtotime($sold_on) ? date('M j, Y', (int) strtotime($sold_on)) : $sold_on;
      $label = ias_gd_ellipsize('Sold ' . $short, $med, 22, $strip - 2 * $margin - 28);
      $w = ias_gd_text_width($label, $med, 22) + 28;
      imagefilledrectangle($im, 34, 98, 34 + $w, 138, imagecolorallocatealpha($im, IAS_DEEP[0], IAS_DEEP[1], IAS_DEEP[2], 20));
      ias_gd_text($im, $label, $med, 22, 48, 126, [255, 255, 255]);
    }

    [$path, $url] = ias_gd_save($im, $name);
    return ['path' => $path, 'url' => $url, 'alt' => ias_sold_alt($items, $sold_on), 'layout' => 'photo'];
  }
}

/** The Results graphic's alt text: the same words whatever the layout. Pure. */
if (!function_exists('ias_sold_alt')) {
  function ias_sold_alt(array $items, string $sold_on): string {
    return count($items) === 1
      ? 'Sold for ' . $items[0]['price'] . ': ' . $items[0]['street'] . (!empty($items[0]['place']) ? ', ' . $items[0]['place'] : '') . ($sold_on !== '' ? ', ' . $sold_on : '')
      : 'Sold ' . $sold_on . ': ' . implode('; ', array_map(function ($it) { return $it['street'] . ' for ' . $it['price']; }, $items));
  }
}

/** Results, *panel*: the photo(s) above a navy panel — "SOLD AT AUCTION", the price, the street and the place. */
if (!function_exists('ias_graphic_results_panel')) {
  function ias_graphic_results_panel(array $items, string $sold_on, string $name): array {
    $n = count($items);
    $W = 1080; $H = 1080; $top = 690;
    $im = imagecreatetruecolor($W, $H);
    $bold = ias_font_path('bold');
    $med  = ias_font_path('medium');
    $strip = (int) floor($W / $n);
    foreach ($items as $i => $it) {
      $x = $i * $strip;
      ias_gd_photo_or_navy($im, (int) $it['photo'], $x, 0, $i === $n - 1 ? $W - $x : $strip, $top);
    }
    imagefilledrectangle($im, 0, $top, $W, $H, imagecolorallocate($im, IAS_NAVY[0], IAS_NAVY[1], IAS_NAVY[2]));
    imagefilledrectangle($im, 0, $top, $W, $top + 9, imagecolorallocate($im, IAS_SLATE[0], IAS_SLATE[1], IAS_SLATE[2]));
    if ($n > 1) {
      $line = imagecolorallocatealpha($im, 255, 255, 255, 60);
      for ($i = 1; $i < $n; $i++) imagefilledrectangle($im, $i * $strip - 1, 0, $i * $strip, $H, $line);
    }
    $margin = $n === 1 ? 64 : 34;
    $psize = $n === 1 ? 116 : ($n === 2 ? 84 : 58);
    foreach ($items as $i => $it) $psize = min($psize, ias_gd_fit((string) $it['price'], $bold, $psize, ($i === $n - 1 ? $W - $i * $strip : $strip) - 2 * $margin, 28));
    foreach ($items as $i => $it) {
      $x = $i * $strip + $margin;
      $width = ($i === $n - 1 ? $W - $i * $strip : $strip) - 2 * $margin;
      ias_gd_text($im, $n === 1 ? 'SOLD AT AUCTION' : 'SOLD', $bold, $n === 1 ? 28 : 22, $x, $top + ($n === 1 ? 66 : 72), IAS_MIST);
      ias_gd_text($im, (string) $it['price'], $bold, $psize, $x, $top + ($n === 1 ? 222 : 175), [255, 255, 255]);
      $ssize = $n === 1 ? 36 : 25;
      ias_gd_text($im, ias_gd_ellipsize((string) $it['street'], $med, $ssize, $width), $med, $ssize, $x, $top + ($n === 1 ? 290 : 240), [255, 255, 255]);
      $place = (string) ($it['place'] ?? '');
      $line2 = $n === 1 ? trim($place . ($sold_on !== '' ? ($place !== '' ? '  ·  ' : '') . $sold_on : '')) : $place;
      if ($line2 !== '') ias_gd_text($im, ias_gd_ellipsize($line2, $med, $n === 1 ? 27 : 21, $width), $med, $n === 1 ? 27 : 21, $x, $top + ($n === 1 ? 342 : 285), IAS_MIST, 0.95);
    }
    ias_gd_mark($im, $n === 1 ? 64 : 34, 48, 20);
    if ($n > 1 && $sold_on !== '') ias_gd_text($im, ias_gd_ellipsize('Sold ' . $sold_on, $med, 21, $W - 68), $med, 21, 34, $H - 30, IAS_MIST, 0.95);
    [$path, $url] = ias_gd_save($im, $name);
    return ['path' => $path, 'url' => $url, 'alt' => ias_sold_alt($items, $sold_on), 'layout' => 'panel'];
  }
}

/** Results, *stamp* (one sale): the photo dimmed behind a framed navy badge — "SOLD" over the price — with the street below. */
if (!function_exists('ias_graphic_results_stamp')) {
  function ias_graphic_results_stamp(array $it, string $sold_on, string $name): array {
    $W = 1080; $H = 1080;
    $im = imagecreatetruecolor($W, $H);
    $bold = ias_font_path('bold');
    $med  = ias_font_path('medium');
    ias_gd_photo_or_navy($im, (int) $it['photo'], 0, 0, $W, $H);
    imagefilledrectangle($im, 0, 0, $W, $H, imagecolorallocatealpha($im, IAS_DEEP[0], IAS_DEEP[1], IAS_DEEP[2], 72)); // the photo dimmed, still there
    ias_gd_gradient($im, 0, $W, (int) ($H * 0.62), $H, IAS_DEEP, 0.85);
    // The badge: a navy box in a white frame, centred a little above the middle.
    [$bx0, $by0, $bx1, $by1] = [170, 250, 910, 690];
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefilledrectangle($im, $bx0 - 8, $by0 - 8, $bx1 + 8, $by1 + 8, $white);
    imagefilledrectangle($im, $bx0, $by0, $bx1, $by1, imagecolorallocatealpha($im, IAS_NAVY[0], IAS_NAVY[1], IAS_NAVY[2], 6));
    $cx = (int) ($W / 2);
    ias_gd_text_center($im, 'SOLD', $bold, 132, $cx, $by0 + 175, [255, 255, 255]);
    imagefilledrectangle($im, $cx - 150, $by0 + 214, $cx + 150, $by0 + 218, imagecolorallocate($im, IAS_SLATE[0], IAS_SLATE[1], IAS_SLATE[2]));
    $psize = ias_gd_fit((string) $it['price'], $bold, 104, $bx1 - $bx0 - 80, 36);
    ias_gd_text_center($im, (string) $it['price'], $bold, $psize, $cx, $by0 + 345, IAS_MIST);
    ias_gd_text_center($im, 'AT AUCTION', $med, 26, $cx, $by0 + 405, [255, 255, 255], 0.9);
    ias_gd_text_center($im, ias_gd_ellipsize((string) $it['street'], $med, 40, $W - 160), $med, 40, $cx, 880, [255, 255, 255]);
    $place = (string) ($it['place'] ?? '');
    $foot = trim($place . ($sold_on !== '' ? ($place !== '' ? '  ·  ' : '') . 'Sold ' . $sold_on : ''));
    if ($foot !== '') ias_gd_text_center($im, ias_gd_ellipsize($foot, $med, 27, $W - 160), $med, 27, $cx, 935, IAS_MIST, 0.95);
    ias_gd_mark($im, 64, 48, 20);
    [$path, $url] = ias_gd_save($im, $name);
    return ['path' => $path, 'url' => $url, 'alt' => ias_sold_alt([$it], $sold_on), 'layout' => 'stamp'];
  }
}

/** Draw the branded card for one listing into a $w×$h area of $im. */
if (!function_exists('ias_gd_card')) {
  function ias_gd_card($im, array $f, int $x, int $y, int $w, int $h): void {
    imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, imagecolorallocate($im, IAS_NAVY[0], IAS_NAVY[1], IAS_NAVY[2]));
    $edge = max(4, (int) round($w * 0.02));
    imagesetthickness($im, max(1, (int) round($w / 180)));
    imagerectangle($im, $x + $edge, $y + $edge, $x + $w - 1 - $edge, $y + $h - 1 - $edge, imagecolorallocate($im, IAS_SLATE[0], IAS_SLATE[1], IAS_SLATE[2]));
    imagesetthickness($im, 1);
    $bold = ias_font_path('bold');
    $med  = ias_font_path('medium');
    $pad = (int) round($w * 0.1);
    $inner = $w - 2 * $pad;
    $s1 = max(14, $w * 0.085);
    $lines = ias_gd_wrap((string) ($f['street'] !== '' ? $f['street'] : $f['title']), $bold, $s1, $inner, 3);
    $yy = $y + (int) round($h * 0.30);
    ias_gd_text($im, 'AUCTION', $bold, max(10, $w * 0.045), $x + $pad, $yy - (int) round($s1 * 1.6), IAS_MIST);
    foreach ($lines as $line) {
      ias_gd_text($im, $line, $bold, $s1, $x + $pad, $yy, [255, 255, 255]);
      $yy += (int) round($s1 * 1.3);
    }
    $s2 = max(11, $w * 0.055);
    $place = trim($f['town'] . ($f['state'] !== '' ? ', ' . $f['state'] : ''));
    if ($place !== '') { ias_gd_text($im, ias_gd_ellipsize($place, $med, $s2, $inner), $med, $s2, $x + $pad, $yy + (int) round($s2 * 0.6), IAS_MIST); $yy += (int) round($s2 * 1.6); }
    $date = $f['date'] instanceof DateTimeImmutable ? $f['date']->format('D, M j') : 'Date to be announced';
    ias_gd_text($im, $date, $bold, $s2, $x + $pad, $yy + (int) round($s2 * 0.8), [255, 255, 255]);
    $s3 = max(9, $w * 0.04);
    ias_gd_text($im, 'INTEL AUCTIONS', $bold, $s3, $x + $pad, $y + $h - $pad, IAS_MIST, 0.9);
  }
}

/** The branded card alone, $w×$h (a late listing's cover). */
if (!function_exists('ias_graphic_card')) {
  function ias_graphic_card(array $f, string $name, int $w = 1080, int $h = 1080): ?array {
    if (!ias_gd_ready()) return null;
    $im = imagecreatetruecolor($w, $h);
    ias_gd_card($im, $f, 0, 0, $w, $h);
    [$path, $url] = ias_gd_save($im, $name);
    return ['path' => $path, 'url' => $url, 'alt' => 'Auction: ' . ($f['street'] ?: $f['title']) . ($f['town'] !== '' ? ', ' . $f['town'] : '')];
  }
}

/**
 * The roundup collage: each listing's brochure cover (or the branded card) on
 * navy under a "NEW THIS WEEK" band. Up to 9 tiles; more shows "+N more".
 */
if (!function_exists('ias_graphic_collage')) {
  function ias_graphic_collage(array $facts_list, string $name): ?array {
    if (!ias_gd_ready() || !$facts_list) return null;
    if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('image');
    $W = 1080; $H = 1080; $head = 150; $gap = 18;
    $im = imagecreatetruecolor($W, $H);
    imagefilledrectangle($im, 0, 0, $W, $H, imagecolorallocate($im, IAS_DEEP[0], IAS_DEEP[1], IAS_DEEP[2]));
    $bold = ias_font_path('bold');
    $med  = ias_font_path('medium');
    ias_gd_text($im, 'NEW THIS WEEK', $bold, 58, 56, 98, [255, 255, 255]);
    $count = count($facts_list);
    ias_gd_text($im, $count . ' new ' . ($count === 1 ? 'auction' : 'auctions') . ' · intelauctions.com', $med, 24, 58, 134, IAS_MIST, 0.95);

    $show = array_slice($facts_list, 0, 9);
    $more = $count - count($show);
    $n = count($show);
    $cols = $n === 1 ? 1 : ($n === 2 || $n === 4 ? 2 : 3);
    $rows = (int) ceil($n / $cols);
    $area_w = $W - 2 * 40; $area_h = $H - $head - 40;
    $tw = (int) floor(($area_w - ($cols - 1) * $gap) / $cols);
    $th = (int) floor(($area_h - ($rows - 1) * $gap) / $rows);

    foreach ($show as $i => $f) {
      $c = $i % $cols; $r = intdiv($i, $cols);
      // Centre a short last row.
      $in_row = ($r === $rows - 1) ? $n - $r * $cols : $cols;
      $offset = (int) (($cols - $in_row) * ($tw + $gap) / 2);
      $x = 40 + $offset + $c * ($tw + $gap);
      $y = $head + $r * ($th + $gap);
      $cover = (int) ($f['brochure'] ?? 0);
      $src = $cover > 0 ? ias_gd_open(ias_attachment_path($cover)) : null;
      if ($src) {
        imagefilledrectangle($im, $x, $y, $x + $tw - 1, $y + $th - 1, imagecolorallocate($im, IAS_NAVY[0], IAS_NAVY[1], IAS_NAVY[2]));
        ias_gd_contain($im, $src, $x, $y, $tw, $th);
        imagedestroy($src);
      } else {
        ias_gd_card($im, $f, $x, $y, $tw, $th);
      }
      if ($more > 0 && $i === $n - 1) {
        imagefilledrectangle($im, $x, $y, $x + $tw - 1, $y + $th - 1, imagecolorallocatealpha($im, IAS_DEEP[0], IAS_DEEP[1], IAS_DEEP[2], 30));
        ias_gd_text($im, '+' . ($more + 1) . ' more', $bold, 44, $x + 30, $y + (int) ($th / 2) + 16, [255, 255, 255]);
      }
    }
    [$path, $url] = ias_gd_save($im, $name);
    $alt = 'New this week at Intel Auctions: ' . implode('; ', array_map(function ($f) {
      return trim(($f['street'] ?: $f['title']) . ($f['town'] !== '' ? ', ' . $f['town'] : ''));
    }, $facts_list));
    return ['path' => $path, 'url' => $url, 'alt' => mb_substr($alt, 0, 400)];
  }
}
