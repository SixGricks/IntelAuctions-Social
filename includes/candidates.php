<?php
/**
 * Intel Auctions Social — which posts should exist, and their windows.
 *
 * Pure: handed every listing's facts (ias_listing_facts()) and "today", it
 * returns the posts the spec calls for, each with its window (earliest →
 * ideal → latest, as Y-m-d), weight, priority and lane. No database and no
 * clock of its own, so tests/unit/ runs it against invented listings.
 *
 *   Listing Posted  — upcoming listing with photos: promotion day → same day
 *                     → +7 days (never past the day before the sale). No
 *                     photos yet: it waits. Photos still missing when auction
 *                     week starts: skipped, and the Auction Week post becomes
 *                     guaranteed and uses the brochure cover (the spec's late
 *                     listing rule). Already posted in full to Facebook by the
 *                     toolkit's webhook: skipped (no second listing post).
 *   Open House      — each open house O: O−7 → O−5 → O−3, never before the
 *                     listing was promoted.
 *   Auction Week    — the sale A: A−10 → A−7 → A−3. Listings selling the same
 *                     day share one post.
 *   Results         — a past listing with a sale price (or a liquidation, with
 *                     none): the day after the sale → whenever approved;
 *                     same-day sales share one post. Only for sales from a
 *                     week before the plugin arrived on, so installing it does
 *                     not unearth a year of results.
 *   New This Week   — Friday F, when listings entered Recently Announced from
 *                     the Friday before through Thursday: F → F → Saturday.
 *
 * Sundays: an ideal day that falls on a Sunday moves to the Monday (or the
 * Saturday when Monday is past the window); a Results post for a Saturday
 * sale starts on the Monday. The scheduler (Phase 3) does the rest.
 */

if (!defined('ABSPATH')) exit;

/** Y-m-d ± days. */
if (!function_exists('ias_day_add')) {
  function ias_day_add(string $day, int $days): string {
    return (new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
  }
}

/** ISO weekday of a Y-m-d (1 = Monday … 7 = Sunday). */
if (!function_exists('ias_day_dow')) {
  function ias_day_dow(string $day): int {
    return (int) (new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC')))->format('N');
  }
}

/** A Sunday ideal moves to Monday, or to Saturday when Monday is past $end. */
if (!function_exists('ias_off_sunday')) {
  function ias_off_sunday(string $day, ?string $start, ?string $end): string {
    if (ias_day_dow($day) !== 7) return $day;
    $mon = ias_day_add($day, 1);
    if ($end === null || $mon <= $end) return $mon;
    $sat = ias_day_add($day, -1);
    return ($start === null || $sat >= $start) ? $sat : $mon;
  }
}

/** The starting-bid multiplier: the first tier the bid reaches; no bid = the lowest tier. */
if (!function_exists('ias_bid_multiplier')) {
  function ias_bid_multiplier(?float $bid, array $tiers): float {
    usort($tiers, function ($a, $b) { return $b[0] <=> $a[0]; });
    if (!$tiers) return 1.0;
    if ($bid === null || $bid <= 0) return (float) end($tiers)[1];
    foreach ($tiers as $t) {
      if ($bid >= (float) $t[0]) return (float) $t[1];
    }
    return 1.0; // below every tier's floor (the last row does not start at $0)
  }
}

/** A group of listings' county list ('' when none is known). */
if (!function_exists('ias_group_county')) {
  function ias_group_county(array $facts_list): string {
    $c = [];
    foreach ($facts_list as $f) if (($f['county'] ?? '') !== '') $c[] = $f['county'];
    $c = array_values(array_unique($c));
    sort($c);
    return implode(', ', $c);
  }
}

if (!function_exists('ias_group_division')) {
  function ias_group_division(array $facts_list): string {
    $d = array_values(array_unique(array_map(function ($f) { return (string) ($f['division'] ?? ''); }, $facts_list)));
    return count($d) === 1 ? $d[0] : 'mixed';
  }
}

/**
 * The posts that should exist. $opts: weights (type => int), bid_tiers,
 * installed_at (Y-m-d, the day the plugin first ran), live_since (Y-m-d, the
 * day Facebook Listing posts went live through this plugin; '' = not yet).
 * Returns rows keyed by slot_key.
 */
if (!function_exists('ias_build_candidates')) {
  function ias_build_candidates(array $all, DateTimeImmutable $today_dt, array $opts): array {
    $today   = $today_dt->format('Y-m-d');
    $weights = (array) ($opts['weights'] ?? []);
    $tiers   = (array) ($opts['bid_tiers'] ?? [[0, 1.0]]);
    $installed = (string) ($opts['installed_at'] ?? $today);
    $live_since = (string) ($opts['live_since'] ?? '');
    $out = [];

    $base = function (string $type, array $facts_list, float $multiplier) use ($weights) {
      $w = (int) ($weights[$type] ?? 0);
      $def = ias_post_types()[$type] ?? [];
      return [
        'type'        => $type,
        'listing_ids' => array_values(array_map(function ($f) { return (int) $f['id']; }, $facts_list)),
        'lane'        => (string) ($def['lane'] ?? 'B'),
        'weight'      => $w,
        'guaranteed'  => !empty($def['guaranteed']),
        'score'       => round($w * $multiplier, 2),
        'county'      => ias_group_county($facts_list),
        'division'    => ias_group_division($facts_list),
        'status'      => 'planned',
        'note'        => '',
        'needs'       => [],
        'meta'        => [],
      ];
    };
    $window = function (array &$row, ?string $start, ?string $ideal, ?string $end) {
      if ($ideal !== null) $ideal = ias_off_sunday($ideal, $start, $end);
      $row['window_start'] = $start;
      $row['window_ideal'] = $ideal;
      $row['window_end']   = $end;
    };

    $live = [];
    foreach ($all as $f) {
      if (($f['status'] ?? '') !== 'publish' || !empty($f['password'])) continue; // drafts, private and password-protected sales get nothing
      $live[(int) $f['id']] = $f;
    }

    $promoted = function (array $f) use ($today) {
      return $f['promoted_at'] !== '' ? $f['promoted_at'] : $today;
    };

    $aw_groups = [];
    $res_groups = [];
    $late = [];

    foreach ($live as $id => $f) {
      $sale = (string) $f['sale_day'];

      if ($f['stage'] === 'upcoming') {
        $p = $promoted($f);
        $row = $base('listing', [$f], ias_bid_multiplier($f['starting_bid'], $tiers));
        $aw_start = $sale !== '' ? ias_day_add($sale, -10) : null;

        if ($f['webhook_pushed'] !== '' && ($live_since === '' || $f['webhook_pushed'] < $live_since)) {
          $row['status'] = 'skipped';
          $row['note'] = 'Facebook already got the full listing from the toolkit\'s webhook on ' . $f['webhook_pushed'] . '.';
          $window($row, $p, $p, $p);
        } elseif (!$f['has_photos']) {
          if ($aw_start !== null && $today >= $aw_start) {
            $row['status'] = 'skipped';
            $row['note'] = 'Photos never arrived before auction week, so the Auction Week post introduces it with the brochure cover.';
            $late[$id] = true;
            $window($row, $p, $p, $p);
          } else {
            $row['needs'][] = 'photos';
            $row['note'] = 'Waiting for photos: the Listing post is planned once the gallery has pictures.';
            $window($row, null, null, null);
          }
        } else {
          $start = max($p, $f['photos_at'] !== '' ? $f['photos_at'] : $p);
          $end = ias_day_add($start, 7);
          if ($sale !== '') $end = min($end, ias_day_add($sale, -1));
          if ($sale !== '' && $end < $start) {
            $row['status'] = 'skipped';
            $row['note'] = 'Promoted too close to the sale for a Listing post; the Auction Week post covers it.';
            $window($row, $start, $start, $start);
          } else {
            $window($row, $start, $start, $end);
          }
        }
        $out['listing:' . $id] = $row;

        // Open houses, from promotion on.
        foreach ($f['open_houses'] as $oh) {
          $o = $oh['start']->format('Y-m-d');
          if ($o < ias_day_add($today, -1)) continue; // long gone
          $start = max(ias_day_add($o, -7), $p);
          $end   = ias_day_add($o, -3);
          $row = $base('open_house', [$f], ias_bid_multiplier($f['starting_bid'], $tiers));
          $row['meta'] = ['oh' => (int) $oh['n'], 'oh_day' => $o];
          if ($start > $end) {
            $row['status'] = 'dropped';
            $row['note'] = 'The open house is less than 3 days after the listing went Upcoming — no room for its post.';
            $window($row, $end, $end, $end);
          } else {
            $window($row, $start, max($start, ias_day_add($o, -5)), $end);
          }
          $out['open_house:' . $id . ':' . str_replace('-', '', $o)] = $row;
        }

        if ($sale !== '' && $sale >= $today) $aw_groups[$sale][] = $f;
      }

      if ($f['stage'] === 'past' && $sale !== '') {
        $from = max(ias_day_add($installed, -7), ias_day_add($today, -30));
        $has_result = $f['price'] !== '' || $f['kind'] === 'liquidation';
        if ($sale >= $from && $sale < $today && $has_result) $res_groups[$sale][] = $f;
      }
    }

    // Auction Week: one post per sale day.
    foreach ($aw_groups as $sale => $group) {
      usort($group, function ($a, $b) { return [$a['date'], $a['id']] <=> [$b['date'], $b['id']]; });
      $mult = max(array_map(function ($f) use ($tiers) { return ias_bid_multiplier($f['starting_bid'], $tiers); }, $group));
      $row = $base('auction_week', $group, $mult);
      $start = ias_day_add($sale, -10);
      foreach ($group as $f) $start = max($start, $promoted($f));
      $end = ias_day_add($sale, -3);
      $row['meta'] = ['sale_day' => $sale];
      $late_ids = array_values(array_filter(array_map(function ($f) { return (int) $f['id']; }, $group), function ($id) use ($late) { return isset($late[$id]); }));
      if ($late_ids) {
        $row['guaranteed'] = true; // "the Auction Week post runs anyway"
        $row['meta']['uses_cover'] = $late_ids;
        $row['note'] = 'Guaranteed: it is the first post for a listing whose photos never arrived.';
      }
      if ($start > $end) {
        $start = $end; // promoted inside the window's tail: one possible day
      }
      $window($row, $start, max($start, ias_day_add($sale, -7)), $end);
      $out['auction_week:' . $sale] = $row;
    }

    // Results: one post per sale day, the day after the sale, open-ended.
    foreach ($res_groups as $sale => $group) {
      usort($group, function ($a, $b) { return [$a['date'], $a['id']] <=> [$b['date'], $b['id']]; });
      $mult = max(array_map(function ($f) use ($tiers) { return ias_bid_multiplier($f['starting_bid'], $tiers); }, $group));
      $row = $base('results', $group, $mult);
      $start = ias_day_add($sale, 1);
      if (ias_day_dow($start) === 7) $start = ias_day_add($start, 1); // a Saturday sale's results go out Monday
      $ideal = max($start, $today);
      $row['meta'] = ['sale_day' => $sale];
      $window($row, $start, $ideal, null);
      $out['results:' . $sale] = $row;
    }

    // New This Week: the Friday whose window is still open, and the one after it.
    $dow = ias_day_dow($today);
    $friday = $dow === 6 ? ias_day_add($today, -1) : ias_day_add($today, (5 - $dow + 7) % 7);
    foreach ([$friday, ias_day_add($friday, 7)] as $fri) {
      $from = ias_day_add($fri, -7);
      $to   = ias_day_add($fri, -1);
      $group = [];
      foreach ($live as $f) {
        $a = (string) ($f['announced_at'] ?? '');
        if ($a === '' && $f['stage'] === 'announced') $a = (string) ($f['published_at'] ?? '');
        if ($a === '' || $a < $from || $a > $to) continue;
        if (!in_array($f['stage'], ['announced', 'upcoming'], true)) continue;
        $group[] = $f;
      }
      if (!$group) continue;
      usort($group, function ($a, $b) {
        $ad = $a['sale_day'] !== '' ? $a['sale_day'] : '9999';
        $bd = $b['sale_day'] !== '' ? $b['sale_day'] : '9999';
        return [$ad, $a['id']] <=> [$bd, $b['id']];
      });
      $row = $base('roundup', $group, 1.0);
      $row['county'] = ''; // names many places; never blocks a same-county day
      $row['division'] = 'mixed';
      $row['meta'] = ['friday' => $fri, 'from' => $from, 'to' => $to];
      $window($row, $fri, $fri, ias_day_add($fri, 1));
      $out['roundup:' . $fri] = $row;
    }

    foreach ($out as $key => &$row) $row['slot_key'] = $key;
    return $out;
  }
}
