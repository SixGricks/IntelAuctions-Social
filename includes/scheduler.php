<?php
/**
 * Intel Auctions Social — the scheduler. Pure: handed the posts, the clock and
 * the limits, it answers which day and minute each post goes out, and which
 * are dropped and why. No database, so the busy-November test runs it.
 *
 * The rules (the spec's "daily run"):
 *   - Never on Sunday. At most `cap` (3) a day, at least `gap` (3 hours) apart,
 *     inside the posting windows, each time shifted by up to `jitter` minutes
 *     (never on the hour) — the same shift for the same post every day, so a
 *     plan that has not changed does not move.
 *   - Nothing new closer than the floor: 48 hours for Lane B (the veto
 *     window); for an approved Lane A post the approval was the yes, so an hour.
 *   - Fixed posts — human posts, posts edited in OneUp, posts already sent and
 *     inside the floor — are never moved or dropped; the plan works around them.
 *   - Every remaining post goes on its ideal day if it can. Guaranteed posts
 *     (Listing, Results, the roundup) are placed first, by priority; a post
 *     that finds no room bumps a lower one (a guaranteed post bumps any
 *     droppable one), and the bumped post looks again. A droppable post with
 *     no room anywhere in its window is dropped with the reason. A guaranteed
 *     post with no room runs late (up to `late_days` after its window, never
 *     past the day before its sale), and only if that fails too is the
 *     same-county rule relaxed for it — reported, never silent.
 *   - Sense rules: one post per property per day, no two posts about the same
 *     county on one day, and when two days are equally good, the one without
 *     this division already. A property's Open House and Auction Week posts
 *     come after its Listing post.
 *   - Then any day with fewer than `min` (2) posts pulls in the best post
 *     whose window reaches it — a dropped one first, else one from a day with
 *     more than `min`.
 *   - A post keeps the day and time it already has when they still work, so a
 *     post on the OneUp calendar is moved only when something forces it.
 */

if (!defined('ABSPATH')) exit;

/**
 * Plan. $posts: each
 *   key, type, listings (int[]), counties (string[]), division, score (float),
 *   guaranteed (bool), lane ('A'|'B'), approved (bool),
 *   start, ideal, end (Y-m-d; end null = open-ended), hard_end (Y-m-d|null: never after this, even late),
 *   fixed_at ('Y-m-d H:i' — fixed in place) or current_at ('Y-m-d H:i' — where it is now), pushed (bool:
 *   on the OneUp calendar already, so only moved when something forces it), after (keys that must come first),
 *   blocked (reason: not to be placed yet — it waits for something else).
 * $cfg: now (DateTimeImmutable, site time), cap, min, gap (minutes), lead (minutes, Lane B floor),
 *   lead_a (minutes, approved Lane A floor), days (plan horizon), slots ([[start, end] minutes]),
 *   slot_order (slot indexes, best first), jitter (minutes), late_days.
 * Returns ['at' => key => 'Y-m-d H:i', 'dropped' => key => reason, 'late' => key => reason,
 *   'relaxed' => key => reason, 'unplaced' => key => reason, 'later' => key => reason (window opens after the plan),
 *   'blocked' => key => reason (waits for something else)].
 */
if (!function_exists('ias_schedule')) {
  function ias_schedule(array $posts, array $cfg): array {
    $now   = $cfg['now'];
    $cap   = max(1, (int) ($cfg['cap'] ?? 3));
    $min   = max(0, min($cap, (int) ($cfg['min'] ?? 2)));
    $gap   = max(0, (int) ($cfg['gap'] ?? 180));
    $slots = array_values((array) ($cfg['slots'] ?? [[360, 450], [690, 780], [1140, 1260]]));
    $order = array_values((array) ($cfg['slot_order'] ?? array_reverse(array_keys($slots))));
    $jitter = max(0, (int) ($cfg['jitter'] ?? 25));
    $late_days = max(0, (int) ($cfg['late_days'] ?? 14));
    $today = $now->format('Y-m-d');
    $horizon = ias_day_add($today, max(1, (int) ($cfg['days'] ?? 30)) - 1);

    $floor = function (array $p) use ($now, $cfg) {
      // Lane A: the approval is the yes, so an hour's notice; an unapproved one is planned the same way and waits for it.
      $mins = $p['lane'] === 'A' ? (int) ($cfg['lead_a'] ?? 60) : (int) ($cfg['lead'] ?? 2880);
      $f = $now->modify('+' . $mins . ' minutes');
      return [$f->format('Y-m-d'), (int) $f->format('G') * 60 + (int) $f->format('i')];
    };

    $P = [];
    foreach ($posts as $p) {
      $p['listings'] = array_values(array_map('intval', (array) ($p['listings'] ?? [])));
      $p['counties'] = array_values(array_filter(array_map('strtolower', (array) ($p['counties'] ?? []))));
      $p['score'] = (float) ($p['score'] ?? 0);
      $p['guaranteed'] = !empty($p['guaranteed']);
      $p['lane'] = (string) ($p['lane'] ?? 'B');
      $p['after'] = array_values(array_filter((array) ($p['after'] ?? [])));
      $P[$p['key']] = $p;
    }

    // Fixed posts take their places first.
    $fixed = []; // day => [[min, key]]
    $fixed_day = []; // key => day
    foreach ($P as $k => $p) {
      if (empty($p['fixed_at'])) continue;
      $d = substr((string) $p['fixed_at'], 0, 10);
      $fixed[$d][] = [ias_hm_to_min(substr((string) $p['fixed_at'], 11, 5)), $k];
      $fixed_day[$k] = $d;
    }

    $placed = []; // day => [key]
    $day_of = []; // key => day
    $out = ['at' => [], 'dropped' => [], 'late' => [], 'relaxed' => [], 'unplaced' => [], 'later' => [], 'blocked' => []];

    $on_day = function (string $d) use (&$placed, &$fixed) {
      $keys = $placed[$d] ?? [];
      foreach ($fixed[$d] ?? [] as $f) $keys[] = $f[1];
      return $keys;
    };
    $rank = function (array $p) { return [$p['guaranteed'] ? 1 : 0, $p['score']]; };
    // A guaranteed post can make any droppable one move; a droppable one only a lower droppable one.
    // A guaranteed post is never moved aside, so nothing can bounce it into being dropped.
    $can_bump = function (array $p, array $o) { return !$o['guaranteed'] && ($p['guaranteed'] || $o['score'] < $p['score']); };

    /**
     * Times for every post on day $d (with $extra added): key => minute, or
     * null when they cannot all fit. Fixed posts keep their minute; a placed
     * post keeps its current minute when that still works; the rest take the
     * best free window by priority, trying every arrangement (≤3 posts).
     */
    $times = function (string $d, ?string $extra = null) use (&$placed, &$fixed, &$P, $slots, $order, $gap, $jitter, $floor) {
      $taken = [];
      foreach ($fixed[$d] ?? [] as $f) $taken[$f[1]] = $f[0];
      $mov = $placed[$d] ?? [];
      if ($extra !== null) $mov[] = $extra;
      usort($mov, function ($a, $b) use ($P) { return [$P[$b]['score'], $a] <=> [$P[$a]['score'], $b]; });
      $ok = function (int $t, array $taken) use ($gap) {
        foreach ($taken as $u) if (abs($t - $u) < $gap) return false;
        return true;
      };
      // Keep current minutes that still work.
      $rest = [];
      foreach ($mov as $k) {
        $cur = (string) ($P[$k]['current_at'] ?? '');
        [$fd, $fm] = $floor($P[$k]);
        if ($cur !== '' && substr($cur, 0, 10) === $d) {
          $t = ias_hm_to_min(substr($cur, 11, 5));
          $in = false;
          foreach ($slots as $s) if ($t >= $s[0] && $t <= $s[1]) $in = true;
          if ($in && ($d > $fd || $t >= $fm) && $ok($t, $taken)) { $taken[$k] = $t; continue; }
        }
        $rest[] = $k;
      }
      if (!$rest) return $taken;
      // Every arrangement of free windows for the rest, best preference first;
      // in each window the free minute nearest the post's own shifted time.
      $choose = function (array $keys, array $taken) use (&$choose, $slots, $order, $jitter, $floor, $d, $P, $gap) {
        if (!$keys) return $taken;
        $k = array_shift($keys);
        [$fd, $fm] = $floor($P[$k]);
        foreach ($order as $si) {
          if (!isset($slots[$si])) continue;
          [$a, $b] = $slots[$si];
          if ($d === $fd) $a = max($a, $fm);
          if ($a > $b) continue;
          $j = $jitter > 0 ? 1 + (crc32($k) % $jitter) : 0;
          $target = max($a, min($b, $slots[$si][0] + $j));
          $t = ias_free_minute($a, $b, $target, array_values($taken), $gap, $jitter > 0);
          if ($t === null) continue;
          $res = $choose($keys, $taken + [$k => $t]);
          if ($res !== null) return $res;
        }
        return null;
      };
      return $choose($rest, $taken);
    };

    /** Can $k go on day $d? (No sense rule broken, room, time.) $relax drops the county rule. */
    $fits = function (string $k, string $d, bool $relax = false) use (&$P, &$placed, &$day_of, &$fixed_day, $on_day, $cap, $times, $floor) {
      $p = $P[$k];
      if (ias_day_dow($d) === 7) return false;
      [$fd] = $floor($p);
      if ($d < $fd) return false;
      $here = $on_day($d);
      if (count($here) >= $cap) return false;
      foreach ($here as $o) {
        if (array_intersect($p['listings'], $P[$o]['listings'])) return false;
        if (!$relax && $p['counties'] && array_intersect($p['counties'], $P[$o]['counties'])) return false;
      }
      // A property's later posts come after its Listing post, and the Listing post before them.
      foreach ($p['after'] as $a) {
        $before = $day_of[$a] ?? ($fixed_day[$a] ?? null);
        if ($before !== null && $d <= $before) return false;
      }
      foreach ($day_of as $o => $od) {
        if ($od <= $d && in_array($k, $P[$o]['after'], true)) return false;
      }
      return $times($d, $k) !== null;
    };

    /** The days $k may use, best first: nearest the ideal, then a day without its division, then earlier. */
    $days_for = function (string $k, bool $late = false) use (&$P, $today, $horizon, $late_days, &$on_day) {
      $p = $P[$k];
      $start = max((string) ($p['start'] ?? $today), $today);
      $end = $p['end'] === null || $p['end'] === '' ? $horizon : (string) $p['end'];
      if ($late) {
        $start = max($start, ias_day_add($end, 1), $today);
        $end = ias_day_add($end, $late_days);
      }
      if (!empty($p['hard_end'])) $end = min($end, (string) $p['hard_end']);
      $end = min($end, ias_day_add($horizon, $late_days));
      if ($start > $end) return [];
      $ideal = (string) ($p['ideal'] ?? $start);
      $ideal = max($start, min($end, $ideal));
      $days = [];
      for ($d = $start; $d <= $end; $d = ias_day_add($d, 1)) $days[] = $d;
      $cur = !empty($p['current_at']) ? substr((string) $p['current_at'], 0, 10) : '';
      usort($days, function ($a, $b) use ($ideal, $cur, $p, $on_day, $P) {
        $da = abs(strtotime($a) - strtotime($ideal)); $db = abs(strtotime($b) - strtotime($ideal));
        $div = function ($d) use ($p, $on_day, $P) {
          foreach ($on_day($d) as $o) if (($P[$o]['division'] ?? '') === ($p['division'] ?? '') && ($p['division'] ?? '') !== '') return 1;
          return 0;
        };
        return [$a === $cur ? 0 : 1, $da, $div($a), $a] <=> [$b === $cur ? 0 : 1, $db, $div($b), $b];
      });
      return $days;
    };

    $put = function (string $k, string $d) use (&$placed, &$day_of) {
      $placed[$d][] = $k;
      $day_of[$k] = $d;
    };
    $take = function (string $k) use (&$placed, &$day_of) {
      $d = $day_of[$k];
      $placed[$d] = array_values(array_diff($placed[$d], [$k]));
      unset($day_of[$k]);
    };

    /** Place $k, bumping lower-ranked posts if it must. Returns the bumped keys, or false. */
    $place = function (string $k, array $days, bool $relax = false) use (&$P, &$placed, &$day_of, $fits, $put, $take, $rank, $can_bump) {
      foreach ($days as $d) {
        if ($fits($k, $d, $relax)) { $put($k, $d); return []; }
      }
      // Bump: on the nearest day where removing lower-ranked posts makes room.
      foreach ($days as $d) {
        $lower = array_values(array_filter($placed[$d] ?? [], function ($o) use ($P, $k, $can_bump) {
          return $can_bump($P[$k], $P[$o]);
        }));
        usort($lower, function ($a, $b) use ($P, $rank) { return $rank($P[$a]) <=> $rank($P[$b]); });
        $removed = [];
        foreach ($lower as $o) {
          $take($o);
          $removed[] = $o;
          if ($fits($k, $d, $relax)) { $put($k, $d); return $removed; }
        }
        foreach ($removed as $o) $put($o, $d); // that did not help: put them back
      }
      return false;
    };

    // Queue: guaranteed first (a Listing post before the posts that wait on it), then by
    // priority; among equals the tighter window, then the earlier ideal day.
    $queue = array_keys(array_filter($P, function ($p) { return empty($p['fixed_at']); }));
    $width = function (array $p) use ($horizon) {
      $e = $p['end'] === null || $p['end'] === '' ? $horizon : $p['end'];
      return (int) round((strtotime($e) - strtotime((string) ($p['start'] ?? $e))) / 86400);
    };
    usort($queue, function ($a, $b) use ($P, $width) {
      $pa = $P[$a]; $pb = $P[$b];
      return [$pb['guaranteed'] ? 1 : 0, $pa['after'] ? 1 : 0, $pb['score'], $width($pa), (string) ($pa['ideal'] ?? ''), $a]
        <=> [$pa['guaranteed'] ? 1 : 0, $pb['after'] ? 1 : 0, $pa['score'], $width($pb), (string) ($pb['ideal'] ?? ''), $b];
    });

    $bumps = [];
    while ($queue) {
      $k = array_shift($queue);
      $p = $P[$k];
      if (!empty($p['blocked'])) {
        $out['blocked'][$k] = (string) $p['blocked'];
        continue;
      }
      if ((string) ($p['start'] ?? '') > $horizon) {
        $out['later'][$k] = 'Its window opens after the ' . ($cfg['days'] ?? 30) . '-day plan.';
        continue;
      }
      $days = $days_for($k);
      $res = $days ? $place($k, $days) : false;
      if ($res === false && $p['guaranteed']) {
        $late = $days_for($k, true);
        $res = $late ? $place($k, $late) : false;
        if ($res !== false) $out['late'][$k] = 'No room in its window; it runs late, on ' . date('D M j', strtotime($day_of[$k])) . '.';
        if ($res === false) {
          $res = $place($k, array_merge($days, $late), true);
          if ($res !== false) $out['relaxed'][$k] = 'Only fits on a day with another post about the same county (' . date('D M j', strtotime($day_of[$k])) . ').';
        }
        if ($res === false) {
          $out['unplaced'][$k] = 'No day within reach has room, even after every droppable post made way.';
          continue;
        }
      }
      if ($res === false) {
        $w = ($p['start'] ?? '') !== '' ? date('M j', strtotime((string) max($p['start'], $today))) . '–' . date('M j', strtotime((string) ($p['end'] ?? $horizon))) : '';
        $out['dropped'][$k] = $days
          ? 'No room in its window (' . $w . '): every day was full of higher-priority posts or broke a rule (one post per property, one per county, Sundays off).'
          : 'Its window (' . $w . ') had closed or was inside the ' . ($p['lane'] === 'A' ? 'hour' : '48-hour veto window') . ' by the time it could be planned.';
        continue;
      }
      foreach ($res as $o) { // a droppable post made way: it looks again elsewhere
        $bumps[$o] = ($bumps[$o] ?? 0) + 1;
        if ($bumps[$o] <= 6) $queue[] = $o;
        else $out['dropped'][$o] = 'Made way for higher-priority posts too often to find a day.';
      }
    }

    // Thin days pull in the best post whose window reaches them.
    $all_days = [];
    for ($d = $today; $d <= $horizon; $d = ias_day_add($d, 1)) if (ias_day_dow($d) !== 7) $all_days[] = $d;
    foreach ($all_days as $d) {
      $guard = 0;
      while (count($on_day($d)) < $min && $guard++ < $cap) {
        $best = null;
        foreach (array_keys($out['dropped']) as $k) {
          $p = $P[$k];
          if (($p['start'] ?? '') > $d || ($p['end'] !== null && $p['end'] !== '' && $p['end'] < $d)) continue;
          if ($fits($k, $d) && ($best === null || $rank($p) > $rank($P[$best]))) $best = $k;
        }
        if ($best !== null) {
          unset($out['dropped'][$best]);
          $put($best, $d);
          continue;
        }
        foreach ($day_of as $k => $from) {
          if ($from === $d || count($on_day($from)) <= $min) continue;
          $p = $P[$k];
          if (!empty($p['pushed'])) continue; // already on the OneUp calendar: do not churn it
          if (($p['start'] ?? '') > $d || ($p['end'] !== null && $p['end'] !== '' && $p['end'] < $d)) continue;
          $take($k);
          $ok = $fits($k, $d);
          $put($k, $from);
          if ($ok && ($best === null || $rank($p) > $rank($P[$best]))) $best = $k;
        }
        if ($best === null) break;
        $take($best);
        $put($best, $d);
      }
    }

    // Minutes.
    foreach ($placed as $d => $keys) {
      if (!$keys) continue;
      $t = $times($d);
      if ($t === null) { // cannot happen: every placement checked its day's times
        foreach ($keys as $k) $out['unplaced'][$k] = 'No time on ' . $d . ' fits.';
        continue;
      }
      foreach ($keys as $k) $out['at'][$k] = $d . ' ' . ias_min_to_hm((int) $t[$k]);
    }
    return $out;
  }
}

/** "06:15" → 375. */
if (!function_exists('ias_hm_to_min')) {
  function ias_hm_to_min(string $hm): int {
    [$h, $m] = array_map('intval', explode(':', $hm . ':0'));
    return $h * 60 + $m;
  }
}

/** 375 → "06:15". */
if (!function_exists('ias_min_to_hm')) {
  function ias_min_to_hm(int $min): string {
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
  }
}

/**
 * The free minute in [$a, $b] nearest $target that is at least $gap from every
 * minute in $taken (and, with $off_hour, not on the hour), or null.
 */
if (!function_exists('ias_free_minute')) {
  function ias_free_minute(int $a, int $b, int $target, array $taken, int $gap, bool $off_hour = true): ?int {
    $free = function (int $t) use ($taken, $gap, $off_hour) {
      if ($off_hour && $t % 60 === 0) return false;
      foreach ($taken as $u) if (abs($t - (int) $u) < $gap) return false;
      return true;
    };
    // The nearest free minute is the target itself or next to the edge of a blocked stretch.
    $cands = [$target, $a, $b];
    foreach ($taken as $u) { $cands[] = (int) $u - $gap; $cands[] = (int) $u + $gap; }
    $best = null;
    foreach ($cands as $c) {
      foreach ([$c, $c + 1, $c - 1, $c + 2] as $t) {
        if ($t < $a || $t > $b || !$free($t)) continue;
        if ($best === null || abs($t - $target) < abs($best - $target) || (abs($t - $target) === abs($best - $target) && $t < $best)) $best = $t;
      }
    }
    return $best;
  }
}

/**
 * The posting windows as people name them (DJ, Oct 14, 2026: "choose the
 * morning, afternoon, or evening time slot"): index (in time order, as the
 * engine numbers them) => ['name', 'from', 'to' (as "6:00 am"), 'a', 'b'
 * (minutes after midnight)]. Named by when they start: before 11:00 the
 * Morning, before 5:00 pm the Afternoon, then the Evening; a second window
 * of the same part of the day is "… 2". $slots: Settings rows ['06:00',
 * '07:30']. Pure.
 */
if (!function_exists('ias_slot_names')) {
  function ias_slot_names(array $slots): array {
    $rows = [];
    foreach ($slots as $row) {
      $hm = [(string) ($row[0] ?? ''), (string) ($row[1] ?? '')];
      if (!preg_match('/^\d{1,2}:\d{2}$/', $hm[0]) || !preg_match('/^\d{1,2}:\d{2}$/', $hm[1])) continue;
      $a = ias_hm_to_min($hm[0]);
      $b = ias_hm_to_min($hm[1]);
      if ($b < $a) continue;
      $rows[] = [$a, $b];
    }
    usort($rows, function ($x, $y) { return $x[0] <=> $y[0]; });
    $fmt = function (int $m) { $h = intdiv($m, 60); return ($h % 12 === 0 ? 12 : $h % 12) . ':' . sprintf('%02d', $m % 60) . ($h < 12 ? ' am' : ' pm'); };
    $out = [];
    $seen = [];
    foreach ($rows as $i => [$a, $b]) {
      $name = $a < 660 ? 'Morning' : ($a < 1020 ? 'Afternoon' : 'Evening');
      $seen[$name] = ($seen[$name] ?? 0) + 1;
      $out[$i] = ['name' => $name . ($seen[$name] > 1 ? ' ' . $seen[$name] : ''), 'from' => $fmt($a), 'to' => $fmt($b), 'a' => $a, 'b' => $b];
    }
    return $out;
  }
}

/** Which window minute $t falls in (an index of ias_slot_names()), or -1. Pure. */
if (!function_exists('ias_slot_index')) {
  function ias_slot_index(array $named, int $t): int {
    foreach ($named as $i => $w) if ($t >= $w['a'] && $t <= $w['b']) return (int) $i;
    return -1;
  }
}

/**
 * The minute a post moved into window [$a, $b] takes: its own shifted time
 * (the same shift the plan gives it, from its slot key), else the nearest free
 * minute to it — never within $gap minutes of $taken, never on the hour,
 * never before $not_before. null = no room in that window. Pure.
 */
if (!function_exists('ias_slot_minute')) {
  function ias_slot_minute(int $a, int $b, string $key, int $jitter, array $taken, int $gap, int $not_before = 0): ?int {
    $from = max($a, $not_before);
    if ($from > $b) return null;
    $j = $jitter > 0 ? 1 + (crc32($key) % $jitter) : 0;
    $target = max($from, min($b, $a + $j));
    return ias_free_minute($from, $b, $target, array_values($taken), $gap, $jitter > 0);
  }
}

/**
 * The scheduler's view of the posts table: which rows take part, which are
 * fixed in place, and what each must come after. $rows are decoded post rows;
 * $lead is the Lane B floor in minutes. Pure.
 *
 *   fixed   — published and human posts, posts locked by an edit in OneUp, and
 *             posts already sent inside the veto window (on the OneUp calendar:
 *             `flags.oneup`);
 *   blocked — a property's Open House and Auction Week posts while its Listing
 *             post waits for photos (the Listing post comes first);
 *   left out — skipped and cancelled posts, posts dropped for want of a window,
 *             listings still waiting for photos, and anything with no day yet.
 */
if (!function_exists('ias_engine_posts')) {
  function ias_engine_posts(array $rows, DateTimeImmutable $now, int $lead): array {
    $floor = $now->modify('+' . $lead . ' minutes')->format('Y-m-d H:i');
    $today = $now->format('Y-m-d');
    $no_photos = []; // listing id => true while its Listing post waits for photos
    foreach ($rows as $r) {
      if ($r['type'] === 'listing' && !empty($r['flags']['needs']) && in_array((string) $r['status'], ['planned', 'held', 'waiting'], true)) {
        foreach ((array) $r['listing_ids'] as $lid) $no_photos[(int) $lid] = true;
      }
    }
    $in = [];
    foreach ($rows as $r) {
      $status = (string) $r['status'];
      if (in_array($status, ['skipped', 'cancelled', 'failed'], true)) continue;
      if ($status === 'dropped' && ($r['flags']['dropped_by'] ?? '') !== 'scheduler') continue;
      if (!empty($r['flags']['needs']) || empty($r['window_ideal'])) {
        if (!in_array($status, ['published', 'human'], true)) continue;
      }
      $at = !empty($r['scheduled_at']) ? substr((string) $r['scheduled_at'], 0, 16) : '';
      $live = (array) ($r['flags']['oneup'] ?? []);
      $sent = $status === 'pushed' || !empty($live['hash']) || !empty($live['id']);
      $fixed = '';
      $pin = (string) ($r['flags']['pinned'] ?? ''); // a person moved it there (Move on its page): it stays, while that time is ahead
      if (in_array($status, ['published', 'human'], true) || !empty($r['locked'])) $fixed = $at;
      elseif ($pin !== '' && $pin > $now->format('Y-m-d H:i')) $fixed = $pin;
      elseif ($sent && $at !== '' && $at < $floor) $fixed = $at; // sent and inside the veto window: it stays
      if ($fixed === '' && in_array($status, ['published', 'human'], true)) continue;
      if ($fixed !== '' && substr($fixed, 0, 10) < $today) continue; // already behind us
      $meta = (array) ($r['flags']['meta'] ?? []);
      $sale = (string) ($meta['sale_day'] ?? '');
      $in[$r['slot_key']] = [
        'key'        => (string) $r['slot_key'],
        'id'         => (int) ($r['id'] ?? 0),
        'type'       => (string) $r['type'],
        'listings'   => array_map('intval', (array) $r['listing_ids']),
        'counties'   => array_filter(array_map('trim', explode(',', (string) $r['county']))),
        'division'   => (string) $r['division'],
        'score'      => (float) $r['score'],
        'guaranteed' => !empty($r['guaranteed']),
        'lane'       => (string) $r['lane'],
        'approved'   => $status === 'approved',
        'start'      => $r['window_start'] ?: null,
        'ideal'      => $r['window_ideal'] ?: null,
        'end'        => $r['window_end'] ?: null,
        'hard_end'   => ($r['type'] === 'listing' && $sale !== '') ? ias_day_add($sale, -1) : null,
        'fixed_at'   => $fixed !== '' ? $fixed : null,
        'current_at' => $fixed === '' && $at !== '' ? $at : null,
        'pushed'     => $sent,
        'after'      => [],
      ];
      if ($fixed === '' && in_array($r['type'], ['open_house', 'auction_week'], true)) {
        foreach ((array) $r['listing_ids'] as $lid) {
          if (isset($no_photos[(int) $lid])) $in[$r['slot_key']]['blocked'] = 'Waits for its Listing post, which is waiting for photos — the Listing post goes first.';
        }
      }
    }
    // A property's Open House and Auction Week posts come after its Listing post.
    foreach ($in as $k => $p) {
      if (!in_array($p['type'], ['open_house', 'auction_week'], true)) continue;
      foreach ($p['listings'] as $lid) {
        if (isset($in['listing:' . $lid])) $in[$k]['after'][] = 'listing:' . $lid;
      }
    }
    return array_values($in);
  }
}

/** The engine's settings from the plugin's. Pure apart from reading them. */
if (!function_exists('ias_engine_cfg')) {
  function ias_engine_cfg(array $s, DateTimeImmutable $now): array {
    $slots = [];
    foreach ((array) $s['slots'] as $row) $slots[] = [ias_hm_to_min((string) $row[0]), ias_hm_to_min((string) $row[1])];
    usort($slots, function ($a, $b) { return $a[0] <=> $b[0]; });
    return [
      'now'        => $now,
      'cap'        => (int) $s['daily_cap'],
      'min'        => (int) $s['daily_min'],
      'gap'        => 60 * (int) $s['min_gap_hours'],
      'lead'       => 60 * (int) $s['lead_hours'],
      'lead_a'     => 60,
      'days'       => (int) $s['plan_days'],
      'slots'      => $slots,
      // The best window to the highest priority: evening, then midday, then morning.
      'slot_order' => array_reverse(array_keys($slots)),
      'jitter'     => (int) $s['jitter_minutes'],
      'late_days'  => 14,
    ];
  }
}
