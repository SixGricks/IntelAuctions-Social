<?php
/**
 * Intel Auctions Social — the kinds of post and the states a post can be in.
 *
 * The post types and their rules come straight from the build spec's table:
 * weight (settings can tune it), whether the post is guaranteed (slides
 * within its window, never dropped), which lane it takes (A: approve first by
 * email; B: scheduled with a 48-hour veto window) and how its window is set.
 */

if (!defined('ABSPATH')) exit;

/*
 * One name per type everywhere a person reads it (0.27.0, from the UI review:
 * the calendar, Settings and the Log each had their own). `short` is kept as a
 * key because the screens read it; it now says the same as `label`.
 */
if (!function_exists('ias_post_types')) {
  function ias_post_types(): array {
    return (array) apply_filters('ias_post_types', [
      'roundup' => [
        'label' => 'New This Week', 'short' => 'New This Week', 'lane' => 'A', 'guaranteed' => true, 'droppable' => false,
        'window' => 'Friday → Friday → Saturday',
        'what'   => 'Every Friday that a listing entered Recently Announced that week: one post naming every new sale, with a collage of their brochure covers.',
      ],
      'listing' => [
        'label' => 'Listing', 'short' => 'Listing', 'lane' => 'B', 'guaranteed' => true, 'droppable' => false,
        'window' => 'promotion day → same day → +7 days',
        'what'   => 'When a listing moves to Upcoming (and has photos): up to 10 photos, the key details, open houses, auction type, the lead and their phone.',
      ],
      'open_house' => [
        'label' => 'Open House', 'short' => 'Open House', 'lane' => 'B', 'guaranteed' => false, 'droppable' => true,
        'window' => '7 days before → 5 → 3',
        'what'   => 'Each open house: the property video when there is one, otherwise 3–5 photos the listing post did not use; the time range and how long it runs.',
      ],
      'auction_week' => [
        'label' => 'Auction Week', 'short' => 'Auction Week', 'lane' => 'B', 'guaranteed' => false, 'droppable' => true,
        'window' => '10 days before → 7 → 3',
        'what'   => 'The sale itself: different photos, an urgency angle, the auction time and how bidding works. Same-day sales share one post.',
      ],
      'results' => [
        'label' => 'Results', 'short' => 'Results', 'lane' => 'A', 'guaranteed' => true, 'droppable' => false,
        'window' => 'next day → next day → whenever approved',
        'what'   => 'When a listing moves to Past with a sale price: the featured photo with the price over it, the name, address, date and result. Liquidations get a thank-you post with no price.',
      ],
      'manual' => [
        'label' => 'Your post', 'short' => 'Your post', 'lane' => '', 'guaranteed' => true, 'droppable' => false,
        'window' => 'where a person puts it',
        'what'   => 'Written on the Social Calendar by a person (New post): their words, or a draft Claude wrote for them to check, on the networks they tick. Pinned where they put it; the plan counts it and works around it, and when it stands for one of a listing\'s own posts that post is turned off.',
      ],
      'human' => [
        'label' => 'Human post', 'short' => 'Human post', 'lane' => '', 'guaranteed' => true, 'droppable' => false,
        'window' => '—',
        'what'   => 'Posted by a person straight to the page or in OneUp. Never moved or dropped; the plan works around it.',
      ],
    ]);
  }
}

/** One type's definition, with its weight from the settings. */
if (!function_exists('ias_post_type')) {
  function ias_post_type(string $type): array {
    $types = ias_post_types();
    $def = $types[$type] ?? ['label' => ucfirst($type), 'short' => ucfirst($type), 'lane' => 'B', 'guaranteed' => false, 'droppable' => true, 'window' => '', 'what' => ''];
    $weights = (array) ias_setting('weights', []);
    $def['weight'] = isset($weights[$type]) ? (int) $weights[$type] : 0;
    return $def;
  }
}

/** "Listing post", "Your post" — a type's name as a noun, never "Your post post". */
if (!function_exists('ias_post_noun')) {
  function ias_post_noun(string $type): string {
    $short = (string) ias_post_type($type)['short'];
    return preg_match('/\bpost$/i', $short) ? $short : $short . ' post';
  }
}

/**
 * Post states, in the calendar's words, with the badge colour each wears.
 * planned → (Lane A) waiting → approved → pushed → published, or
 * (Lane B) planned → pushed → published; and the ways out: held (stopped for
 * a person), dropped (no room), skipped (a person said no), cancelled
 * (listing unpublished or deleted, or Cancel posts), failed (OneUp refused).
 */
if (!function_exists('ias_statuses')) {
  function ias_statuses(): array {
    // One colour per status (0.21.0, DJ, Oct 14, 2026: "Pushed and Published should not match. Dropped, skipped,
    // and Human should not match … red for dropped. Yellow for needs a decision"): [line, fill, text], used by the
    // calendar's posts and every status badge alike. `off` = it will not go out (struck through on the calendar).
    return [
      'planned'   => ['label' => 'Planned',              'badge' => 'info',  'color' => ['#2271b1', '#f0f6fc', '#135e96']], // blue
      'held'      => ['label' => 'Needs a decision',     'badge' => 'warn',  'color' => ['#dba617', '#fcf6dc', '#6b5200']], // yellow
      'waiting'   => ['label' => 'Waiting for approval', 'badge' => 'info',  'color' => ['#7b3fc0', '#f5effc', '#5b2a96']], // purple
      'approved'  => ['label' => 'Approved',             'badge' => 'ok',    'color' => ['#0a7f86', '#e6f6f7', '#06656b']], // teal
      'pushed'    => ['label' => 'Pushed',               'badge' => 'ok',    'color' => ['#00a32a', '#edfaef', '#00661a']], // light green
      'published' => ['label' => 'Published',            'badge' => 'ok',    'color' => ['#233a66', '#233a66', '#ffffff']], // Intel navy, solid
      'dropped'   => ['label' => 'Dropped',              'badge' => 'bad',   'color' => ['#d63638', '#fcf0f1', '#a51d1f'], 'off' => true], // red
      'skipped'   => ['label' => 'Skipped',              'badge' => 'muted', 'color' => ['#8c8f94', '#f6f7f7', '#50575e'], 'off' => true], // grey
      'cancelled' => ['label' => 'Cancelled',            'badge' => 'muted', 'color' => ['#3c434a', '#eceeef', '#2c3338'], 'off' => true], // charcoal
      'failed'    => ['label' => 'Failed',               'badge' => 'bad',   'color' => ['#e26f00', '#fff2e5', '#9a4a00']], // orange
      'human'     => ['label' => 'Human',                'badge' => 'muted', 'color' => ['#c2306b', '#ffffff', '#a3245a']], // pink outline
      'notsent'   => ['label' => 'Not sent',             'badge' => 'muted', 'color' => ['#c3c4c7', '#fafafa', '#6c7074'], 'off' => true], // pale grey, dashed — shown only (ias_post_shown_status()): its time came in the dry run, before 0.12.0
    ];
  }
}

/** States that still belong on the plan (everything that is not finished or out). */
if (!function_exists('ias_live_statuses')) {
  function ias_live_statuses(): array {
    return ['planned', 'held', 'waiting', 'approved', 'pushed'];
  }
}

/**
 * Does this post wait for a person? It has reasons it stopped (`holds`) and
 * nobody has pressed Keep as written since. Whatever brings a post back onto
 * the plan brings such a post back as Needs a decision, never as Planned.
 */
if (!function_exists('ias_post_needs_decision')) {
  function ias_post_needs_decision(array $p): bool {
    $flags = (array) ($p['flags'] ?? []);
    return !empty($flags['holds']) && empty($flags['released_by']);
  }
}

/** The status a post returns to when it comes back onto the plan. */
if (!function_exists('ias_back_on_plan_status')) {
  function ias_back_on_plan_status(array $p): string {
    return ias_post_needs_decision($p) ? 'held' : 'planned';
  }
}

/** The reason a draft stops when its listing changed and only a person may rewrite it. */
if (!function_exists('ias_stale_hold')) {
  function ias_stale_hold(bool $edited, bool $in_oneup = false, array $what = [], bool $approved = false): string {
    // What changed, when the fingerprints can say (0.23.0): "The listing's open houses and description changed".
    $changed = $what ? 'The listing\'s ' . (count($what) > 1 ? implode(', ', array_slice($what, 0, -1)) . ' and ' . end($what) : $what[0]) . ' changed' : 'The listing changed';
    if ($in_oneup) return $changed . ' after a person edited this post in OneUp — press Edit it here again, check the words against the listing and save them (or skip it).';
    if ($approved) return $changed . ' after this post was approved — check it against the listing, then approve it again (or press Rewrite).';
    return $edited
      ? $changed . ' after a person edited this post — check the words against the listing, then save them (or press Rewrite).'
      : $changed . ' after this draft was written — check it against the listing, or press Rewrite.';
  }
}

if (!function_exists('ias_status_badge')) {
  function ias_status_badge(string $status): string {
    $all = ias_statuses();
    $s = $all[$status] ?? ['label' => ucfirst($status), 'badge' => 'muted'];
    if (empty($s['color'])) return ia_ui_badge($s['label'], $s['badge']);
    // The toolkit's badge shape with this status's own colours, inline so it looks the same on any screen.
    return '<span class="ia-badge ias-st ias-st-' . esc_attr($status) . '" style="' . esc_attr(ias_status_badge_style($status)) . '">' . esc_html($s['label']) . '</span>';
  }
}

/** A status badge's colours as inline CSS ('' for an unknown status). Pure. */
if (!function_exists('ias_status_badge_style')) {
  function ias_status_badge_style(string $status): string {
    $c = ias_statuses()[$status]['color'] ?? null;
    if (!$c) return '';
    [$line, $fill, $text] = $c;
    return 'background:' . $fill . ';color:' . $text . ';' . ($status === 'notsent' ? 'outline:1px dashed ' . $line . ';outline-offset:-1px' : 'box-shadow:inset 0 0 0 1px ' . $line)
      . ';display:inline-block;padding:1px 9px;border-radius:999px;font-size:11px;font-weight:600;line-height:1.8;white-space:nowrap'; // the badge's shape too, where the toolkit's stylesheet is not loaded
  }
}

/** The calendar's posts in their status's colours (CSS rules). Pure. */
if (!function_exists('ias_status_chip_css')) {
  function ias_status_chip_css(): string {
    $css = '';
    foreach (ias_statuses() as $k => $s) {
      if (empty($s['color'])) continue;
      [$line, $fill, $text] = $s['color'];
      $css .= '.ias-chip.st-' . $k . '{border-color:' . $line . ';background:' . $fill . ';color:' . $text . (!empty($s['off']) ? ';text-decoration:line-through' : '') . '}';
    }
    return $css . '.ias-chip.st-human{border:1px solid ' . ias_statuses()['human']['color'][0] . ';border-left-width:3px}.ias-chip.st-notsent{border-left-style:dashed}';
  }
}

/**
 * What a post is about, in a few words: the listing's title, or for a post
 * that covers several ("3 sales on Nov 18", "New this week: 4 sales").
 */
if (!function_exists('ias_post_subject')) {
  function ias_post_subject(array $post): string {
    $ids = (array) ($post['listing_ids'] ?? []);
    if (($post['type'] ?? '') === 'roundup') {
      return count($ids) . ' new ' . (count($ids) === 1 ? 'sale' : 'sales');
    }
    if (count($ids) > 1) {
      $day = !empty($post['content']['sale_day']) ? ' on ' . date_i18n('M j', strtotime((string) $post['content']['sale_day'])) : '';
      return count($ids) . ' sales' . $day;
    }
    $id = (int) ($ids[0] ?? ($post['listing_id'] ?? 0));
    if ($id <= 0) return (string) ($post['content']['subject'] ?? '—');
    $title = get_the_title($id);
    return $title !== '' ? html_entity_decode($title, ENT_QUOTES, 'UTF-8') : '#' . $id;
  }
}

/** When the post runs (scheduled time, else its ideal day), as a DateTimeImmutable or null. */
if (!function_exists('ias_post_when')) {
  function ias_post_when(array $post): ?DateTimeImmutable {
    $raw = !empty($post['scheduled_at']) ? (string) $post['scheduled_at'] : (string) ($post['window_ideal'] ?? '');
    if ($raw === '') return null;
    try { return new DateTimeImmutable($raw, ia_wp_tz()); } catch (\Exception $e) { return null; }
  }
}

/** Division keys → the names the team uses. */
if (!function_exists('ias_division_label')) {
  function ias_division_label(string $division): string {
    $labels = ['farm_home' => 'Farm & Home', 'investment_rec' => 'Investment & Recreation', 'intel' => 'Intel general', 'mixed' => 'Several divisions']; // 'intel' feeds the facts fingerprint: never rename it
    return $labels[$division] ?? $division;
  }
}
