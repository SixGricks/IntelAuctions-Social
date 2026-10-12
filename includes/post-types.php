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

if (!function_exists('ias_post_types')) {
  function ias_post_types(): array {
    return (array) apply_filters('ias_post_types', [
      'roundup' => [
        'label' => 'New This Week', 'short' => 'Roundup', 'lane' => 'A', 'guaranteed' => true, 'droppable' => false,
        'window' => 'Friday → Friday → Saturday',
        'what'   => 'Every Friday that a listing entered Recently Announced that week: one post naming every new sale, with a collage of their brochure covers.',
      ],
      'listing' => [
        'label' => 'Listing Posted', 'short' => 'Listing', 'lane' => 'B', 'guaranteed' => true, 'droppable' => false,
        'window' => 'promotion day → same day → +7 days',
        'what'   => 'When a listing moves to Upcoming (and has photos): up to 10 photos, the key details, open houses, auction type, the lead and their phone.',
      ],
      'open_house' => [
        'label' => 'Open House', 'short' => 'Open house', 'lane' => 'B', 'guaranteed' => false, 'droppable' => true,
        'window' => '7 days before → 5 → 3',
        'what'   => 'Each open house: the property video when there is one, otherwise 3–5 photos the listing post did not use; the time range and how long it runs.',
      ],
      'auction_week' => [
        'label' => 'Auction Week', 'short' => 'Auction week', 'lane' => 'B', 'guaranteed' => false, 'droppable' => true,
        'window' => '10 days before → 7 → 3',
        'what'   => 'The sale itself: different photos, an urgency angle, the auction time and how bidding works. Same-day sales share one post.',
      ],
      'results' => [
        'label' => 'Results', 'short' => 'Results', 'lane' => 'A', 'guaranteed' => true, 'droppable' => false,
        'window' => 'next day → next day → whenever approved',
        'what'   => 'When a listing moves to Past with a sale price: the featured photo with the price over it, the name, address, date and result. Liquidations get a thank-you post with no price.',
      ],
      'human' => [
        'label' => 'Human post', 'short' => 'Human', 'lane' => '', 'guaranteed' => true, 'droppable' => false,
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

/**
 * Post states, in the calendar's words, with the badge colour each wears.
 * planned → (Lane A) waiting → approved → pushed → published, or
 * (Lane B) planned → pushed → published; and the ways out: held (stopped for
 * a person), dropped (no room), skipped (a person said no), cancelled
 * (listing unpublished or deleted, or Cancel posts), failed (OneUp refused).
 */
if (!function_exists('ias_statuses')) {
  function ias_statuses(): array {
    return [
      'planned'   => ['label' => 'Planned',              'badge' => 'info'],
      'held'      => ['label' => 'Needs a decision',     'badge' => 'bad'],
      'waiting'   => ['label' => 'Waiting for approval', 'badge' => 'warn'],
      'approved'  => ['label' => 'Approved',             'badge' => 'ok'],
      'pushed'    => ['label' => 'Pushed',               'badge' => 'ok'],
      'published' => ['label' => 'Published',            'badge' => 'ok'],
      'dropped'   => ['label' => 'Dropped',              'badge' => 'muted'],
      'skipped'   => ['label' => 'Skipped',              'badge' => 'muted'],
      'cancelled' => ['label' => 'Cancelled',            'badge' => 'muted'],
      'failed'    => ['label' => 'Failed',               'badge' => 'bad'],
      'human'     => ['label' => 'Human',                'badge' => 'muted'],
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
  function ias_stale_hold(bool $edited, bool $in_oneup = false): string {
    if ($in_oneup) return 'The listing changed after a person edited this post in OneUp — fix it there (or skip it here), then press Keep as written.';
    return $edited
      ? 'The listing changed after a person edited this post — check the words against the listing, then save them (or press Rewrite).'
      : 'The listing changed after this draft was written — check it against the listing, or press Rewrite.';
  }
}

if (!function_exists('ias_status_badge')) {
  function ias_status_badge(string $status): string {
    $all = ias_statuses();
    $s = $all[$status] ?? ['label' => ucfirst($status), 'badge' => 'muted'];
    return ia_ui_badge($s['label'], $s['badge']);
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
    $labels = ['farm_home' => 'Farm & Home', 'investment_rec' => 'Investment & Recreation', 'intel' => 'Intel general'];
    return $labels[$division] ?? $division;
  }
}
