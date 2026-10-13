<?php
/**
 * Intel Auctions Social — photo grading, picking and crops.
 *
 * Grading: the first time a listing's photos are needed, Claude looks at each
 * one once (in batches of 8, at the small "grade size" so it costs little)
 * and scores it 1–10 for quality, tags its kind — aerial, exterior, interior,
 * barn (barn or outbuilding), land, other — and writes alt text from what it
 * sees. The grade lives on the attachment (`_ias_photo`), so it is never paid
 * for twice, whichever post asks.
 *
 * Picking: every post about a property leads with a photo that property has
 * not used yet (the `image` history), so nobody sees the same aerial twice.
 * Listing posts mix kinds (up to 10); Open House posts favour interiors and
 * skip the listing post's photos (3–5); Auction Week posts lead with the
 * strongest unused aerial.
 *
 * Crops: every chosen image is saved in three crops — landscape 1200×628,
 * square 1080×1080, vertical 1080×1350 — under uploads/intel-social/crops/,
 * so Instagram later is a new renderer, not a season of re-cropping.
 */

if (!defined('ABSPATH')) exit;

if (!defined('IAS_PHOTO_KINDS')) define('IAS_PHOTO_KINDS', ['aerial', 'exterior', 'interior', 'barn', 'land', 'other']);

/** A photo's grade: ['q' => 1–10, 'kind' => …, 'alt' => …] or null when not graded yet. */
if (!function_exists('ias_photo_grade')) {
  function ias_photo_grade(int $att): ?array {
    $g = get_post_meta($att, '_ias_photo', true);
    return (is_array($g) && isset($g['q'], $g['kind'])) ? $g : null;
  }
}

/** Where generated pictures live: [dir path, base url], created on first use. */
if (!function_exists('ias_upload_dir')) {
  function ias_upload_dir(string $sub): array {
    $up = wp_upload_dir(null, false);
    $dir = trailingslashit((string) $up['basedir']) . 'intel-social/' . $sub;
    $url = trailingslashit((string) $up['baseurl']) . 'intel-social/' . $sub;
    if (!is_dir($dir)) wp_mkdir_p($dir);
    return [$dir, $url];
  }
}

/**
 * A local file for an attachment at the first size in $sizes that exists
 * (works for PDFs too: WordPress keeps a preview image per size). '' when none.
 */
if (!function_exists('ias_attachment_path')) {
  function ias_attachment_path(int $att, array $sizes = ['1536x1536', 'large', 'full']): string {
    $file = (string) get_attached_file($att);
    if ($file === '') return '';
    $meta = wp_get_attachment_metadata($att);
    $dir  = dirname($file);
    foreach ($sizes as $size) {
      if ($size === 'full') {
        $is_image = strpos((string) get_post_mime_type($att), 'image/') === 0;
        if ($is_image && is_readable($file)) return $file;
        if (!empty($meta['sizes']['full']['file']) && is_readable($dir . '/' . $meta['sizes']['full']['file'])) return $dir . '/' . $meta['sizes']['full']['file'];
        continue;
      }
      if (!empty($meta['sizes'][$size]['file']) && is_readable($dir . '/' . $meta['sizes'][$size]['file'])) {
        return $dir . '/' . $meta['sizes'][$size]['file'];
      }
    }
    return '';
  }
}

/** The public URL of an attachment at a size (PDF previews included). */
if (!function_exists('ias_attachment_url')) {
  function ias_attachment_url(int $att, string $size = 'full'): string {
    $src = wp_get_attachment_image_src($att, $size);
    if (is_array($src) && !empty($src[0])) return (string) $src[0];
    return (string) wp_get_attachment_url($att);
  }
}

/**
 * One image as a Messages API block: inline (base64) from the local file
 * when it is small enough — no fetch from Anthropic's side, so it works behind
 * Cloudflare and on staging — else by URL.
 */
if (!function_exists('ias_image_block')) {
  function ias_image_block(int $att, string $size): ?array {
    $path = ias_attachment_path($att, [$size, 'medium', 'large']);
    if ($path !== '' && filesize($path) > 0 && filesize($path) < 3 * 1024 * 1024) {
      $type = wp_check_filetype($path)['type'] ?? '';
      if (in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $type, 'data' => base64_encode((string) file_get_contents($path))]];
      }
    }
    $url = ias_attachment_url($att, $size);
    return $url !== '' ? ['type' => 'image', 'source' => ['type' => 'url', 'url' => $url]] : null;
  }
}

/**
 * Grade the listing's ungraded photos (at most $max_calls batches of 8 this
 * time; the rest wait for the next run). Returns ['graded' => n, 'left' => n, 'error' => ''].
 */
if (!function_exists('ias_photos_grade')) {
  function ias_photos_grade(array $f, int $max_calls = 3): array {
    $todo = array_values(array_filter((array) $f['photos'], function ($att) {
      return ias_photo_grade((int) $att) === null && wp_attachment_is_image((int) $att);
    }));
    $todo = array_slice($todo, 0, 40); // a big gallery: the first 40 are plenty to choose from
    $out = ['graded' => 0, 'left' => count($todo), 'error' => ''];
    if (!$todo) return $out;
    $why = ias_ai_blocker();
    if ($why !== '') { $out['error'] = $why; return $out; } // said once on every Social screen, not logged per listing
    $err_at = (int) get_post_meta((int) $f['id'], '_ias_grade_error_at', true);
    if ($err_at > time() - 6 * HOUR_IN_SECONDS) {
      $out['error'] = 'Grading failed less than 6 hours ago; waiting before paying for another try.';
      return $out;
    }

    $size = (string) ias_setting('grade_size', 'medium');
    foreach (array_slice(array_chunk($todo, 8), 0, max(1, $max_calls)) as $batch) {
      $blocks = [];
      $map = [];
      foreach ($batch as $n => $att) {
        $img = ias_image_block((int) $att, $size);
        if (!$img) continue;
        $map[$n + 1] = (int) $att;
        $blocks[] = ['type' => 'text', 'text' => 'Photo ' . ($n + 1) . ':'];
        $blocks[] = $img;
      }
      if (!$map) continue;
      $blocks[] = ['type' => 'text', 'text' => "These are photos of one property listed for auction: " . $f['title'] . ($f['town'] !== '' ? ' in ' . $f['town'] : '') . ".\n"
        . "For each photo give:\n"
        . "- quality: 1–10 for a Facebook post (sharp, well lit, level, uncluttered, shows the property well; lower for blur, darkness, tilt, clutter, people or vehicles in focus, screenshots or text-heavy graphics)\n"
        . "- kind: one of aerial (drone or overhead), exterior (the house or main building from the ground), interior, barn (barn or any outbuilding), land (fields, woods, water, views), other (maps, documents, graphics, close-ups)\n"
        . "- alt: alt text of at most 120 characters saying only what is visible. Never describe or guess at people.\n"
        . 'Answer with JSON only: [{"n":1,"quality":7,"kind":"aerial","alt":"…"}, …] — one object per photo, in order.'];

      $raw = ia_ai_generate('You grade property photos for a Pennsylvania auction company\'s Facebook page. Output JSON only.', $blocks, 1500, 'social.photos');
      if (is_wp_error($raw)) {
        update_post_meta((int) $f['id'], '_ias_grade_error_at', time());
        $out['error'] = $raw->get_error_message();
        ias_log('gen_failed', (int) $f['id'], ['summary' => 'Photo grading failed: ' . $raw->get_error_message()]);
        return $out;
      }
      $rows = ias_json_from_text((string) $raw);
      $done = 0;
      foreach ((array) $rows as $row) {
        $n = (int) ($row['n'] ?? 0);
        if (!isset($map[$n])) continue;
        $kind = strtolower(trim((string) ($row['kind'] ?? 'other')));
        if (!in_array($kind, IAS_PHOTO_KINDS, true)) $kind = 'other';
        update_post_meta($map[$n], '_ias_photo', [
          'q'    => max(1, min(10, (int) ($row['quality'] ?? 5))),
          'kind' => $kind,
          'alt'  => mb_substr(trim(wp_strip_all_tags((string) ($row['alt'] ?? ''))), 0, 160),
          'at'   => ias_now_sql(),
        ]);
        $done++;
      }
      $out['graded'] += $done;
      $out['left'] -= $done;
      if ($done === 0) {
        update_post_meta((int) $f['id'], '_ias_grade_error_at', time());
        $out['error'] = 'Claude\'s answer could not be read as grades.';
        return $out;
      }
    }
    if ($out['graded']) ias_log('graded', (int) $f['id'], ['summary' => $out['graded'] . ' photos graded', 'left' => $out['left']]);
    delete_post_meta((int) $f['id'], '_ias_grade_error_at');
    return $out;
  }
}

/** A photo's alt text: Claude's from grading, then the attachment's own, then the listing's name. */
if (!function_exists('ias_photo_alt')) {
  function ias_photo_alt(int $att, array $f): string {
    $g = ias_photo_grade($att);
    if ($g && trim((string) $g['alt']) !== '') return (string) $g['alt'];
    $alt = trim((string) get_post_meta($att, '_wp_attachment_image_alt', true));
    return $alt !== '' ? $alt : 'Photo of ' . $f['title'];
  }
}

/** Image ids this listing's posts have already used (all time — "nobody sees the same aerial twice"). */
if (!function_exists('ias_listing_used_images')) {
  function ias_listing_used_images(int $listing_id, int $except_post = 0): array {
    if (!ias_db_ready() || $listing_id <= 0) return [];
    global $wpdb;
    $rows = $wpdb->get_col($wpdb->prepare(
      'SELECT value FROM ' . ias_table('history') . " WHERE kind = 'image' AND listing_id = %d AND post_id <> %d",
      $listing_id, $except_post
    ));
    return array_values(array_unique(array_map('intval', (array) $rows)));
  }
}

/**
 * Choose a post's photos from one listing. Pure apart from the grades it is
 * handed: $photos = [id => ['q' =>, 'kind' =>]], $used = ids this property
 * has used, $avoid = ids this post must not use at all (the listing post's,
 * for an open house), $opts['max'] = most photos for a listing post. Returns
 * ordered ids; the first is the lead and is never a used one while an unused
 * photo exists.
 */
if (!function_exists('ias_pick_photos')) {
  function ias_pick_photos(array $photos, string $type, array $used, array $avoid = [], array $opts = []): array {
    $pool = [];
    foreach ($photos as $id => $g) {
      if (in_array((int) $id, $avoid, true)) continue;
      $pool[(int) $id] = ['q' => (int) ($g['q'] ?? 5), 'kind' => (string) ($g['kind'] ?? 'other'), 'used' => in_array((int) $id, $used, true)];
    }
    if (!$pool) return [];
    // Weak photos only when nothing better is left.
    $good = array_filter($pool, function ($p) { return $p['q'] > 3; });
    if (count($good) >= 3) $pool = $good;

    $rank = function (array $kinds) use (&$pool) {
      $ids = array_keys($pool);
      usort($ids, function ($a, $b) use ($pool, $kinds) {
        $ka = array_search($pool[$a]['kind'], $kinds, true); $ka = $ka === false ? 99 : $ka;
        $kb = array_search($pool[$b]['kind'], $kinds, true); $kb = $kb === false ? 99 : $kb;
        return [$pool[$a]['used'], $ka, -$pool[$a]['q'], $a] <=> [$pool[$b]['used'], $kb, -$pool[$b]['q'], $b];
      });
      return $ids;
    };

    if ($type === 'open_house') {
      $max = max(1, min(5, (int) ($opts['max'] ?? 5)));
      $order = $rank(['interior', 'exterior', 'barn', 'land', 'aerial', 'other']);
    } elseif ($type === 'auction_week') {
      $max = 4;
      $order = $rank(['aerial', 'exterior', 'land', 'barn', 'interior', 'other']);
    } else { // listing: the strongest unused aerial or exterior first, then one of each kind in turn
      $max = max(1, min(10, (int) ($opts['max'] ?? 10)));
      $lead = $rank(['aerial', 'exterior', 'land', 'barn', 'interior', 'other'])[0];
      $by_kind = [];
      foreach ($rank(IAS_PHOTO_KINDS) as $id) {
        if ($id !== $lead) $by_kind[$pool[$id]['kind']][] = $id;
      }
      foreach ($by_kind as $k => $ids) {
        usort($ids, function ($a, $b) use ($pool) { return [$pool[$a]['used'], -$pool[$a]['q'], $a] <=> [$pool[$b]['used'], -$pool[$b]['q'], $b]; });
        $by_kind[$k] = $ids;
      }
      $order = [$lead];
      while (count($order) < $max && array_filter($by_kind)) {
        foreach (['exterior', 'interior', 'aerial', 'barn', 'land', 'other'] as $k) {
          if (!empty($by_kind[$k])) $order[] = array_shift($by_kind[$k]);
          if (count($order) >= $max) break;
        }
      }
      return $order;
    }

    // Fresh photos first; used ones only to fill out a short post.
    $fresh = array_values(array_filter($order, function ($id) use ($pool) { return !$pool[$id]['used']; }));
    $stale = array_values(array_filter($order, function ($id) use ($pool) { return $pool[$id]['used']; }));
    $min = $type === 'open_house' ? 3 : 2;
    $pick = array_slice($fresh, 0, $max);
    if (count($pick) < $min) $pick = array_merge($pick, array_slice($stale, 0, $min - count($pick)));
    return $pick;
  }
}

/**
 * Three crops of one image (landscape 1200×628, square 1080×1080, vertical
 * 1080×1350), made once and kept until the source file changes. Returns
 * name => url ([] when the editor cannot open the file).
 */
if (!function_exists('ias_photo_crops')) {
  function ias_photo_crops(int $att): array {
    $src = ias_attachment_path($att, ['full', '2048x2048', '1536x1536', 'large']);
    if ($src === '') return [];
    $mtime = (int) @filemtime($src);
    $have = get_post_meta($att, '_ias_crops', true);
    if (is_array($have) && (int) ($have['mtime'] ?? 0) === $mtime && !empty($have['urls'])) return (array) $have['urls'];

    [$dir, $url] = ias_upload_dir('crops');
    $urls = [];
    foreach (['landscape' => [1200, 628], 'square' => [1080, 1080], 'vertical' => [1080, 1350]] as $name => [$w, $h]) {
      $ed = wp_get_image_editor($src);
      if (is_wp_error($ed)) return [];
      $ed->set_quality(86);
      $size = $ed->get_size();
      // Crop to the aspect first (centred), then scale — never upscale a small photo past its pixels.
      $sw = (int) $size['width']; $sh = (int) $size['height'];
      $ratio = $w / $h;
      if ($sw / max(1, $sh) > $ratio) { $cw = (int) round($sh * $ratio); $ch = $sh; }
      else { $cw = $sw; $ch = (int) round($sw / $ratio); }
      $ed->crop((int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $cw, $ch, min($w, $cw), min($h, $ch));
      $file = $att . '-' . $name . '.jpg';
      $saved = $ed->save($dir . '/' . $file, 'image/jpeg');
      if (is_wp_error($saved)) return [];
      $urls[$name] = $url . '/' . $file;
    }
    update_post_meta($att, '_ias_crops', ['mtime' => $mtime, 'urls' => $urls]);
    return $urls;
  }
}

/** Grades for a set of photo ids, ungraded ones as a middling "other". */
if (!function_exists('ias_photo_grades')) {
  function ias_photo_grades(array $ids): array {
    $out = [];
    foreach ($ids as $id) {
      $g = ias_photo_grade((int) $id);
      $out[(int) $id] = $g ?: ['q' => 5, 'kind' => 'other'];
    }
    return $out;
  }
}

/** Pull the first JSON array or object out of a model's reply (code fences and chatter tolerated). */
if (!function_exists('ias_json_from_text')) {
  function ias_json_from_text(string $text) {
    $text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)));
    $decoded = json_decode($text, true);
    if ($decoded !== null) return $decoded;
    foreach ([['[', ']'], ['{', '}']] as [$open, $close]) {
      $a = strpos($text, $open);
      $b = strrpos($text, $close);
      if ($a !== false && $b !== false && $b > $a) {
        $decoded = json_decode(substr($text, $a, $b - $a + 1), true);
        if ($decoded !== null) return $decoded;
      }
    }
    return null;
  }
}

/**
 * How many photos the Listing post may take and still leave fresh ones for
 * the property's later posts (3 for each open house, 2 for auction week):
 * never more than 10, never fewer than 4 while there are 4.
 */
if (!function_exists('ias_listing_photo_budget')) {
  function ias_listing_photo_budget(int $photos, int $open_houses): int {
    $reserve = 3 * max(0, $open_houses) + 2;
    return max(min(4, $photos), min(10, $photos - $reserve));
  }
}
