<?php
/**
 * Intel Auctions Social — Phase 5: OneUp live.
 *
 * Everything here runs only when the switches allow it (oneup.php:
 * ias_oneup_blockers(), ias_push_blockers()); with the master switch off the
 * plugin never calls OneUp at all.
 *
 *   Sending      ias_push_live_send() schedules a post (taking an older copy
 *                off first) and keeps what it sent in `flags.oneup`: the
 *                OneUp post id, the request's hash, the text and time, and
 *                the facts it carried. ias_push_live_delete() takes it back.
 *                Going live clears the dry run's record, so nothing counts as
 *                sent that was not.
 *   Cancelling   a cancelled post that is on OneUp is taken off at once.
 *   Read-back    every hourly run lists the Facebook page's scheduled,
 *                published and failed posts in OneUp: a post a person moved or
 *                edited there is adopted and locked (the plan works around it
 *                and never replaces it), one a person deleted there stays off
 *                the plan, published ones are confirmed, failed ones stop for
 *                a decision, and posts a person scheduled there themselves
 *                become Human posts the plan works around. Posts on the
 *                category's other accounts (Google Business Profile, YouTube)
 *                are not Facebook and are ignored.
 *   Verify       a safe round trip from Settings — a draft that never
 *                publishes is saved, read back and deleted — to check the
 *                endpoints and the time zone before any switch is turned on.
 *   Corrections  a published post is never edited (editing resets its reach):
 *                when a fact it carried changes, a drafted comment waits for a
 *                person to post.
 */

if (!defined('ABSPATH')) exit;

/* ---------------- Mode ---------------- */

/** Note the push mode; going live clears the dry run's record of what it "sent". */
if (!function_exists('ias_push_mode_changed')) {
  function ias_push_mode_changed(string $mode): void {
    $last = (string) get_option('ias_push_mode', 'dry');
    if ($last === $mode) return;
    update_option('ias_push_mode', $mode, false);
    if ($mode !== 'live') {
      ias_log('run', 0, ['summary' => 'Back to the dry run: nothing more is sent to OneUp. Posts already scheduled there stay there until they are deleted in OneUp.']);
      return;
    }
    $n = 0;
    foreach (ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'dropped', 'skipped', 'cancelled'], 'limit' => 2000]) as $p) {
      if (empty($p['flags']['dry_hash'])) continue;
      $flags = $p['flags'];
      unset($flags['dry_hash']);
      ias_post_update((int) $p['id'], ['flags' => $flags]);
      $n++;
    }
    ias_log('run', 0, ['summary' => 'Live pushing is on: the dry run\'s record of ' . $n . ' posts was cleared, so each goes to OneUp for real (outside the 48-hour window).']);
  }
}

/* ---------------- Sending ---------------- */

/** The facts a correction compares, from the post's listings now. */
if (!function_exists('ias_correction_facts')) {
  function ias_correction_facts(array $p): array {
    $out = [];
    foreach (ias_post_facts($p) as $f) {
      $pf = ias_facts_for_prompt($f);
      $one = [];
      foreach (['auction_date' => 'the auction date', 'auction_time' => 'the start time', 'starting_bid' => 'the starting bid', 'sale_price' => 'the sale price', 'address' => 'the address', 'lead_phone' => 'the phone number'] as $k => $label) {
        if (isset($pf[$k]) && $pf[$k] !== '') $one[$label] = (string) $pf[$k];
      }
      if (!empty($pf['open_houses'])) $one['the open house'] = implode('; ', (array) $pf['open_houses']);
      $out[(string) (int) $f['id']] = ['name' => (string) ($pf['street'] ?? $pf['property'] ?? ''), 'facts' => $one];
    }
    return $out;
  }
}

/** Did OneUp's answer to a delete mean "it is not there" (so the delete is done)? */
if (!function_exists('ias_oneup_gone')) {
  function ias_oneup_gone(array $r): bool {
    return $r['code'] === 404 || (bool) preg_match('/not\s*found|does\s*not\s*exist|no\s*such|already\s*deleted/i', (string) $r['error']);
  }
}

/**
 * Schedule one post on OneUp (taking its older copy off first). Returns true
 * when it is on OneUp now. A third failure in a row marks the post Failed, for
 * a person.
 */
if (!function_exists('ias_push_live_send')) {
  function ias_push_live_send(array $p, array $req, string $hash): bool {
    $id = (int) $p['id'];
    $flags = $p['flags'];
    $old = ias_post_sent($p);
    $when = date_i18n('D M j, g:i a', strtotime((string) $p['scheduled_at']));

    if ($old['mode'] === 'live' && $old['id'] !== '') {
      $d = ias_oneup_call('delete', ['post_id' => $old['id']]);
      if (!$d['ok'] && !ias_oneup_gone($d)) {
        ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Could not take the older copy off OneUp (post #' . $old['id'] . '), so the new one was not sent: ' . $d['error']]);
        return false;
      }
      unset($flags['oneup']);
      $flags['was_on_oneup'] = true; // if the new copy is refused, retrying it is a replace, not a new post inside the veto window
      ias_post_update($id, ['flags' => $flags, 'oneup_post_id' => '']);
    }

    $r = ias_oneup_call((string) $req['endpoint'], (array) $req['params']);
    if (!$r['ok']) {
      $fails = (int) ($flags['push_fails'] ?? 0) + 1;
      $flags['push_fails'] = $fails;
      $flags['push_error'] = $r['error'];
      $fields = ['flags' => $flags];
      if ($fails >= 3) {
        $fields['status'] = 'failed';
        $fields['note'] = 'OneUp refused it three times: ' . $r['error'];
        ias_post_trail($id, 'Failed: OneUp refused it three times — ' . $r['error']);
      }
      ias_post_update($id, $fields);
      ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'OneUp refused the post for ' . $when . ' (try ' . $fails . '): ' . $r['error']]);
      return false;
    }

    $oneup_id = $r['id'] !== '' ? $r['id'] : ias_oneup_find_scheduled($req);
    $flags['oneup'] = [
      'id'    => $oneup_id,
      'hash'  => $hash,
      'at'    => ias_now_sql(),
      'when'  => (string) $req['params']['scheduled_date_time'],
      'text'  => (string) $req['params']['content'],
      'facts' => ias_correction_facts($p),
    ];
    unset($flags['push_fails'], $flags['push_error'], $flags['was_on_oneup']);
    ias_post_update($id, ['flags' => $flags, 'oneup_post_id' => mb_substr($oneup_id, 0, 64), 'pushed_at' => ias_now_sql()]);
    if ($p['type'] === 'listing' && (string) get_option('ias_live_since', '') === '') update_option('ias_live_since', ias_now()->format('Y-m-d'), false);
    ias_post_trail($id, 'Scheduled on OneUp for ' . $when . ($oneup_id !== '' ? ' (post #' . $oneup_id . ')' : ''));
    ias_log('pushed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'oneup' => $oneup_id,
      'summary' => ($old['mode'] === 'live' ? 'Re-scheduled' : 'Scheduled') . ' on OneUp for ' . $when . ($oneup_id === '' ? ' — OneUp gave no post id; the read-back will match it' : '')]);
    return true;
  }
}

/** Find a just-scheduled post on OneUp by its text and time when the answer carried no id ('' = not found). */
if (!function_exists('ias_oneup_find_scheduled')) {
  function ias_oneup_find_scheduled(array $req): string {
    $list = ias_oneup_list('scheduled', 10);
    if (!$list['ok']) return '';
    foreach (array_reverse($list['rows']) as $row) {
      if (ias_norm_text((string) ($row['content'] ?? '')) === ias_norm_text((string) $req['params']['content'])
        && substr((string) ($row['date_time'] ?? ''), 0, 16) === (string) $req['params']['scheduled_date_time']) {
        return (string) ($row['post_id'] ?? '');
      }
    }
    return '';
  }
}

/** Text compared the way OneUp may hand it back (line endings, runs of spaces, trailing space). */
if (!function_exists('ias_norm_text')) {
  function ias_norm_text(string $s): string {
    return trim((string) preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r"], "\n", $s)));
  }
}

/** Take a post back off OneUp. Returns true when it is gone. */
if (!function_exists('ias_push_live_delete')) {
  function ias_push_live_delete(array $p, string $why): bool {
    $id = (int) $p['id'];
    $sent = ias_post_sent($p);
    $flags = $p['flags'];
    if ($sent['id'] === '') {
      unset($flags['oneup']);
      ias_post_update($id, ['flags' => $flags, 'oneup_post_id' => '', 'note' => 'It could not be taken off OneUp automatically (OneUp gave no post id): delete it there by hand.']);
      ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Could not take it off OneUp (' . $why . '): no post id — delete it in OneUp by hand.']);
      return false;
    }
    $d = ias_oneup_call('delete', ['post_id' => $sent['id']]);
    if (!$d['ok'] && !ias_oneup_gone($d)) {
      ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Could not take post #' . $sent['id'] . ' off OneUp (' . $why . '): ' . $d['error'] . ' — tried again next hour.']);
      return false;
    }
    unset($flags['oneup']);
    ias_post_update($id, ['flags' => $flags, 'oneup_post_id' => '']);
    ias_post_trail($id, 'Taken off OneUp: ' . $why);
    ias_log('pushed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'oneup' => $sent['id'], 'summary' => 'Taken off OneUp (' . $why . ')']);
    return true;
  }
}

/** A cancelled post that is on OneUp comes off at once — before the page that cancelled it has finished. */
add_action('ias_post_cancelled', function ($post_id) {
  if (ias_push_mode() !== 'live') return;
  $p = ias_post_get((int) $post_id);
  if ($p && ias_post_sent($p)['mode'] === 'live') ias_push_live_delete($p, 'cancelled: ' . (string) $p['note']);
}, 10, 1);

/* ---------------- Read-back ---------------- */

/** The Facebook page's name in OneUp (how list rows name it), from Settings or looked up once. */
if (!function_exists('ias_oneup_account_name')) {
  function ias_oneup_account_name(): string {
    $name = trim((string) ias_setting('oneup_account_name', ''));
    if ($name !== '') return $name;
    $cat = (string) ias_setting('oneup_category_id', '');
    $acct = (string) ias_setting('oneup_account_id', '');
    if ($cat === '' || $acct === '') return '';
    $data = ias_oneup_get('accounts', ['category_id' => $cat]);
    if (is_wp_error($data)) return '';
    foreach (ias_oneup_pairs($data, ['social_network_id', 'id'], ['social_network_name', 'username', 'name']) as [$aid, $aname]) {
      if ((string) $aid === $acct && $aname !== '') {
        ias_settings_update(['oneup_account_name' => $aname]);
        return $aname;
      }
    }
    return '';
  }
}

/** A OneUp time as site time ('Y-m-d H:i:s'). Scheduled times are the account's own (= the site's); published ones are UTC. */
if (!function_exists('ias_oneup_time')) {
  function ias_oneup_time(string $raw, bool $utc): string {
    if ($raw === '' || $raw === 'NA') return '';
    try {
      $dt = new DateTimeImmutable($raw, $utc ? new DateTimeZone((string) apply_filters('ias_oneup_published_tz', 'UTC')) : ia_wp_tz());
      return $dt->setTimezone(ia_wp_tz())->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
      return '';
    }
  }
}

/** Record (or update) a post a person scheduled or published on the page through OneUp. */
if (!function_exists('ias_human_upsert')) {
  function ias_human_upsert(string $oneup_id, string $at, string $text): void {
    if ($oneup_id === '' || $at === '') return;
    $slot = 'human:' . $oneup_id;
    $row = ias_post_by_slot($slot);
    $fields = [
      'scheduled_at' => $at, 'window_start' => substr($at, 0, 10), 'window_ideal' => substr($at, 0, 10), 'window_end' => substr($at, 0, 10),
      'content' => ['text' => $text, 'subject' => mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 70)],
    ];
    if ($row) {
      if ((string) $row['scheduled_at'] !== $at || (string) ($row['content']['text'] ?? '') !== $text) ias_post_update((int) $row['id'], $fields);
      return;
    }
    $id = ias_post_insert($fields + [
      'slot_key' => $slot, 'type' => 'human', 'status' => 'human', 'lane' => '', 'listing_id' => 0, 'listing_ids' => [],
      'guaranteed' => 1, 'weight' => 0, 'score' => 0, 'county' => '', 'division' => '', 'oneup_post_id' => mb_substr($oneup_id, 0, 64), 'flags' => [],
    ]);
    if ($id) ias_log('readback', 0, ['post' => $id, 'type' => 'human', 'summary' => 'A person\'s post on the page (OneUp #' . $oneup_id . ', ' . date_i18n('D M j, g:i a', strtotime($at)) . ') — the plan works around it.']);
  }
}

/**
 * The hourly read-back: what OneUp holds for the Facebook page. Returns
 * ['ok', 'error', 'changes' => [human-readable bits]].
 */
if (!function_exists('ias_readback_run')) {
  function ias_readback_run(): array {
    $out = ['ok' => false, 'error' => '', 'changes' => []];
    $block = ias_oneup_blockers(false);
    if ($block) { $out['error'] = implode('; ', $block); return $out; }
    $account = ias_oneup_account_name();
    if ($account === '') { $out['error'] = 'the Facebook page\'s name in OneUp is unknown (run Check connection)'; return ias_readback_note($out); }
    $sched = ias_oneup_list('scheduled', 10);
    if (!$sched['ok']) { $out['error'] = $sched['error']; return ias_readback_note($out); }
    $pub = ias_oneup_list('published', 2);
    $fail = ias_oneup_list('failed', 1);
    $mine = function (array $row) use ($account) { return trim((string) ($row['social_network_username'] ?? '')) === $account; };
    $now = ias_now_sql();
    $count = ['moved' => 0, 'edited' => 0, 'deleted' => 0, 'published' => 0, 'failed' => 0, 'human' => 0];

    $ours = [];
    foreach (ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'pushed', 'published', 'failed'], 'limit' => 2000]) as $p) {
      if ($p['type'] === 'human') continue;
      $sent = ias_post_sent($p);
      if ($sent['mode'] === 'live' && $sent['id'] !== '') $ours[$sent['id']] = $p;
    }

    // Scheduled.
    $seen = [];
    foreach ($sched['rows'] as $row) {
      if (!$mine($row)) continue;
      $oid = (string) ($row['post_id'] ?? '');
      if ($oid === '') continue;
      $seen[$oid] = true;
      $at = ias_oneup_time((string) ($row['date_time'] ?? ''), false);
      $text = (string) ($row['content'] ?? '');
      if (!isset($ours[$oid])) { ias_human_upsert($oid, $at, $text); $count['human']++; continue; }
      $p = $ours[$oid];
      $o = (array) $p['flags']['oneup'];
      $moved = $at !== '' && substr($at, 0, 16) !== substr(str_replace('T', ' ', (string) ($o['when'] ?? '')), 0, 16);
      $edited = ias_norm_text($text) !== ias_norm_text((string) ($o['text'] ?? ''));
      if (!$moved && !$edited) continue;
      $flags = $p['flags'];
      $flags['oneup']['when'] = substr($at, 0, 16);
      $flags['oneup']['text'] = $text;
      $flags['edited_in_oneup'] = trim(($moved ? 'moved' : '') . ($moved && $edited ? ' and ' : '') . ($edited ? 'edited' : ''));
      $fields = ['flags' => $flags, 'locked' => 1, 'note' => ($moved ? 'Moved' : 'Edited') . ' in OneUp by a person — the plugin leaves it as they made it.'];
      if ($moved) $fields['scheduled_at'] = $at;
      ias_post_update((int) $p['id'], $fields);
      $what = ($moved ? 'moved to ' . date_i18n('D M j, g:i a', strtotime($at)) : '') . ($moved && $edited ? ' and ' : '') . ($edited ? 'its text edited' : '');
      ias_post_trail((int) $p['id'], 'In OneUp a person ' . $what . ' — kept as they made it');
      ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'A person ' . $what . ' in OneUp — the plan keeps it and works around it.']);
      $count[$moved ? 'moved' : 'edited']++;
    }

    // Published.
    $seen_pub = [];
    foreach ($pub['ok'] ? $pub['rows'] : [] as $row) {
      if (!$mine($row)) continue;
      $oid = (string) ($row['post_id'] ?? '');
      if ($oid === '') continue;
      $seen_pub[$oid] = true;
      $at = ias_oneup_time((string) ($row['created_at'] ?? $row['date_time'] ?? ''), true);
      if (isset($ours[$oid])) {
        $p = $ours[$oid];
        if ($p['status'] === 'published' && empty($p['flags']['unconfirmed'])) continue;
        $flags = $p['flags'];
        unset($flags['unconfirmed']);
        ias_post_update((int) $p['id'], ['status' => 'published', 'flags' => $flags, 'published_at' => $at !== '' ? $at : $now, 'note' => '']);
        ias_post_trail((int) $p['id'], 'Published on Facebook' . ($at !== '' ? ' ' . date_i18n('D M j, g:i a', strtotime($at)) : ''));
        ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'Published on Facebook (OneUp #' . $oid . ').']);
        $count['published']++;
      } elseif ($at !== '' && $at >= ias_now()->modify('-2 days')->format('Y-m-d H:i:s')) {
        ias_human_upsert($oid, $at, (string) ($row['content'] ?? '')); // today's posts count toward today's three
      }
    }

    // Failed.
    $seen_fail = [];
    foreach ($fail['ok'] ? $fail['rows'] : [] as $row) {
      if (!$mine($row)) continue;
      $oid = (string) ($row['post_id'] ?? '');
      $seen_fail[$oid] = true;
      if (!isset($ours[$oid]) || $ours[$oid]['status'] === 'failed') continue;
      $p = $ours[$oid];
      $why = (string) ($row['failure_reason'] ?? $row['reason'] ?? $row['error'] ?? $row['message'] ?? 'no reason given');
      ias_post_update((int) $p['id'], ['status' => 'failed', 'note' => 'OneUp could not publish it: ' . $why . ' — fix it in OneUp (retry there) or skip it here.']);
      ias_post_trail((int) $p['id'], 'Failed on OneUp: ' . $why);
      ias_log('push_failed', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'OneUp could not publish it: ' . $why]);
      $count['failed']++;
    }

    // Ours that vanished before their time: a person deleted them in OneUp. Only when the whole list was read.
    if ($sched['complete']) {
      $grace = ias_now()->modify('-10 minutes')->format('Y-m-d H:i:s');
      foreach ($ours as $oid => $p) {
        if (!in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'pushed'], true)) continue;
        if (isset($seen[$oid]) || isset($seen_pub[$oid]) || isset($seen_fail[$oid])) continue;
        if ((string) $p['scheduled_at'] <= $now || (string) ($p['pushed_at'] ?? '') > $grace) continue;
        $flags = $p['flags'];
        unset($flags['oneup']);
        $flags['skipped_by'] = 'person';
        ias_post_update((int) $p['id'], ['status' => 'skipped', 'flags' => $flags, 'oneup_post_id' => '', 'locked' => 0, 'note' => 'Deleted in OneUp by a person — it stays off the plan until you press Restore.']);
        ias_post_trail((int) $p['id'], 'Deleted in OneUp by a person');
        ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'Deleted in OneUp by a person — kept off the plan.']);
        $count['deleted']++;
      }
      // A person's scheduled post that is gone (and did not go out) leaves the plan.
      foreach (ias_posts_query(['status' => 'human', 'limit' => 500]) as $h) {
        $oid = (string) $h['oneup_post_id'];
        if ($oid === '' || (string) $h['scheduled_at'] <= $now || isset($seen[$oid]) || isset($seen_pub[$oid])) continue;
        global $wpdb;
        $wpdb->delete(ias_table('posts'), ['id' => (int) $h['id']]);
      }
    }

    $out['ok'] = true;
    foreach (['moved' => 'moved in OneUp', 'edited' => 'edited in OneUp', 'deleted' => 'deleted in OneUp', 'published' => 'published', 'failed' => 'failed'] as $k => $label) {
      if ($count[$k]) $out['changes'][] = $count[$k] . ' ' . $label;
    }
    return ias_readback_note($out);
  }
}

/** Remember the last read-back (Settings and the calendar show it). */
if (!function_exists('ias_readback_note')) {
  function ias_readback_note(array $out): array {
    update_option('ias_readback_last', ['at' => ias_now_sql(), 'ok' => $out['ok'], 'error' => $out['error'], 'changes' => $out['changes']], false);
    if (!$out['ok'] && $out['error'] !== '') ias_log('readback', 0, ['summary' => 'Read-back from OneUp failed: ' . $out['error']]);
    return $out;
  }
}

/* ---------------- Verify OneUp ---------------- */

/**
 * The safe round trip (Settings → Verify OneUp): the category and the page,
 * a draft saved (drafts never publish), read back, its time compared, then
 * deleted; the scheduled and published lists read. Each step is recorded in
 * `ias_oneup_verify`. Needs the key and the ids, not the master switch: it
 * changes nothing anyone can see.
 */
if (!function_exists('ias_oneup_verify')) {
  function ias_oneup_verify(string $by): array {
    $steps = [];
    $step = function (string $label, ?bool $ok, string $detail) use (&$steps) { $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail]; return $ok; };
    $cat = (string) ias_setting('oneup_category_id', '');
    $acct = (string) ias_setting('oneup_account_id', '');
    $tz_offset = null;
    if (ias_oneup_key() === '' || $cat === '' || $acct === '') {
      $step('Key and ids', false, 'Set INTEL_ONEUP_API_KEY in wp-config and the category and Facebook account ids below first.');
      return ias_oneup_verify_save($steps, $by, null);
    }

    // 1. The page.
    $accts = ias_oneup_get('accounts', ['category_id' => $cat]);
    $name = '';
    $type = '';
    if (!is_wp_error($accts)) {
      foreach (ias_oneup_pairs($accts, ['social_network_id', 'id'], ['social_network_name', 'username', 'name']) as [$aid, $aname, $row]) {
        if ((string) $aid === $acct) { $name = $aname; $type = (string) ($row['social_network_type'] ?? $row['type'] ?? ''); }
      }
    }
    if (!$step('The Facebook page', $name !== '' && strcasecmp($type, 'facebook') === 0,
      is_wp_error($accts) ? 'OneUp did not list the category\'s accounts: ' . $accts->get_error_message()
      : ($name === '' ? 'Account ' . $acct . ' is not in category ' . $cat . '.' : $name . ' (' . ($type !== '' ? $type : 'type not given') . ')' . (strcasecmp($type, 'facebook') === 0 ? '' : ' — that is not a Facebook page.')))) {
      return ias_oneup_verify_save($steps, $by, null);
    }
    ias_settings_update(['oneup_account_name' => $name]);

    // 2. A draft.
    $when = ias_now()->modify('+7 days')->setTime(9, 17)->format('Y-m-d H:i');
    $text = 'Intel Auctions Social connection test ' . wp_generate_password(6, false) . ' — a draft: it never publishes, and the plugin deletes it straight away.';
    $r = ias_oneup_call('schedule_text', ['category_id' => $cat, 'social_network_id' => $acct, 'scheduled_date_time' => $when, 'content' => $text, 'isDraftPost' => 'true']);
    if (!$step('Save a draft (scheduletextpost, as a draft)', $r['ok'], $r['ok'] ? 'OneUp accepted it' . ($r['id'] !== '' ? ' as post #' . $r['id'] : ' (its answer carried no post id — found below by its text)') . '.' : $r['error'])) {
      return ias_oneup_verify_save($steps, $by, null);
    }

    // 3. Read it back.
    $drafts = ias_oneup_list('drafts', 5);
    $found = null;
    foreach ($drafts['rows'] as $row) if (ias_norm_text((string) ($row['content'] ?? '')) === ias_norm_text($text)) $found = $row;
    $draft_id = $r['id'] !== '' ? $r['id'] : (string) ($found['post_id'] ?? '');
    if ($found) {
      $back = substr((string) ($found['date_time'] ?? ''), 0, 16);
      if ($back !== '') {
        try { $tz_offset = (int) round(((new DateTimeImmutable($back))->getTimestamp() - (new DateTimeImmutable($when))->getTimestamp()) / 60); } catch (\Exception $e) { $tz_offset = null; }
      }
      $step('Read the draft back (getdraftposts)', true, 'Found as post #' . (string) ($found['post_id'] ?? '?') . '.');
      $step('Time zone', $tz_offset === 0 ? true : ($tz_offset === null ? null : false), $tz_offset === 0 ? 'OneUp kept ' . $when . ' — its account time zone matches the site\'s.'
        : ($tz_offset === null ? 'OneUp did not say the draft\'s time.' : 'Sent ' . $when . ', OneUp shows ' . $back . ': its account time zone is ' . round($tz_offset / 60, 1) . ' hours off the site\'s. Set OneUp\'s time zone to the site\'s before going live.'));
    } else {
      $step('Read the draft back (getdraftposts)', false, $drafts['ok'] ? 'The draft was not in OneUp\'s draft list.' : $drafts['error']);
    }

    // 4. Delete it.
    if ($draft_id !== '') {
      $d = ias_oneup_call('delete', ['post_id' => $draft_id]);
      $again = ias_oneup_list('drafts', 5);
      $still = false;
      foreach ($again['rows'] as $row) if ((string) ($row['post_id'] ?? '') === $draft_id) $still = true;
      $step('Delete it (deletepost)', $d['ok'] && !$still, $d['ok'] ? ($still ? 'OneUp answered, but the draft is still there.' : 'Deleted.') : $d['error'] . ' — delete the test draft in OneUp by hand.');
    } else {
      $step('Delete it (deletepost)', false, 'No post id to delete with — delete the test draft in OneUp by hand.');
    }

    // 5. The lists the read-back uses.
    foreach (['scheduled' => 'getscheduledposts', 'published' => 'getpublishedposts', 'failed' => 'getfailedposts'] as $ep => $path) {
      $l = ias_oneup_list($ep, 1);
      $step('Read the ' . $ep . ' posts (' . $path . ')', $l['ok'], $l['ok'] ? count($l['rows']) . ' on the first page.' : $l['error']);
    }
    return ias_oneup_verify_save($steps, $by, $tz_offset);
  }
}

if (!function_exists('ias_oneup_verify_save')) {
  function ias_oneup_verify_save(array $steps, string $by, ?int $tz_offset): array {
    $ok = (bool) $steps && !in_array(false, array_column($steps, 'ok'), true);
    $result = ['at' => ias_now_sql(), 'by' => $by, 'ok' => $ok, 'steps' => $steps, 'tz_offset' => $tz_offset];
    update_option('ias_oneup_verify', $result, false);
    ias_log('readback', 0, ['summary' => 'Verify OneUp: ' . ($ok ? 'every step passed' : 'a step failed — see Settings')], $by);
    return $result;
  }
}

/* ---------------- Corrections ---------------- */

/**
 * A published post is never edited. When a fact it carried (the date, the
 * time, the starting bid, the sale price, the address, the phone, an open
 * house) has changed since it went out, a drafted comment waits for a person
 * to post on it (`flags.correction`). Returns the drafted text, '' when
 * nothing it said has changed.
 */
if (!function_exists('ias_correction_text')) {
  function ias_correction_text(array $p): string {
    $then = (array) ($p['flags']['oneup']['facts'] ?? []);
    if (!$then) return '';
    $now = ias_correction_facts($p);
    $lines = [];
    foreach ($then as $lid => $was) {
      $is = (array) ($now[$lid]['facts'] ?? []);
      foreach ((array) $was['facts'] as $label => $old) {
        $new = $is[$label] ?? '';
        if ($new === $old) continue;
        $who = count($then) > 1 && $was['name'] !== '' ? ' for ' . $was['name'] : '';
        $lines[] = $new === '' ? ucfirst($label) . $who . ' no longer applies (it was ' . $old . ').' : ucfirst($label) . $who . ' is now ' . $new . ' (not ' . $old . ').';
      }
    }
    return $lines ? 'Update: ' . implode(' ', $lines) : '';
  }
}

/** Check the published posts for facts that changed since they went out (from the sync). */
if (!function_exists('ias_corrections_check')) {
  function ias_corrections_check(): int {
    $n = 0;
    $since = ias_now()->modify('-60 days')->format('Y-m-d H:i:s');
    foreach (ias_posts_query(['status' => 'published', 'limit' => 500]) as $p) {
      if ($p['type'] === 'human' || !empty($p['flags']['dry_run']) || empty($p['flags']['oneup']['facts'])) continue;
      if ((string) $p['published_at'] < $since) continue;
      $text = ias_correction_text($p);
      $have = (array) ($p['flags']['correction'] ?? []);
      if ($text === '' || ($have['text'] ?? '') === $text) continue;
      $flags = $p['flags'];
      $flags['correction'] = ['text' => $text, 'at' => ias_now_sql(), 'done' => ''];
      ias_post_update((int) $p['id'], ['flags' => $flags]);
      ias_post_trail((int) $p['id'], 'A fact changed after it went out — a correction comment is drafted');
      ias_log('edited', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'Correction needed as a comment: ' . $text]);
      $n++;
    }
    return $n;
  }
}

/** Published posts with a correction waiting for a person. */
if (!function_exists('ias_corrections_waiting')) {
  function ias_corrections_waiting(): array {
    return array_values(array_filter(ias_posts_query(['status' => 'published', 'limit' => 500]), function ($p) {
      return !empty($p['flags']['correction']['text']) && empty($p['flags']['correction']['done']);
    }));
  }
}

/** The hourly job: corrections for published posts whose facts changed (after the plan and the pushes). */
add_filter('ias_job_run', function (array $result): array {
  $n = ias_corrections_check();
  if ($n) $result['parts'][] = $n . ' correction' . ($n === 1 ? '' : 's') . ' to post as a comment';
  return $result;
}, 30);
