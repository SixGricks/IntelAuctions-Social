<?php
/**
 * Intel Auctions Social — Phase 5: OneUp live.
 *
 * Everything here runs only when the switches allow it (oneup.php:
 * ias_oneup_blockers(), ias_push_blockers()). A switch is on or off (DJ,
 * Oct 14, 2026 — the dry run went in 0.12.0): with the master switch off the
 * plugin sends nothing new and reads nothing, and only takes back what it put
 * on OneUp itself, until none of it is left there.
 *
 *   Sending      ias_push_live_send() schedules a post (taking an older copy
 *                off first) and keeps what it sent in `flags.oneup`: the
 *                OneUp post id, the request's hash, the text and time, and
 *                the facts it carried. ias_push_live_delete() takes it back.
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

/* ---------------- The dry run, retired ---------------- */

/**
 * 0.12.0 took the dry run out (DJ, Oct 14, 2026: "I want to turn dry run off
 * and remove it. Either things are active or inactive."). Once, on the first
 * admin page or hourly run after the update: the stored `dry_run` and
 * Google's separate Send switch go, and Google stays on only where it was
 * really being sent. A site that was in the dry run has every post type and
 * Google switched off here, so the update itself never starts anything
 * posting — a person turns on what should go (the notice under the switches
 * names them, until Settings is next saved). The dry run's records on posts
 * (`dry_hash`, `dry_ch`) go too; `flags.dry_run` stays on the posts whose
 * time came in it, as history: nothing was sent.
 */
if (!function_exists('ias_dry_run_retire')) {
  function ias_dry_run_retire(): void {
    if (get_option('ias_dry_run_retired', '') !== '' || !ias_db_ready()) return;
    $saved = get_option('ias_settings', null);
    $off = [];
    if (is_array($saved)) {
      $was_dry = !array_key_exists('dry_run', $saved) || !empty($saved['dry_run']);
      $g = isset($saved['networks']['gbp']) && is_array($saved['networks']['gbp']) ? $saved['networks']['gbp'] : null;
      if ($was_dry) {
        foreach ((array) ($saved['types'] ?? []) as $type => $on) {
          if (!$on) continue;
          $saved['types'][$type] = false;
          $off[] = (string) $type;
        }
      }
      if ($g !== null) {
        if (!empty($g['on']) && ($was_dry || empty($g['live']))) { $g['on'] = false; $off[] = 'gbp'; } // planned for Google, never really sent: off
        unset($g['live']);
        $saved['networks']['gbp'] = $g;
      }
      unset($saved['dry_run']);
      update_option('ias_settings', $saved, true);
    }
    $n = 0;
    foreach (ias_posts_query(['limit' => 2000, 'order' => 'id']) as $p) {
      $flags = (array) $p['flags'];
      if (!isset($flags['dry_hash']) && !isset($flags['dry_ch'])) continue;
      unset($flags['dry_hash'], $flags['dry_ch']);
      ias_post_update((int) $p['id'], ['flags' => $flags]);
      $n++;
    }
    delete_option('ias_push_mode');
    delete_option('ias_gbp_mode');
    update_option('ias_dry_run_retired', ['at' => ias_now_sql(), 'off' => $off], false);
    $names = array_map(function ($t) { return $t === 'gbp' ? 'Google' : (string) ias_post_type($t)['short']; }, $off);
    ias_log('run', 0, ['summary' => 'The dry run is gone (0.12.0): every switch is on or off now. ' . ($names ? 'Switched off so the update would not start posting: ' . implode(', ', $names) . '. ' : '') . 'The dry run\'s records on ' . $n . ' post' . ($n === 1 ? '' : 's') . ' were cleared.']);
  }
}
add_action('admin_init', function () { if (function_exists('ias_dry_run_retire')) ias_dry_run_retire(); }, 7);

/** What the retirement switched off, while nobody has saved Settings since ([] = nothing to say). */
if (!function_exists('ias_dry_run_switched_off')) {
  function ias_dry_run_switched_off(): array {
    $r = get_option('ias_dry_run_retired', []);
    return is_array($r) ? array_values(array_map('strval', (array) ($r['off'] ?? []))) : [];
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

/** Text compared the way OneUp may hand it back (line endings, runs of spaces, trailing space). */
if (!function_exists('ias_norm_text')) {
  function ias_norm_text(string $s): string {
    return trim((string) preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r"], "\n", $s)));
  }
}

/**
 * OneUp's scheduled list, read once per request and forgotten after every
 * write. $fresh = read it again now.
 */
if (!function_exists('ias_oneup_scheduled')) {
  function ias_oneup_scheduled(bool $fresh = false): array {
    if ($fresh || !isset($GLOBALS['ias_oneup_scheduled'])) $GLOBALS['ias_oneup_scheduled'] = ias_oneup_list('scheduled', 10);
    return $GLOBALS['ias_oneup_scheduled'];
  }
}
if (!function_exists('ias_oneup_scheduled_forget')) {
  function ias_oneup_scheduled_forget(): void { unset($GLOBALS['ias_oneup_scheduled']); }
}

/** A post on OneUp's scheduled list with this text and time: its id, '' when it is not there, null when the list could not be read. */
if (!function_exists('ias_oneup_find_scheduled')) {
  function ias_oneup_find_scheduled(string $text, string $when): ?string {
    $list = ias_oneup_scheduled();
    if (!$list['ok']) return null;
    foreach (array_reverse($list['rows']) as $row) {
      if (ias_norm_text((string) ($row['content'] ?? '')) === ias_norm_text($text) && substr((string) ($row['date_time'] ?? ''), 0, 16) === substr($when, 0, 16)) {
        return (string) ($row['post_id'] ?? '');
      }
    }
    return '';
  }
}

/** Is post $id really not on OneUp's scheduled list (read afresh, completely)? */
if (!function_exists('ias_oneup_confirm_absent')) {
  function ias_oneup_confirm_absent(string $id): bool {
    $list = ias_oneup_scheduled(true);
    if (!$list['ok'] || !$list['complete']) return false;
    foreach ($list['rows'] as $row) if ((string) ($row['post_id'] ?? '') === $id) return false;
    return true;
  }
}

/** Did OneUp's answer to a delete say the post is not there? (Believed only once its list confirms it.) */
if (!function_exists('ias_oneup_gone')) {
  function ias_oneup_gone(array $r): bool {
    if (ias_oneup_no_route($r)) return false; // OneUp has no such address: that says nothing about the post
    return $r['code'] === 404 || (bool) preg_match('/post\s+not\s*found|no\s+such\s+post|already\s+deleted|does\s+not\s+exist/i', (string) $r['error']);
  }
}

/** Did OneUp answer with its "Page Not Found" web page — no such API address — rather than an answer about a post? */
if (!function_exists('ias_oneup_no_route')) {
  function ias_oneup_no_route(array $r): bool {
    return !$r['ok'] && $r['code'] === 404 && !is_array($r['data']);
  }
}

/**
 * Test drafts an earlier Verify could not delete, by their OneUp post ids
 * (`ias_oneup_verify_leftovers`), with the ones a run before this list
 * existed left behind: read from that run's own steps (its draft was found
 * "as post #…, a draft" and its delete failed). Only the plugin's own test
 * drafts are ever on it.
 */
if (!function_exists('ias_oneup_verify_leftovers')) {
  function ias_oneup_verify_leftovers(): array {
    $ids = array_map('strval', (array) get_option('ias_oneup_verify_leftovers', []));
    foreach (['ias_oneup_verify', 'ias_gbp_verify'] as $opt) {
      $v = get_option($opt, []);
      if (!is_array($v) || empty($v['steps'])) continue;
      $failed = false; $id = '';
      foreach ((array) $v['steps'] as $st) {
        if (strpos((string) $st['label'], 'Delete it') === 0 && $st['ok'] !== true) $failed = true;
        if (strpos((string) $st['label'], 'Read the draft back') === 0 && preg_match('/#(\d+), a draft\./', (string) $st['detail'], $m)) $id = $m[1];
      }
      if ($failed && $id !== '') $ids[] = $id;
    }
    return array_values(array_unique(array_filter($ids)));
  }
}

/** A sent post with no OneUp id yet: look for it by its text and time, and keep the id if found ('' = not found). */
if (!function_exists('ias_oneup_resolve_id')) {
  function ias_oneup_resolve_id(array $p): string {
    $o = (array) ($p['flags']['oneup'] ?? []);
    if (!empty($o['id'])) return (string) $o['id'];
    if (empty($o['text']) || empty($o['when'])) return '';
    $found = (string) ias_oneup_find_scheduled((string) $o['text'], (string) $o['when']);
    if ($found === '') return '';
    $flags = $p['flags'];
    $flags['oneup']['id'] = $found;
    unset($flags['manual_delete']);
    ias_post_update((int) $p['id'], ['flags' => $flags, 'oneup_post_id' => mb_substr($found, 0, 64)]);
    ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'oneup' => $found, 'summary' => 'Found on OneUp by its text and time: post #' . $found]);
    return $found;
  }
}

/**
 * OneUp has the post an earlier attempt sent, though its answer was lost
 * (found on the list as $oneup_id): that attempt becomes what was sent.
 * Returns the post as it is now.
 */
if (!function_exists('ias_oneup_adopt_attempt')) {
  function ias_oneup_adopt_attempt(array $p, string $oneup_id): array {
    $id = (int) $p['id'];
    $flags = $p['flags'];
    $try = (array) ($flags['sending'] ?? []);
    unset($flags['sending'], $flags['push_fails'], $flags['push_error'], $flags['was_on_oneup']);
    $flags['oneup'] = ['id' => $oneup_id, 'hash' => (string) ($try['hash'] ?? ''), 'at' => (string) ($try['at'] ?? ias_now_sql()), 'when' => (string) ($try['when'] ?? ''),
      'text' => (string) ($try['text'] ?? ''), 'facts' => (array) ($try['facts'] ?? [])];
    ias_post_update($id, ['flags' => $flags, 'oneup_post_id' => mb_substr($oneup_id, 0, 64), 'pushed_at' => $flags['oneup']['at']]);
    ias_human_forget($oneup_id);
    ias_post_trail($id, 'Found on OneUp after all (its answer was lost): post #' . $oneup_id);
    ias_log('pushed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'oneup' => $oneup_id, 'summary' => 'OneUp had the post after all (its answer was lost): post #' . $oneup_id . ' kept, not sent twice.']);
    return ias_post_get($id) ?: $p;
  }
}

/** A OneUp post taken for a person's is the plugin's own after all: its Human row goes. */
if (!function_exists('ias_human_forget')) {
  function ias_human_forget(string $oneup_id): void {
    $row = $oneup_id !== '' ? ias_post_by_slot('human:' . $oneup_id) : null;
    if (!$row) return;
    global $wpdb;
    $wpdb->delete(ias_table('posts'), ['id' => (int) $row['id']]);
  }
}

/** A person deleted this post in OneUp: it stays off the plan (Skipped, by a person) until someone presses Restore. */
if (!function_exists('ias_live_person_deleted')) {
  function ias_live_person_deleted(array $p, bool $confirmed_gone): void {
    $flags = $p['flags'];
    if ($confirmed_gone) unset($flags['oneup']); // else the take-back confirms it is gone (and takes it off if it is not)
    unset($flags['missing_since'], $flags['was_on_oneup']);
    $flags['skipped_by'] = 'person';
    ias_post_update((int) $p['id'], ['status' => 'skipped', 'flags' => $flags, 'locked' => 0, 'oneup_post_id' => $confirmed_gone ? '' : (string) $p['oneup_post_id'],
      'note' => 'Deleted in OneUp by a person — it stays off the plan until you press Restore.']);
    ias_post_trail((int) $p['id'], 'Deleted in OneUp by a person');
    ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'Deleted in OneUp by a person — kept off the plan.']);
  }
}

/**
 * Schedule one post on OneUp, taking its older copy off first. Returns true
 * when the post is on OneUp as it is now. Never sends twice: an attempt whose
 * answer was lost (no answer, a server error) is looked for on OneUp's list
 * before anything is sent again; an older copy is taken off only by its id;
 * and an older copy that turns out to be gone was deleted by a person, whose
 * delete stands.
 */
if (!function_exists('ias_push_live_send')) {
  function ias_push_live_send(array $p, array $req, string $hash): bool {
    $id = (int) $p['id'];
    $p = ias_post_get($id) ?: $p; // as it is now: another run may have sent it meanwhile
    $flags = $p['flags'];
    $old = ias_post_sent($p);
    if ($old['mode'] === 'live' && $old['hash'] === $hash) return true;
    $when = date_i18n('D M j, g:i a', strtotime((string) $p['scheduled_at']));
    $fail = function (string $summary) use ($p, $id) {
      ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => $summary]);
      return false;
    };

    // An earlier attempt whose answer never came back: if OneUp has it, adopt it instead of sending again.
    if (!empty($flags['sending'])) {
      $try = (array) $flags['sending'];
      $found = ias_oneup_find_scheduled((string) $try['text'], (string) $try['when']);
      if ($found === null) return $fail('Could not read OneUp\'s list to see whether an earlier attempt arrived, so nothing was sent (tried again next hour).');
      if ($found !== '') {
        $p = ias_oneup_adopt_attempt($p, $found);
        $flags = $p['flags'];
        $old = ias_post_sent($p);
        if ($old['hash'] === $hash) return true;
      } else {
        unset($flags['sending']);
        ias_post_update($id, ['flags' => $flags]);
      }
    }

    // A scheduled post's time cannot be edited: the older copy comes off first — only by its id.
    if ($old['mode'] === 'live') {
      $old_id = $old['id'] !== '' ? $old['id'] : ias_oneup_resolve_id($p);
      if ($old_id === '') return $fail('Not replaced yet: OneUp has not given the id of the copy already there, and it cannot be taken off without it (looked for again each hour).');
      $d = ias_oneup_call('delete', ['post_id' => $old_id]);
      ias_oneup_scheduled_forget();
      if (!$d['ok']) {
        if (ias_oneup_gone($d) && ias_oneup_confirm_absent($old_id)) {
          ias_live_person_deleted($p, true); // the plugin did not take it off: a person did, and that stands
          return false;
        }
        return $fail('Could not take the older copy off OneUp (post #' . $old_id . '), so the new one was not sent: ' . $d['error']);
      }
      unset($flags['oneup']);
      $flags['was_on_oneup'] = true; // if the new copy is refused, retrying it is a replace, not a new post inside the veto window
      ias_post_update($id, ['flags' => $flags, 'oneup_post_id' => '']);
    }

    // Send, remembering the attempt until OneUp's answer is in.
    $attempt = ['hash' => $hash, 'when' => (string) $req['params']['scheduled_date_time'], 'text' => (string) $req['params']['content'], 'at' => ias_now_sql(), 'facts' => ias_correction_facts($p)];
    $flags['sending'] = $attempt;
    ias_post_update($id, ['flags' => $flags]);
    $r = ias_oneup_call((string) $req['endpoint'], (array) $req['params']);
    ias_oneup_scheduled_forget();
    if (!$r['ok']) {
      if (!ias_oneup_uncertain($r)) unset($flags['sending']); // a clear refusal: nothing is on OneUp
      $fails = (int) ($flags['push_fails'] ?? 0) + 1;
      $flags['push_fails'] = $fails;
      $flags['push_error'] = $r['error'];
      $fields = ['flags' => $flags];
      if ($fails >= 3 && empty($flags['sending'])) {
        $fields['status'] = 'failed';
        $fields['note'] = 'OneUp refused it three times: ' . $r['error'];
        ias_post_trail($id, 'Failed: OneUp refused it three times — ' . $r['error']);
      }
      ias_post_update($id, $fields);
      return $fail('OneUp ' . (ias_oneup_uncertain($r) ? 'did not answer clearly' : 'refused the post') . ' for ' . $when . ' (try ' . $fails . '): ' . $r['error']);
    }

    $oneup_id = $r['id'] !== '' ? $r['id'] : (string) ias_oneup_find_scheduled($attempt['text'], $attempt['when']);
    unset($flags['sending'], $flags['push_fails'], $flags['push_error'], $flags['was_on_oneup']);
    $flags['oneup'] = ['id' => $oneup_id] + $attempt;
    ias_post_update($id, ['flags' => $flags, 'oneup_post_id' => mb_substr($oneup_id, 0, 64), 'pushed_at' => $attempt['at']]);
    if ($p['type'] === 'listing' && (string) get_option('ias_live_since', '') === '') update_option('ias_live_since', ias_now()->format('Y-m-d'), false);
    ias_post_trail($id, 'Scheduled on OneUp for ' . $when . ($oneup_id !== '' ? ' (post #' . $oneup_id . ')' : ''));
    ias_log('pushed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'oneup' => $oneup_id,
      'summary' => ($old['mode'] === 'live' ? 'Re-scheduled' : 'Scheduled') . ' on OneUp for ' . $when . ($oneup_id === '' ? ' — OneUp gave no post id; the read-back looks for it by its text and time' : '')]);
    return true;
  }
}

/** Take a post back off OneUp. Returns true when it is gone. Only by its id; "gone" only when OneUp's list confirms it. */
if (!function_exists('ias_push_live_delete')) {
  function ias_push_live_delete(array $p, string $why): bool {
    $id = (int) $p['id'];
    $p = ias_post_get($id) ?: $p;
    $flags = $p['flags'];
    $sent = ias_post_sent($p);
    if ($sent['mode'] !== 'live') return true;
    $oid = $sent['id'] !== '' ? $sent['id'] : ias_oneup_resolve_id($p);
    if ($oid === '') {
      if (empty($flags['manual_delete'])) {
        $flags['manual_delete'] = ias_now_sql();
        ias_post_update($id, ['flags' => $flags, 'note' => 'It should come off OneUp (' . $why . '), but OneUp never gave its id: if it is still on the OneUp calendar, delete it there.']);
        ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Could not take it off OneUp (' . $why . '): no post id yet — looked for each hour; delete it in OneUp by hand if it is there.']);
      }
      return false;
    }
    $d = ias_oneup_call('delete', ['post_id' => $oid]);
    ias_oneup_scheduled_forget();
    if (!$d['ok'] && !(ias_oneup_gone($d) && ias_oneup_confirm_absent($oid))) {
      ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'summary' => 'Could not take post #' . $oid . ' off OneUp (' . $why . '): ' . $d['error'] . ' — tried again next hour.']);
      return false;
    }
    unset($flags['oneup'], $flags['manual_delete'], $flags['missing_since']);
    ias_post_update($id, ['flags' => $flags, 'oneup_post_id' => '']);
    ias_post_trail($id, 'Taken off OneUp: ' . $why);
    ias_log('pushed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'oneup' => $oid, 'summary' => 'Taken off OneUp (' . $why . ')']);
    return true;
  }
}

/** A cancelled post that is on OneUp comes off at once — before the page that cancelled it has finished. */
add_action('ias_post_cancelled', function ($post_id) {
  if (ias_oneup_takeback_blockers()) return;
  $p = ias_post_get((int) $post_id);
  if ($p && ias_post_sent($p)['mode'] === 'live') ias_push_live_delete($p, 'cancelled: ' . (string) $p['note']);
}, 10, 1);

/* ---------------- Read-back ---------------- */

/** The Facebook page's name in OneUp (how list rows name it), from Settings or looked up once. */
if (!function_exists('ias_oneup_account_name')) {
  function ias_oneup_account_name(bool $look_again = false): string {
    $name = trim((string) ias_setting('oneup_account_name', ''));
    if ($name !== '' && !$look_again) return $name;
    $cat = (string) ias_setting('oneup_category_id', '');
    $acct = (string) ias_setting('oneup_account_id', '');
    if ($cat === '' || $acct === '') return $name;
    $data = ias_oneup_get('accounts', ['category_id' => $cat]);
    if (is_wp_error($data)) return $name;
    foreach (ias_oneup_pairs($data, ['social_network_id', 'id'], ['social_network_name', 'username', 'name']) as [$aid, $aname]) {
      if ((string) $aid === $acct && $aname !== '') {
        if ($aname !== $name) ias_settings_update(['oneup_account_name' => $aname]);
        return $aname;
      }
    }
    return $name;
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
      'content' => ['text' => $text, 'subject' => mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, 70)],
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
 * The read-back: what OneUp holds for the Facebook page, before every push.
 * The plugin's own posts are matched by their OneUp id (or, without one yet,
 * by their text and time) whatever the page is called; a person's posts are
 * the other rows on the page, by its name. Returns ['ok', 'error', 'changes'].
 */
if (!function_exists('ias_readback_run')) {
  function ias_readback_run(bool $full = true): array {
    $out = ['ok' => false, 'error' => '', 'changes' => []];
    $block = ias_oneup_blockers(false);
    if ($block) { $out['error'] = implode('; ', $block); return $out; }
    $account = ias_oneup_account_name();
    $sched = ias_oneup_scheduled(true);
    if (!$sched['ok']) { $out['error'] = $sched['error']; return ias_readback_note($out); }
    // A person's change sent at once ($full false) reads the scheduled list only: what a person moved or
    // edited there. Published, failed and deleted are the hourly run's (and the open calendar's) to conclude.
    $none = ['ok' => false, 'rows' => [], 'complete' => false, 'error' => ''];
    $pub = $full ? ias_oneup_list('published', 4) : $none;
    $fail = $full ? ias_oneup_list('failed', 2) : $none;
    $GLOBALS['ias_readback_lists'] = ['published' => $pub, 'failed' => $fail]; // Google's read-back, next, reads the same lists
    $now = ias_now_sql();
    $count = ['moved' => 0, 'edited' => 0, 'deleted' => 0, 'published' => 0, 'failed' => 0];

    // The plugin's posts on OneUp: by id; those still without one, and attempts whose answer was
    // lost, by their text and time (so neither is ever taken for a person's post).
    $ours = [];
    $noid = [];
    foreach (ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'pushed', 'published', 'failed', 'skipped', 'cancelled', 'dropped'], 'limit' => 2000]) as $p) {
      if ($p['type'] === 'human') continue;
      $sent = ias_post_sent($p);
      if ($sent['mode'] === 'live' && $sent['id'] !== '') $ours[$sent['id']] = $p;
      elseif ($sent['mode'] === 'live' || !empty($p['flags']['sending'])) $noid[] = $p;
    }
    foreach ($noid as $p) {
      $trying = ias_post_sent($p)['mode'] !== 'live';
      $o = $trying ? (array) $p['flags']['sending'] : (array) $p['flags']['oneup'];
      foreach ($sched['rows'] as $row) {
        $oid = (string) ($row['post_id'] ?? '');
        if ($oid === '' || isset($ours[$oid])) continue;
        if (ias_norm_text((string) ($row['content'] ?? '')) === ias_norm_text((string) ($o['text'] ?? '')) && substr((string) ($row['date_time'] ?? ''), 0, 16) === substr((string) ($o['when'] ?? ''), 0, 16)) {
          $ours[$oid] = $trying ? ias_oneup_adopt_attempt($p, $oid) : (ias_oneup_resolve_id($p) !== '' ? ias_post_get((int) $p['id']) : $p);
          ias_human_forget($oid);
          break;
        }
      }
    }
    $names = [];
    $mine = function (array $row) use ($account) { return $account !== '' && trim((string) ($row['social_network_username'] ?? '')) === $account; };

    // Scheduled.
    $seen = [];
    foreach ($sched['rows'] as $row) {
      $oid = (string) ($row['post_id'] ?? '');
      if ($oid === '') continue;
      $seen[$oid] = true;
      $at = ias_oneup_time((string) ($row['date_time'] ?? ''), false);
      $text = (string) ($row['content'] ?? '');
      if (!isset($ours[$oid])) { if ($mine($row)) ias_human_upsert($oid, $at, $text); continue; }
      $names[] = trim((string) ($row['social_network_username'] ?? ''));
      $p = $ours[$oid];
      if (!in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'pushed', 'published'], true)) continue;
      $o = (array) $p['flags']['oneup'];
      $flags = $p['flags'];
      $was_missing = isset($flags['missing_since']);
      unset($flags['missing_since']);
      $moved = $at !== '' && substr($at, 0, 16) !== substr(str_replace('T', ' ', (string) ($o['when'] ?? '')), 0, 16);
      $edited = ias_norm_text($text) !== ias_norm_text((string) ($o['text'] ?? ''));
      if (!$moved && !$edited) { if ($was_missing) ias_post_update((int) $p['id'], ['flags' => $flags]); continue; }
      $flags['oneup']['when'] = substr($at, 0, 16);
      $flags['oneup']['text'] = $text;
      $flags['edited_in_oneup'] = trim(($moved ? 'moved' : '') . ($moved && $edited ? ' and ' : '') . ($edited ? 'edited' : ''));
      $fields = ['flags' => $flags, 'locked' => 1, 'note' => ($moved ? 'Moved' : 'Edited') . ' in OneUp by a person — the plugin leaves it as they made it (Edit it here again changes that).'];
      if ($moved) $fields['scheduled_at'] = $at;
      ias_post_update((int) $p['id'], $fields);
      $what = ($moved ? 'moved it to ' . date_i18n('D M j, g:i a', strtotime($at)) : '') . ($moved && $edited ? ' and ' : '') . ($edited ? 'edited its text' : '');
      ias_post_trail((int) $p['id'], 'In OneUp a person ' . $what . ' — kept as they made it');
      ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'A person ' . $what . ' in OneUp — the plan keeps it and works around it.']);
      $count[$moved ? 'moved' : 'edited']++;
    }

    // Published.
    $seen_pub = [];
    foreach ($pub['ok'] ? $pub['rows'] : [] as $row) {
      $oid = (string) ($row['post_id'] ?? '');
      if ($oid === '') continue;
      $seen_pub[$oid] = true;
      $at = ias_oneup_time((string) ($row['created_at'] ?? $row['date_time'] ?? ''), true);
      if (!isset($ours[$oid])) {
        if ($mine($row) && $at !== '' && $at >= ias_now()->modify('-2 days')->format('Y-m-d H:i:s')) ias_human_upsert($oid, $at, (string) ($row['content'] ?? '')); // today's count toward today's three
        continue;
      }
      $names[] = trim((string) ($row['social_network_username'] ?? ''));
      $p = $ours[$oid];
      if ($p['status'] === 'published' && empty($p['flags']['unconfirmed'])) continue;
      $flags = $p['flags'];
      $was = (string) $p['status'];
      unset($flags['unconfirmed'], $flags['unconfirmed_noted'], $flags['missing_since']);
      $note = in_array($was, ['cancelled', 'skipped', 'dropped'], true) ? 'It went out on Facebook although it was ' . $was . ' here — take it down on the page if it should not be there.' : '';
      ias_post_update((int) $p['id'], ['status' => 'published', 'flags' => $flags, 'published_at' => $at !== '' ? $at : $now, 'note' => $note]);
      ias_post_trail((int) $p['id'], 'Published on Facebook' . ($at !== '' ? ' ' . date_i18n('D M j, g:i a', strtotime($at)) : '') . ($note !== '' ? ' — although it was ' . $was : ''));
      ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'Published on Facebook (OneUp #' . $oid . ').' . ($note !== '' ? ' ' . $note : '')]);
      if (function_exists('ias_voice_record')) ias_voice_record((int) $p['id'], 'out');
      $count['published']++;
    }

    // Failed.
    $seen_fail = [];
    foreach ($fail['ok'] ? $fail['rows'] : [] as $row) {
      $oid = (string) ($row['post_id'] ?? '');
      $seen_fail[$oid] = true;
      if (!isset($ours[$oid]) || in_array($ours[$oid]['status'], ['failed', 'cancelled', 'skipped'], true)) continue;
      $p = $ours[$oid];
      $why = (string) ($row['failure_reason'] ?? $row['reason'] ?? $row['error'] ?? $row['message'] ?? 'no reason given');
      ias_post_update((int) $p['id'], ['status' => 'failed', 'note' => 'OneUp could not publish it: ' . $why . ' — press Try again, or skip it.']);
      ias_post_trail((int) $p['id'], 'Failed on OneUp: ' . $why);
      ias_log('push_failed', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'OneUp could not publish it: ' . $why]);
      $count['failed']++;
    }

    // The page was renamed (or reconnected): the plugin's own posts show it under another name.
    $names = array_values(array_unique(array_filter($names)));
    if (count($names) === 1 && $names[0] !== $account) {
      ias_settings_update(['oneup_account_name' => $names[0]]);
      ias_log('readback', 0, ['summary' => 'The Facebook page is now called "' . $names[0] . '" in OneUp (was "' . $account . '").']);
    }

    // Ours that vanished before their time: a person deleted them in OneUp. Only from a whole, real
    // list; only after two read-backs at least 30 minutes apart; never when none of them can be seen —
    // and only on a full read-back, which has the published and failed lists to tell them apart.
    if ($full && $sched['complete']) {
      $grace = ias_now()->modify('-10 minutes')->format('Y-m-d H:i:s');
      $expected = array_filter($ours, function ($p) use ($now, $grace) {
        return in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'pushed'], true) && (string) $p['scheduled_at'] > $now && (string) ($p['pushed_at'] ?? '') <= $grace;
      });
      $missing = array_diff_key($expected, $seen, $seen_pub, $seen_fail);
      if (count($expected) >= 2 && count($missing) === count($expected)) {
        if (empty($out['error'])) ias_log('readback', 0, ['summary' => 'None of the ' . count($expected) . ' posts the plugin scheduled is on OneUp\'s list — nothing is concluded from that; check the page in OneUp and run Verify OneUp.']);
      } else {
        $limit = ias_now()->modify('-30 minutes')->format('Y-m-d H:i:s');
        foreach ($missing as $oid => $p) {
          $since = (string) ($p['flags']['missing_since'] ?? '');
          if ($since === '') {
            $flags = $p['flags'];
            $flags['missing_since'] = $now;
            ias_post_update((int) $p['id'], ['flags' => $flags]);
            continue;
          }
          if ($since > $limit) continue;
          ias_live_person_deleted($p, false);
          $count['deleted']++;
        }
      }
      // A person's scheduled post that is gone (and did not go out) leaves the plan.
      foreach (ias_posts_query(['status' => 'human', 'limit' => 500]) as $h) {
        $oid = (string) $h['oneup_post_id'];
        if ($oid === '' || (string) $h['scheduled_at'] <= $now || isset($seen[$oid]) || isset($seen_pub[$oid])) continue;
        global $wpdb;
        $wpdb->delete(ias_table('posts'), ['id' => (int) $h['id']]);
      }
    }

    // Gone out by our clock a day ago and still not confirmed: tell a person once.
    $day_ago = ias_now()->modify('-24 hours')->format('Y-m-d H:i:s');
    foreach ($full ? ias_posts_query(['status' => 'published', 'limit' => 500]) : [] as $p) {
      if (empty($p['flags']['unconfirmed']) || !empty($p['flags']['unconfirmed_noted']) || (string) $p['published_at'] > $day_ago) continue;
      $flags = $p['flags'];
      $flags['unconfirmed_noted'] = $now;
      ias_post_update((int) $p['id'], ['flags' => $flags, 'note' => 'OneUp has not confirmed this went out — check the page.']);
      ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'summary' => 'Not confirmed by OneUp a day after its time — check the page.']);
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
    $prev = get_option('ias_readback_last', []);
    $prev = is_array($prev) ? $prev : [];
    // The same failure again within the hour (an open calendar asks every minute) is logged once.
    $again = !$out['ok'] && empty($prev['ok']) && (string) ($prev['error'] ?? '') === (string) $out['error'] && !empty($prev['logged']) && (string) $prev['logged'] > ias_now()->modify('-1 hour')->format('Y-m-d H:i:s');
    $logged = !$out['ok'] && $out['error'] !== '' && !$again ? ias_now_sql() : (string) ($prev['logged'] ?? '');
    update_option('ias_readback_last', ['at' => ias_now_sql(), 'ok' => $out['ok'], 'error' => $out['error'], 'changes' => $out['changes'], 'logged' => $out['ok'] ? '' : $logged], false);
    if (!$out['ok'] && $out['error'] !== '' && !$again) ias_log('readback', 0, ['summary' => 'Read-back from OneUp failed: ' . $out['error']]);
    return $out;
  }
}

/* ---------------- Verify OneUp ---------------- */

/**
 * The safe round trip (Settings → Verify OneUp): the category and the page,
 * a draft saved (drafts never publish), read back, its time compared, then
 * deleted; the scheduled, published and failed lists read. Each step is
 * recorded in `ias_oneup_verify`, with the page it was run for; nothing is
 * written to OneUp for real until it has passed for the page set now.
 * $ch 'gbp' runs the same round trip for the Google location (Verify for
 * Google, recorded in `ias_gbp_verify`) — Google's own, never Facebook's.
 *
 * It needs the master switch on (off, the plugin calls OneUp only to take
 * back its own posts): the one thing it writes is the draft, which never
 * publishes, and no post type has to be on for it. Should OneUp ignore
 * "draft" and schedule the test as a real post, Verify finds it on the
 * scheduled list, fails, and takes it off at once (it is set a month out, so
 * there is time to delete it by hand if even that fails).
 */
if (!function_exists('ias_oneup_verify')) {
  function ias_oneup_verify(string $by, string $ch = 'facebook'): array {
    $steps = [];
    $step = function (string $label, ?bool $ok, string $detail) use (&$steps) { $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail]; return $ok; };
    $gbp = $ch === 'gbp';
    $cat = (string) ias_setting('oneup_category_id', '');
    $acct = $gbp ? ias_channel_settings('gbp')['account'] : (string) ias_setting('oneup_account_id', '');
    $tz_offset = null;
    $block = $gbp ? ias_gbp_blockers_read() : ias_oneup_blockers(false);
    if ($block) {
      $step('Ready to call OneUp', false, 'Not yet: ' . implode('; ', $block) . '. (The master switch must be on. Nothing posts while the post types are off.)');
      return ias_oneup_verify_save($steps, $by, null, $ch);
    }

    // 1. The page (or, for Google, the location).
    $accts = ias_oneup_get('accounts', ['category_id' => $cat]);
    $name = '';
    $type = '';
    if (!is_wp_error($accts)) {
      foreach (ias_oneup_pairs($accts, ['social_network_id', 'id'], ['social_network_name', 'username', 'name']) as [$aid, $aname, $row]) {
        if ((string) $aid === $acct) { $name = $aname; $type = (string) ($row['social_network_type'] ?? $row['type'] ?? ''); }
      }
    }
    $right = $gbp ? ias_oneup_type_is('gbp', $type) : strcasecmp($type, 'facebook') === 0;
    if (!$step($gbp ? 'The Google Business Profile location' : 'The Facebook page', $name !== '' && $right,
      is_wp_error($accts) ? 'OneUp did not list the category\'s accounts: ' . $accts->get_error_message()
      : ($name === '' ? 'Account ' . $acct . ' is not in category ' . $cat . '.' : $name . ' (' . ($type !== '' ? $type : 'type not given') . ')' . ($right ? '' : ($gbp ? ' — that is not a Google Business Profile.' : ' — that is not a Facebook page.'))))) {
      return ias_oneup_verify_save($steps, $by, null, $ch);
    }
    if ($gbp) {
      $nets = (array) ias_setting('networks', []);
      $nets['gbp']['name'] = $name;
      ias_settings_update(['networks' => $nets]);
    } else {
      ias_settings_update(['oneup_account_name' => $name]);
    }

    // Test drafts an earlier Verify could not delete go first, by their ids — nobody deletes them in OneUp by hand.
    $left = ias_oneup_verify_leftovers();
    if ($left) {
      $errors = [];
      foreach ($left as $lid) {
        $d = ias_oneup_call('delete', ['post_id' => $lid]);
        if (!$d['ok'] && !ias_oneup_gone($d)) $errors[$lid] = $d['error'];
      }
      $now_drafts = ias_oneup_list('drafts', 5);
      $still_ids = [];
      foreach ($now_drafts['rows'] as $row) if (in_array((string) ($row['post_id'] ?? ''), $left, true)) $still_ids[] = (string) $row['post_id'];
      $kept = $now_drafts['ok'] ? $still_ids : $left;
      update_option('ias_oneup_verify_leftovers', array_values($kept), false);
      $gone = array_values(array_diff($left, $kept));
      $step('Earlier test drafts', $kept ? false : true, ($gone ? 'Deleted #' . implode(' and #', $gone) . ', left by an earlier Verify.' : '')
        . ($kept ? ' Still in OneUp: #' . implode(', #', $kept) . ($errors ? ' (' . implode('; ', array_unique($errors)) . ')' : '') . ' — tried again by the next Verify.' : ''));
    }

    // 2. A draft (for Google, an "update" — the kind of post it will be sent).
    $when = ias_now()->modify('+30 days')->setTime(9, 17)->format('Y-m-d H:i');
    $text = 'Intel Auctions Social connection test ' . wp_generate_password(6, false) . ' — a draft: it never publishes, and the plugin deletes it straight away.';
    $r = ias_oneup_call('schedule_text', ['category_id' => $cat, 'social_network_id' => $acct, 'scheduled_date_time' => $when, 'content' => $text, 'isDraftPost' => 'true'] + ($gbp ? ['google_post_type' => 'update'] : []));
    ias_oneup_scheduled_forget();
    if (!$step('Save a draft (scheduletextpost, as a draft)', $r['ok'], $r['ok'] ? 'OneUp accepted it' . ($r['id'] !== '' ? ' as post #' . $r['id'] : ' (its answer carried no post id — found below by its text)') . '.' : $r['error'])) {
      // No answer is not "no post": look on the scheduled list (and among the drafts) before giving up.
      if (ias_oneup_uncertain($r)) {
        $found = (string) ias_oneup_find_scheduled($text, $when);
        $where = 'scheduled it as a real post';
        if ($found === '') {
          $where = 'saved the draft';
          foreach (ias_oneup_list('drafts', 5)['rows'] as $row) if (ias_norm_text((string) ($row['content'] ?? '')) === ias_norm_text($text)) $found = (string) ($row['post_id'] ?? '');
        }
        if ($found !== '') {
          $d = ias_oneup_call('delete', ['post_id' => $found]);
          ias_oneup_scheduled_forget();
          $step('The test post arrived anyway', false, 'OneUp ' . $where . ' (#' . $found . ', ' . $when . ') without answering; '
            . ($d['ok'] ? 'it has been deleted.' : 'it could not be deleted: DELETE IT IN ONEUP NOW.'));
        }
      }
      return ias_oneup_verify_save($steps, $by, null, $ch);
    }

    // 3. Read it back: among the drafts — and make sure it is not on the scheduled list as a real post.
    $drafts = ias_oneup_list('drafts', 5);
    $found = null;
    foreach ($drafts['rows'] as $row) if (ias_norm_text((string) ($row['content'] ?? '')) === ias_norm_text($text)) $found = $row;
    $real = (string) ias_oneup_find_scheduled($text, $when);
    if ($real !== '') {
      $d = ias_oneup_call('delete', ['post_id' => $real]);
      ias_oneup_scheduled_forget();
      $step('Saved as a draft, not a post', false, 'OneUp did not save it as a draft: it scheduled it as a real post (#' . $real . ') for ' . $when . '. '
        . ($d['ok'] ? 'It has been deleted. Nothing can be sent until OneUp keeps drafts as drafts.' : 'It could not be deleted (' . $d['error'] . '): DELETE IT IN ONEUP NOW.'));
      return ias_oneup_verify_save($steps, $by, null, $ch);
    }
    $draft_id = $r['id'] !== '' ? $r['id'] : (string) ($found['post_id'] ?? '');
    if ($found) {
      $back = substr((string) ($found['date_time'] ?? ''), 0, 16);
      if ($back !== '') {
        try { $tz_offset = (int) round(((new DateTimeImmutable($back))->getTimestamp() - (new DateTimeImmutable($when))->getTimestamp()) / 60); } catch (\Exception $e) { $tz_offset = null; }
      }
      $step('Read the draft back (getdraftposts)', true, 'Found as post #' . (string) ($found['post_id'] ?? '?') . ', a draft.');
      $step('Time zone', $tz_offset === 0 ? true : false, $tz_offset === 0 ? 'OneUp kept ' . $when . ' — its account time zone matches the site\'s.'
        : ($tz_offset === null ? 'OneUp did not say the draft\'s time, so the time zone could not be checked.' : 'Sent ' . $when . ', OneUp shows ' . $back . ': its account time zone is ' . round($tz_offset / 60, 1) . ' hours off the site\'s. Set OneUp\'s time zone to the site\'s, then verify again.'));
    } else {
      $step('Read the draft back (getdraftposts)', false, $drafts['ok'] ? 'The draft was not in OneUp\'s draft list.' : $drafts['error']);
    }

    // 4. Delete it.
    if ($draft_id !== '') {
      $d = ias_oneup_call('delete', ['post_id' => $draft_id]);
      $again = ias_oneup_list('drafts', 5);
      $still = !$again['ok'];
      foreach ($again['rows'] as $row) if ((string) ($row['post_id'] ?? '') === $draft_id) $still = true;
      $path = (string) (ias_oneup_endpoints()['delete'][1] ?? 'delete');
      if (!$d['ok'] || $still) update_option('ias_oneup_verify_leftovers', array_values(array_unique(array_merge(array_map('strval', (array) get_option('ias_oneup_verify_leftovers', [])), [$draft_id]))), false);
      $step('Delete it (' . $path . ')', $d['ok'] && !$still, ($d['ok'] ? ($still ? 'OneUp answered, but the draft is still there (or the list could not be read).' : 'Deleted.') : $d['error'])
        . ($d['ok'] && !$still ? '' : ' The test draft (#' . $draft_id . ') never publishes; the next Verify deletes it.'));
    } else {
      $step('Delete it (' . (string) (ias_oneup_endpoints()['delete'][1] ?? 'delete') . ')', false, 'OneUp gave the test draft no id to delete it by — it never publishes; delete it in OneUp if it is in the way.');
    }

    // 5. The lists the read-back uses.
    foreach (['scheduled' => 'getscheduledposts', 'published' => 'getpublishedposts', 'failed' => 'getfailedposts'] as $ep => $path) {
      $l = ias_oneup_list($ep, 1);
      $step('Read the ' . $ep . ' posts (' . $path . ')', $l['ok'], $l['ok'] ? count($l['rows']) . ' on the first page.' : $l['error']);
    }
    return ias_oneup_verify_save($steps, $by, $tz_offset, $ch);
  }
}

if (!function_exists('ias_oneup_verify_save')) {
  function ias_oneup_verify_save(array $steps, string $by, ?int $tz_offset, string $ch = 'facebook'): array {
    $ok = (bool) $steps && !in_array(false, array_column($steps, 'ok'), true) && !in_array(null, array_column($steps, 'ok'), true) && $tz_offset === 0;
    $gbp = $ch === 'gbp';
    $result = ['at' => ias_now_sql(), 'by' => $by, 'ok' => $ok, 'steps' => $steps, 'tz_offset' => $tz_offset,
      'category' => (string) ias_setting('oneup_category_id', ''), 'account' => $gbp ? ias_channel_settings('gbp')['account'] : (string) ias_setting('oneup_account_id', ''), 'key' => ias_oneup_key_print()];
    update_option($gbp ? 'ias_gbp_verify' : 'ias_oneup_verify', $result, false);
    ias_log('readback', 0, ['summary' => ($gbp ? 'Verify for Google: ' : 'Verify OneUp: ') . ($ok ? 'every step passed' : 'a step failed — see Settings')], $by);
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
