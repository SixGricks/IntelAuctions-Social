<?php
/**
 * Intel Auctions Social — keeping the posts in step with the listings, and
 * the hourly job.
 *
 * The sync reads every current listing's facts, asks candidates.php which
 * posts should exist, and makes the table match: new posts are added as
 * Planned, changed ones get their new window, priority and listing set, posts
 * no longer called for are cancelled (a listing unpublished or deleted, a date
 * or an open house changed), and posts the plugin itself cancelled or skipped
 * come back when they are called for again (photos arrived, the listing was
 * republished). Nothing a person decided is undone: a post someone skipped or
 * cancelled stays that way, and one already approved, pushed or published is
 * never rewritten — a listing that joins its sale day later gets its own post.
 *
 * The job (`ias_social` on the toolkit's hourly dispatcher) syncs, then writes
 * the drafts that are missing or whose facts changed, a few per run, Lane A
 * (approve-first) posts first, then by day.
 */

if (!defined('ABSPATH')) exit;

/* ---------------- Markers on the listing ---------------- */

/**
 * When a listing changes stage, note the day: `_ias_announced_at` (it entered
 * Recently Announced — the Friday roundup reads it) and `_ias_promoted_at`
 * (it moved to Upcoming — the Listing post's window starts there). First time
 * only; site time zone.
 */
if (!function_exists('ias_on_stage_changed')) {
  function ias_on_stage_changed($post_id, $stage): void {
    $post_id = (int) $post_id;
    $key = ['announced' => '_ias_announced_at', 'upcoming' => '_ias_promoted_at'][(string) $stage] ?? '';
    if ($key === '' || get_post_meta($post_id, $key, true) !== '') return;
    update_post_meta($post_id, $key, ias_now()->format('Y-m-d'));
  }
}
add_action('ia_stage_changed', 'ias_on_stage_changed', 10, 2);

/* ---------------- Sync ---------------- */

/** The listings the plan has to look at: announced, upcoming, recently past, and any with a live post. */
if (!function_exists('ias_sync_listing_ids')) {
  function ias_sync_listing_ids(): array {
    $ids = [];
    foreach (['announced', 'upcoming'] as $stage) {
      foreach (ia_stage_auctions($stage, 300) as $row) $ids[] = (int) $row['ID'];
    }
    $cutoff = ias_now()->setTime(0, 0)->modify('-35 days');
    foreach (ia_stage_auctions('past', 80) as $row) {
      if ($row['date'] instanceof DateTimeImmutable && $row['date'] >= $cutoff) $ids[] = (int) $row['ID'];
    }
    foreach (ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'pushed'], 'limit' => 2000]) as $p) {
      foreach ((array) $p['listing_ids'] as $id) $ids[] = (int) $id;
    }
    return array_values(array_unique(array_filter($ids)));
  }
}

/** Options for the candidate builder, from the settings and the plugin's history. */
if (!function_exists('ias_candidate_opts')) {
  function ias_candidate_opts(): array {
    $installed = (string) get_option('ias_installed_at', '');
    if ($installed === '') {
      $installed = ias_now()->format('Y-m-d');
      update_option('ias_installed_at', $installed, false);
    }
    // Listings with a Results post still on the plan: theirs never expires, however long it waits.
    $results = [];
    foreach (ias_posts_query(['type' => 'results', 'status' => ias_live_statuses(), 'limit' => 500]) as $p) {
      foreach ((array) $p['listing_ids'] as $id) $results[] = (int) $id;
    }
    return [
      'weights'      => (array) ias_setting('weights', []),
      'bid_tiers'    => (array) ias_setting('bid_tiers', []),
      'installed_at' => $installed,
      'live_since'   => (string) get_option('ias_live_since', ''),
      'keep_results' => array_values(array_unique($results)),
    ];
  }
}

/** The columns a candidate sets on its row. */
if (!function_exists('ias_candidate_fields')) {
  function ias_candidate_fields(array $c): array {
    return [
      'type'         => $c['type'],
      'listing_id'   => (int) ($c['listing_ids'][0] ?? 0),
      'listing_ids'  => $c['listing_ids'],
      'lane'         => $c['lane'],
      'weight'       => (int) $c['weight'],
      'score'        => (float) $c['score'],
      'guaranteed'   => $c['guaranteed'] ? 1 : 0,
      'county'       => mb_substr((string) $c['county'], 0, 80),
      'division'     => (string) $c['division'],
      'window_start' => $c['window_start'],
      'window_ideal' => $c['window_ideal'],
      'window_end'   => $c['window_end'],
    ];
  }
}

/**
 * Make the table match the listings. Returns counts: added, updated, revived,
 * cancelled, split, and the listings looked at.
 */
if (!function_exists('ias_sync')) {
  function ias_sync(): array {
    if (!ias_db_ready()) return ['skipped' => ['tables not ready']];
    $today = ias_now()->setTime(0, 0);
    $day = $today->format('Y-m-d');
    $sum = ['listings' => 0, 'added' => 0, 'updated' => 0, 'revived' => 0, 'cancelled' => 0, 'split' => 0];

    $facts = [];
    foreach (ias_sync_listing_ids() as $id) {
      if (!get_post($id)) continue;
      $f = ias_listing_facts($id);
      // First sight of a listing already Upcoming, or of its photos: today is the day.
      if ($f['stage'] === 'upcoming' && $f['promoted_at'] === '' && $f['status'] === 'publish') {
        update_post_meta($id, '_ias_promoted_at', $day);
        $f['promoted_at'] = $day;
      }
      if ($f['has_photos'] && $f['photos_at'] === '') {
        update_post_meta($id, '_ias_photos_at', $day);
        $f['photos_at'] = $day;
      }
      $facts[$id] = $f;
    }
    $sum['listings'] = count($facts);
    $cands = ias_build_candidates(array_values($facts), $today, ias_candidate_opts());
    $facts_of = function (array $ids) use ($facts) {
      return array_values(array_filter(array_map(function ($id) use ($facts) { return $facts[(int) $id] ?? null; }, $ids)));
    };

    // Approved but not yet out: the yes was for these facts about these listings. If a
    // listing left the post (unpublished, Cancel posts, a date moved) or a fact behind it
    // changed (a corrected price), the approval is withdrawn before anything else.
    foreach ($cands as $key => $c) {
      $row = ias_post_by_slot($key);
      if (!$row || $row['status'] !== 'approved') continue;
      $flags_meta = ['meta' => $c['meta'], 'needs' => $c['needs']];
      $lost = array_values(array_diff(array_map('intval', $row['listing_ids']), array_map('intval', $c['listing_ids'])));
      $hash = ias_facts_hash(['type' => $c['type'], 'flags' => array_merge($row['flags'], $flags_meta)], $facts_of($row['listing_ids']));
      if (!$lost && ($row['facts_hash'] === '' || $hash === $row['facts_hash'])) continue;
      ias_approval_withdraw($row, $c, $lost ? 'A listing in it is no longer part of it' : 'The listing changed');
      $sum['updated']++;
    }

    // Frozen posts (approved, on OneUp, published) never take in a listing; one that joins
    // their sale day later gets a post of its own, so nothing scheduled is redrafted for it.
    $on_oneup = function (array $row) { return function_exists('ias_post_sent') && ias_post_sent($row)['mode'] === 'live'; };
    foreach ($cands as $key => $c) {
      $row = ias_post_by_slot($key);
      if (!$row || !(in_array($row['status'], ['approved', 'pushed', 'published'], true) || $on_oneup($row))) continue;
      $new = array_values(array_diff($c['listing_ids'], $row['listing_ids']));
      if (!$new || !in_array($c['type'], ['auction_week', 'results'], true)) continue;
      $extra = $c;
      $extra['listing_ids'] = $new;
      $sub = array_values(array_filter($facts, function ($f) use ($new) { return in_array((int) $f['id'], $new, true); }));
      $extra['county'] = ias_group_county($sub);
      $extra['division'] = ias_group_division($sub);
      $extra['slot_key'] = $key . ':' . implode('-', $new);
      $cands[$extra['slot_key']] = $extra;
      $sum['split']++;
    }

    foreach ($cands as $key => $c) {
      $row = ias_post_by_slot($key);
      $flags_meta = ['meta' => $c['meta'], 'needs' => $c['needs']];
      if (!$row) {
        $id = ias_post_insert(ias_candidate_fields($c) + [
          'slot_key' => $key,
          'status'   => $c['status'],
          'note'     => $c['note'],
          'flags'    => $flags_meta + ($c['status'] !== 'planned' ? [$c['status'] . '_by' => 'system'] : []),
        ]);
        if ($id) {
          $sum['added']++;
          $what = $c['status'] === 'planned' ? 'Planned' : ucfirst($c['status']) . ': ' . $c['note'];
          ias_post_trail($id, $what);
          ias_log($c['status'] === 'planned' ? 'planned' : $c['status'], (int) ($c['listing_ids'][0] ?? 0), ['post' => $id, 'type' => $c['type'], 'summary' => $what]);
        }
        continue;
      }
      if (in_array($row['status'], ['pushed', 'published', 'human', 'approved'], true)) continue; // approved: handled above

      $fields = ias_candidate_fields($c);
      if ($on_oneup($row)) {
        // On OneUp: it keeps its own listings (a newcomer got its own post above); one that left the sale leaves it.
        $keep = array_values(array_intersect(array_map('intval', $c['listing_ids']), array_map('intval', $row['listing_ids'])));
        if ($keep) { $fields['listing_ids'] = $keep; $fields['listing_id'] = $keep[0]; }
      }
      $flags = array_merge($row['flags'], $flags_meta);
      $status = $row['status'];
      $trail = '';
      $by_system = function (string $st) use ($row) { return ($row['flags'][$st . '_by'] ?? '') === 'system'; };

      $restored = ($row['flags']['restored_over'] ?? null) === $c['note']; // a person pressed Restore over this very reason

      if ($c['status'] === 'planned' && in_array($status, ['cancelled', 'skipped', 'dropped'], true) && $by_system($status)) {
        $trail = 'Back on the plan' . ($row['note'] !== '' ? ' (was: ' . $row['note'] . ')' : '');
        unset($flags[$status . '_by']);
        $status = ias_back_on_plan_status(['flags' => $flags]); // a draft that stopped for a person comes back stopped
        $fields['note'] = $status === 'held' ? 'Needs a decision: ' . (string) ($flags['holds'][0] ?? '') : '';
        $sum['revived']++;
      } elseif ($c['status'] !== 'planned' && in_array($status, ['planned', 'held', 'waiting'], true) && !$restored) {
        $status = $c['status'];
        $flags[$status . '_by'] = 'system';
        $fields['note'] = $c['note'];
        $trail = ucfirst($status) . ': ' . $c['note'];
        if ($row['status'] === 'waiting' && function_exists('ias_approval_void')) ias_approval_void((int) $row['id']);
      } elseif ($c['status'] !== 'planned' && $restored) {
        // Kept on the plan by a person. The system's window for a skipped post is its one lost day,
        // so it gets a week from today instead (a Listing post: never later than the day before its sale).
        if (!empty($fields['window_end']) && (string) $fields['window_end'] < $day) {
          $end = ias_day_add($day, 7);
          $sale = (string) ($c['meta']['sale_day'] ?? '');
          if ($c['type'] === 'listing' && $sale !== '') $end = max($day, min($end, ias_day_add($sale, -1)));
          $fields['window_start'] = $day;
          $fields['window_ideal'] = $day;
          $fields['window_end'] = $end;
        }
      } elseif ($c['status'] === 'planned' && $status === 'planned' && $row['note'] !== '' && empty($c['needs']) && !empty($row['flags']['needs'])) {
        $fields['note'] = ''; // photos arrived: the "waiting for photos" note goes
      }
      if ($c['status'] === 'planned' && !empty($c['needs'])) $fields['note'] = $c['note'];

      // The listing changed under a draft. One nobody touched is rewritten on the next run; one a
      // person edited, or one already stopped for a person, stops (again) for that person.
      if (!empty($row['content']['text']) && $row['facts_hash'] !== '' && empty($row['flags']['stale']) && in_array($status, ['planned', 'held', 'waiting'], true)) {
        $hash = ias_facts_hash(['type' => $c['type'], 'flags' => $flags], $facts_of($fields['listing_ids']));
        if ($hash !== $row['facts_hash']) {
          $flags['stale'] = true;
          if ($row['status'] === 'waiting' && function_exists('ias_approval_void')) ias_approval_void((int) $row['id']); // old links must not approve old facts
          $edited = !empty($row['flags']['edited_by']);
          $in_oneup = !empty($row['locked']); // a person changed it in OneUp: only they can fix it, there
          if ($edited || $in_oneup || $status === 'held') {
            $why = ias_stale_hold($edited, $in_oneup);
            $flags['holds'] = array_values(array_unique(array_merge([$why], (array) ($flags['holds'] ?? []))));
            unset($flags['released_by']);
            $status = 'held';
            $fields['note'] = 'Needs a decision: ' . $why;
            $trail = $trail !== '' ? $trail : 'Needs a decision: ' . $why;
          }
        }
      }

      $fields['flags'] = $flags;
      $fields['status'] = $status;
      $changed = false;
      foreach ($fields as $k => $v) {
        $old = $row[$k] ?? null;
        if ($k === 'listing_ids') { $changed = $changed || $old !== $v; continue; }
        if ($k === 'flags') { $changed = $changed || wp_json_encode($old) !== wp_json_encode($v); continue; }
        if ((string) $old !== (string) $v) $changed = true;
      }
      if ($changed) {
        ias_post_update((int) $row['id'], $fields);
        $sum['updated']++;
      }
      if ($trail !== '') {
        ias_post_trail((int) $row['id'], $trail);
        ias_log($status === 'planned' ? 'planned' : $status, (int) $row['listing_id'], ['post' => (int) $row['id'], 'type' => $row['type'], 'summary' => $trail]);
      }
    }

    // Posts no longer called for.
    foreach (ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved'], 'limit' => 2000]) as $row) {
      if ($row['type'] === 'human' || isset($cands[$row['slot_key']])) continue;
      $gone = false; $held = '';
      foreach ((array) $row['listing_ids'] as $id) {
        $p = get_post((int) $id);
        if (!$p || $p->post_status !== 'publish' || $p->post_password !== '') $gone = true;
        elseif (get_post_meta((int) $id, '_ias_posts_cancelled', true) !== '') $held = (string) get_post_meta((int) $id, '_ias_posts_cancelled', true);
      }
      $why = $gone ? 'The listing was unpublished, made private, password-protected or deleted.'
        : ($held !== '' ? 'Cancel posts was pressed on the listing (' . $held . ').' : 'No longer called for — the listing\'s dates, open houses or stage changed.');
      ias_post_cancel($row, $why, 'system');
      $sum['cancelled']++;
    }
    return $sum;
  }
}

/**
 * Withdraw an approval that no longer fits: $c is the post's candidate now.
 * The post takes the candidate's listings and window; a draft nobody edited
 * is rewritten on the next run and sent for a fresh yes, while one a person
 * edited stops for that person (their words are never rewritten behind their
 * back). The old links stop working.
 */
if (!function_exists('ias_approval_withdraw')) {
  function ias_approval_withdraw(array $row, array $c, string $what): void {
    $flags = array_merge($row['flags'], ['meta' => $c['meta'], 'needs' => $c['needs'], 'stale' => true]);
    $edited = !empty($row['flags']['edited_by']);
    if ($edited) {
      $why = ias_stale_hold(true);
      $flags['holds'] = array_values(array_unique(array_merge([$why], (array) ($flags['holds'] ?? []))));
      unset($flags['released_by']);
    }
    if (function_exists('ias_approval_void')) ias_approval_void((int) $row['id']);
    $fields = ias_candidate_fields($c);
    if (function_exists('ias_post_sent') && ias_post_sent($row)['mode'] === 'live') { // on OneUp: newcomers get their own post
      $keep = array_values(array_intersect(array_map('intval', $c['listing_ids']), array_map('intval', $row['listing_ids'])));
      if ($keep) { $fields['listing_ids'] = $keep; $fields['listing_id'] = $keep[0]; }
    }
    ias_post_update((int) $row['id'], $fields + [
      'status'      => $edited ? 'held' : 'planned',
      'flags'       => $flags,
      'note'        => $edited ? 'Needs a decision: ' . $flags['holds'][0] : $what . ' after it was approved: it is rewritten and sent for approval again.',
      'approved_at' => null,
      'approved_by' => '',
    ]);
    ias_post_trail((int) $row['id'], $what . ' after approval — ' . ($edited ? 'it needs a decision, then a fresh yes' : 'back to be rewritten and approved again'));
    ias_log('planned', (int) $row['listing_id'], ['post' => (int) $row['id'], 'type' => $row['type'], 'summary' => 'Approval withdrawn: ' . lcfirst($what) . ' after it was approved'], 'system');
  }
}

/** Cancel one post (by the system, or by a person: then the sync never brings it back). */
if (!function_exists('ias_post_cancel')) {
  function ias_post_cancel(array $row, string $why, string $by = 'system'): void {
    $flags = $row['flags'];
    $flags['cancelled_by'] = $by === 'system' ? 'system' : 'person';
    ias_post_update((int) $row['id'], ['status' => 'cancelled', 'note' => $why, 'flags' => $flags]);
    ias_post_trail((int) $row['id'], 'Cancelled: ' . $why, $by);
    ias_log('cancelled', (int) $row['listing_id'], ['post' => (int) $row['id'], 'type' => $row['type'], 'summary' => $why, 'was' => $row['status']], $by);
    /** Phase 5 removes a pushed post from OneUp here. */
    do_action('ias_post_cancelled', (int) $row['id'], $row);
  }
}

/* ---------------- Drafts ---------------- */

/** Posts that need a draft written now, most urgent first. */
if (!function_exists('ias_drafts_due')) {
  function ias_drafts_due(): array {
    $horizon = ias_now()->setTime(0, 0)->modify('+' . (int) ias_setting('plan_days', 30) . ' days')->format('Y-m-d');
    $due = [];
    foreach (ias_posts_query(['status' => ['planned', 'held', 'waiting'], 'limit' => 2000]) as $p) {
      if ($p['type'] === 'human' || !empty($p['flags']['needs'])) continue;
      if (!empty($p['flags']['gen_error_at']) && (int) $p['flags']['gen_error_at'] > time() - 6 * HOUR_IN_SECONDS) continue;
      $ideal = (string) ($p['window_ideal'] ?? '');
      if ($ideal === '' || $ideal > $horizon) continue;
      $has = !empty($p['content']['text']);
      // A stale draft is rewritten on the next run — at most once an hour, so a listing edited
      // five times in an afternoon costs a handful of calls, not one per save.
      $stale = !empty($p['flags']['stale']) && empty($p['flags']['edited_by']) && empty($p['locked'])
        && strtotime((string) $p['generated_at']) < strtotime(ias_now_sql()) - HOUR_IN_SECONDS;
      if ($has && !$stale) continue;
      if ($p['status'] === 'held' && $has) continue; // a held draft waits for its person
      // The roundup is written on its Friday, once the week's announcements are in.
      if ($p['type'] === 'roundup' && ias_now()->format('Y-m-d') < (string) $p['window_start']) continue;
      $due[] = $p;
    }
    usort($due, function ($a, $b) {
      return [$a['lane'] === 'A' ? 0 : 1, (string) $a['window_ideal'], -$a['score']] <=> [$b['lane'] === 'A' ? 0 : 1, (string) $b['window_ideal'], -$b['score']];
    });
    return $due;
  }
}

/** Write up to $limit drafts, stopping early after $seconds so a run never outlasts the host. */
if (!function_exists('ias_drafts_run')) {
  function ias_drafts_run(int $limit, int $seconds = 150): array {
    $out = ['written' => 0, 'held' => 0, 'failed' => 0, 'left' => 0, 'errors' => []];
    if ($limit <= 0) return $out;
    $due = ias_drafts_due();
    $started = microtime(true);
    foreach ($due as $i => $p) {
      if ($i >= $limit || microtime(true) - $started > $seconds) { $out['left'] = count($due) - $i; break; }
      $r = ias_generate_post((int) $p['id']);
      if (!$r['ok']) {
        $out['failed']++;
        $out['errors'][] = $r['error'];
        if (stripos((string) $r['error'], 'budget') !== false || stripos((string) $r['error'], 'API key') !== false) {
          $out['left'] = count($due) - $i - 1;
          break; // every other draft would fail the same way
        }
        continue;
      }
      $out['written']++;
      if (!empty($r['holds'])) $out['held']++;
    }
    $out['errors'] = array_values(array_unique($out['errors']));
    return $out;
  }
}

/* ---------------- The hourly job ---------------- */

add_filter('ia_cron_jobs', function (array $jobs): array {
  $jobs['ias_social'] = [
    'label'    => 'Intel Auctions Social: plan, drafts and approvals',
    'interval' => HOUR_IN_SECONDS,
    'callback' => 'ias_job_run',
  ];
  return $jobs;
});

/**
 * One run: sync, then drafts; later phases add the morning plan, the approval
 * emails and the Monday review through `ias_job_run`. The result (and why a
 * part skipped) goes to the toolkit's job log.
 */
if (!function_exists('ias_job_run')) {
  function ias_job_run(): array {
    ias_db_migrate();
    if (!ias_db_ready()) return ['skipped' => ['Intel Auctions Social tables are not ready']];
    $sync = ias_sync();
    $drafts = ias_drafts_run((int) ias_setting('generate_per_run', 8));
    $result = ['sync' => $sync, 'drafts' => $drafts, 'errors' => $drafts['errors']];
    /** Later phases add their steps (plan, approvals, the Monday email) and their results. */
    $result = (array) apply_filters('ias_job_run', $result);
    ias_log('run', 0, ['summary' => ias_run_summary($result)]);
    return $result;
  }
}

/** One line for the log: what a run did. */
if (!function_exists('ias_run_summary')) {
  function ias_run_summary(array $r): string {
    $s = $r['sync'] ?? [];
    $d = $r['drafts'] ?? [];
    $bits = [];
    $bits[] = (int) ($s['listings'] ?? 0) . ' listings';
    foreach (['added' => 'added', 'revived' => 'back', 'cancelled' => 'cancelled'] as $k => $label) if (!empty($s[$k])) $bits[] = (int) $s[$k] . ' ' . $label;
    if (!empty($d['written'])) $bits[] = (int) $d['written'] . ' drafts' . (!empty($d['held']) ? ' (' . (int) $d['held'] . ' held)' : '');
    if (!empty($d['failed'])) $bits[] = (int) $d['failed'] . ' drafts failed';
    if (!empty($d['left'])) $bits[] = (int) $d['left'] . ' drafts waiting';
    foreach ((array) ($r['parts'] ?? []) as $part) $bits[] = (string) $part;
    return implode(', ', $bits);
  }
}
