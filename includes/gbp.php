<?php
/**
 * Intel Auctions Social — Google Business Profile, sent for real (0.9.0).
 *
 * DJ, Oct 13, 2026: "I want to be able to schedule GBM posts from here."
 * Google is the second network the plugin sends to, through the same OneUp
 * category, with everything Facebook has — and none of Facebook's records:
 *
 *   Switches     Google posts on, a location chosen, **Send to Google** on,
 *                the master switch on, dry run off, and **Verify for Google**
 *                passed for that location (`ias_gbp_verify`, the same safe
 *                draft round trip as Facebook's). Until then Google posts are
 *                a dry run in the log, like Instagram's (channels.php).
 *   Its record   `flags.gbp` (id, hash, at, when, text) is what went to
 *                Google; `flags.gbp_sending` an attempt whose answer is not in
 *                yet; `gbp_fails` / `gbp_error` its refusals; `gbp_locked` a
 *                person moved or edited it in OneUp; `gbp_off` Google is off
 *                for this post ('person': deleted in OneUp or switched off on
 *                the post; 'failed': refused three times or failed to
 *                publish). Facebook's `flags.oneup` is never read or written
 *                here, and a Google failure never fails the Facebook post.
 *   The rules    Facebook's: one push run at a time (this runs inside its
 *                lock), an attempt is recorded before it is sent and looked
 *                for on OneUp's list before it is ever sent again, a copy is
 *                replaced or taken back only by its post_id, "not found"
 *                counts only when the list agrees, a person's edit or move in
 *                OneUp is kept and their delete stands (two read-backs 30
 *                minutes apart, from a whole list).
 *   Its time     a minute after the Facebook post, so the two are never the
 *                same words at the same minute on OneUp's list.
 *
 * While Google is sent from here, the toolkit's own Google post (its
 * pipeline's draft and "Review and post" task, and `gbp` in its webhook's
 * channels) stands down through `ia_social_gbp_managed` (toolkit 4.68.0), so
 * nothing reaches the profile twice; with an older toolkit, nothing is sent.
 */

if (!defined('ABSPATH')) exit;

if (!defined('IAS_GBP_TOOLKIT')) define('IAS_GBP_TOOLKIT', '4.68.0');

/** What stops the plugin reading Google's posts on OneUp ([] = nothing). */
if (!function_exists('ias_gbp_blockers_read')) {
  function ias_gbp_blockers_read(): array {
    $out = [];
    if (!ias_setting('master', false)) $out[] = 'the master switch is off';
    if (ias_oneup_key() === '') $out[] = 'no OneUp key (Settings → OneUp)';
    if ((string) ias_setting('oneup_category_id', '') === '') $out[] = 'no OneUp category chosen';
    if (ias_channel_settings('gbp')['account'] === '') $out[] = 'no Google location chosen';
    return $out;
  }
}

/** What stops Google posts being sent for real ([] = they are sent). */
if (!function_exists('ias_gbp_blockers')) {
  function ias_gbp_blockers(): array {
    $g = ias_channel_settings('gbp');
    $out = [];
    if (!$g['on']) $out[] = 'Google posts are off';
    if (!$g['live']) $out[] = 'Send to Google is off';
    if (ias_setting('dry_run', true)) $out[] = 'dry run is on';
    $out = array_merge($out, ias_gbp_blockers_read());
    if (!defined('IA_TOOLKIT_VERSION') || version_compare((string) IA_TOOLKIT_VERSION, IAS_GBP_TOOLKIT, '<')) {
      $out[] = 'the Intel Auctions Toolkit needs updating to ' . IAS_GBP_TOOLKIT . ' (from then its own Google post stands down, so nothing is posted twice)';
    }
    if (!apply_filters('ias_live_pushing_built', true)) $out[] = 'live sending is turned off on this site';
    if (!$out && !ias_gbp_verified()) $out[] = 'Verify for Google has not passed for this location';
    return $out;
  }
}

/** Has Verify for Google passed for the location set now (same key, category and location; OneUp's time the site's)? */
if (!function_exists('ias_gbp_verified')) {
  function ias_gbp_verified(): bool {
    $v = get_option('ias_gbp_verify', []);
    return is_array($v) && !empty($v['ok']) && ($v['tz_offset'] ?? null) === 0
      && (string) ($v['key'] ?? '') === ias_oneup_key_print()
      && (string) ($v['category'] ?? '') === (string) ias_setting('oneup_category_id', '')
      && (string) ($v['account'] ?? '') !== '' && (string) ($v['account'] ?? '') === ias_channel_settings('gbp')['account'];
  }
}

/** 'off' (Google posts off), 'live' (sent for real) or 'dry' (planned and logged). */
if (!function_exists('ias_gbp_mode')) {
  function ias_gbp_mode(): string {
    if (!ias_channel_settings('gbp')['on']) return 'off';
    return ias_gbp_blockers() ? 'dry' : 'live';
  }
}

if (!function_exists('ias_gbp_live')) {
  function ias_gbp_live(): bool {
    return ias_gbp_mode() === 'live';
  }
}

/** Does this post type go to Google (its own switch on, and ticked for Google)? */
if (!function_exists('ias_gbp_type_on')) {
  function ias_gbp_type_on(string $type): bool {
    return ias_type_on($type) && in_array($type, ias_channel_settings('gbp')['types'], true);
  }
}

/** Is this post ready to go to Google (apart from the switches)? '' = yes, else why not. */
if (!function_exists('ias_gbp_ready')) {
  function ias_gbp_ready(array $p): string {
    $why = ias_push_ready($p);
    if ($why !== '') return $why;
    if (!in_array((string) $p['type'], ias_channel_settings('gbp')['types'], true)) return ias_post_type((string) $p['type'])['label'] . ' posts do not go to Google';
    $off = (string) ($p['flags']['gbp_off'] ?? '');
    if ($off !== '') return $off === 'failed' ? 'Google could not take it' : 'a person took it off Google';
    return '';
  }
}

/** The Google location's name on OneUp's lists ('' = not known yet). */
if (!function_exists('ias_gbp_name')) {
  function ias_gbp_name(): string {
    $g = ias_channel_settings('gbp');
    if ($g['name'] !== '') return $g['name'];
    $a = ias_oneup_directory_account($g['account']);
    return $a ? (string) $a['name'] : '';
  }
}

/** Is this OneUp list row Google's (by its account name, when the row and the name are known)? */
if (!function_exists('ias_gbp_row_ok')) {
  function ias_gbp_row_ok(array $row): bool {
    $name = ias_gbp_name();
    $user = trim((string) ($row['social_network_username'] ?? ''));
    return $name === '' || $user === '' || $user === $name;
  }
}

/** Google's copy of this post on OneUp's scheduled list, by its words and time: its id, '' = not there, null = the list could not be read. */
if (!function_exists('ias_gbp_find_scheduled')) {
  function ias_gbp_find_scheduled(string $text, string $when): ?string {
    $list = ias_oneup_scheduled();
    if (!$list['ok']) return null;
    foreach (array_reverse($list['rows']) as $row) {
      if (!ias_gbp_row_ok($row)) continue;
      if (ias_norm_text((string) ($row['content'] ?? '')) === ias_norm_text($text) && substr((string) ($row['date_time'] ?? ''), 0, 16) === substr($when, 0, 16)) {
        return (string) ($row['post_id'] ?? '');
      }
    }
    return '';
  }
}

/** Note Google's mode; going live clears its dry run's record, so nothing counts as sent that was not. */
if (!function_exists('ias_gbp_mode_changed')) {
  function ias_gbp_mode_changed(string $mode): void {
    $last = (string) get_option('ias_gbp_mode', 'off');
    if ($last === $mode) return;
    update_option('ias_gbp_mode', $mode, false);
    if ($mode !== 'live') {
      if ($last === 'live') ias_log('run', 0, ['channel' => 'gbp', 'summary' => 'Google is no longer sent for real (' . ($mode === 'off' ? 'Google posts are off' : implode('; ', ias_gbp_blockers())) . '). Google posts already scheduled on OneUp stay there until they are deleted in OneUp.']);
      return;
    }
    $n = 0;
    foreach (ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'dropped', 'skipped', 'cancelled'], 'limit' => 2000]) as $p) {
      if (empty($p['flags']['dry_ch']['gbp'])) continue;
      $flags = $p['flags'];
      unset($flags['dry_ch']['gbp']);
      if (!$flags['dry_ch']) unset($flags['dry_ch']);
      ias_post_update((int) $p['id'], ['flags' => $flags]);
      $n++;
    }
    ias_log('run', 0, ['channel' => 'gbp', 'summary' => 'Google is sent for real now: the dry run\'s record of ' . $n . ' posts was cleared, so each goes to Google through OneUp (outside the ' . (int) ias_setting('lead_hours', 48) . '-hour window).']);
  }
}

/** A Google copy with no OneUp id yet: look for it by its words and time, and keep the id if found ('' = not found). */
if (!function_exists('ias_gbp_resolve_id')) {
  function ias_gbp_resolve_id(array $p): string {
    $g = (array) ($p['flags']['gbp'] ?? []);
    if (!empty($g['id'])) return (string) $g['id'];
    if (empty($g['text']) || empty($g['when'])) return '';
    $found = (string) ias_gbp_find_scheduled((string) $g['text'], (string) $g['when']);
    if ($found === '') return '';
    $flags = $p['flags'];
    $flags['gbp']['id'] = $found;
    unset($flags['gbp_manual_delete']);
    ias_post_update((int) $p['id'], ['flags' => $flags]);
    ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'channel' => 'gbp', 'oneup' => $found, 'summary' => 'Google: found on OneUp by its words and time: post #' . $found]);
    return $found;
  }
}

/** OneUp has the Google copy an earlier attempt sent, though its answer was lost: that attempt becomes what was sent. */
if (!function_exists('ias_gbp_adopt_attempt')) {
  function ias_gbp_adopt_attempt(array $p, string $oneup_id): array {
    $id = (int) $p['id'];
    $flags = $p['flags'];
    $try = (array) ($flags['gbp_sending'] ?? []);
    unset($flags['gbp_sending'], $flags['gbp_fails'], $flags['gbp_error'], $flags['gbp_was']);
    $flags['gbp'] = ['id' => $oneup_id, 'hash' => (string) ($try['hash'] ?? ''), 'at' => (string) ($try['at'] ?? ias_now_sql()), 'when' => (string) ($try['when'] ?? ''), 'text' => (string) ($try['text'] ?? '')];
    ias_post_update($id, ['flags' => $flags]);
    ias_post_trail($id, 'Google: found on OneUp after all (its answer was lost): post #' . $oneup_id);
    ias_log('pushed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'channel' => 'gbp', 'oneup' => $oneup_id, 'summary' => 'Google: OneUp had the post after all (its answer was lost): post #' . $oneup_id . ' kept, not sent twice.']);
    return ias_post_get($id) ?: $p;
  }
}

/** Google is off for this post: a person deleted it in OneUp (or took it off here), or Google would not take it. */
if (!function_exists('ias_gbp_switch_off')) {
  function ias_gbp_switch_off(array $p, string $why, string $words, bool $gone): void {
    $flags = $p['flags'];
    if ($gone) unset($flags['gbp']); // else the take-back confirms it is gone (and takes it off if it is not)
    unset($flags['gbp_missing'], $flags['gbp_was'], $flags['gbp_locked']);
    $flags['gbp_off'] = $why;
    ias_post_update((int) $p['id'], ['flags' => $flags]);
    ias_post_trail((int) $p['id'], 'Google: ' . $words);
    ias_log($why === 'failed' ? 'push_failed' : 'readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'channel' => 'gbp', 'summary' => 'Google: ' . $words]);
  }
}

/**
 * Schedule one post on Google through OneUp, taking its older Google copy off
 * first. Returns true when Google has the post as it is now. Never sends
 * twice (ias_push_live_send()'s rules, on Google's record).
 */
if (!function_exists('ias_gbp_send')) {
  function ias_gbp_send(array $p, array $req, string $hash): bool {
    $id = (int) $p['id'];
    $p = ias_post_get($id) ?: $p;
    $flags = $p['flags'];
    $old = (array) ($flags['gbp'] ?? []);
    if (($old['hash'] ?? '') === $hash) return true;
    $when = date_i18n('D M j, g:i a', strtotime((string) $req['params']['scheduled_date_time']));
    $fail = function (string $summary) use ($p, $id) {
      ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'channel' => 'gbp', 'summary' => 'Google: ' . $summary]);
      return false;
    };

    // An earlier attempt whose answer never came back: if OneUp has it, adopt it instead of sending again.
    if (!empty($flags['gbp_sending'])) {
      $try = (array) $flags['gbp_sending'];
      $found = ias_gbp_find_scheduled((string) $try['text'], (string) $try['when']);
      if ($found === null) return $fail('Could not read OneUp\'s list to see whether an earlier attempt arrived, so nothing was sent (tried again next hour).');
      if ($found !== '') {
        $p = ias_gbp_adopt_attempt($p, $found);
        $flags = $p['flags'];
        $old = (array) ($flags['gbp'] ?? []);
        if (($old['hash'] ?? '') === $hash) return true;
      } else {
        unset($flags['gbp_sending']);
        ias_post_update($id, ['flags' => $flags]);
      }
    }

    // The older copy comes off first — only by its id.
    if ($old) {
      $old_id = !empty($old['id']) ? (string) $old['id'] : ias_gbp_resolve_id($p);
      if ($old_id === '') return $fail('Not replaced yet: OneUp has not given the id of the Google copy already there, and it cannot be taken off without it (looked for again each hour).');
      $d = ias_oneup_call('delete', ['post_id' => $old_id]);
      ias_oneup_scheduled_forget();
      if (!$d['ok']) {
        if (ias_oneup_gone($d) && ias_oneup_confirm_absent($old_id)) {
          ias_gbp_switch_off($p, 'person', 'deleted in OneUp by a person — Google stays off for this post until someone sends it again', true);
          return false;
        }
        return $fail('Could not take the older copy off OneUp (post #' . $old_id . '), so the new one was not sent: ' . $d['error']);
      }
      unset($flags['gbp']);
      $flags['gbp_was'] = true; // a refused replacement is retried as a replacement, not a new post inside the veto window
      ias_post_update($id, ['flags' => $flags]);
    }

    $attempt = ['hash' => $hash, 'when' => (string) $req['params']['scheduled_date_time'], 'text' => (string) $req['params']['content'], 'at' => ias_now_sql()];
    $flags['gbp_sending'] = $attempt;
    ias_post_update($id, ['flags' => $flags]);
    $r = ias_oneup_call((string) $req['endpoint'], (array) $req['params']);
    ias_oneup_scheduled_forget();
    if (!$r['ok']) {
      if (!ias_oneup_uncertain($r)) unset($flags['gbp_sending']); // a clear refusal: nothing is on OneUp
      $fails = (int) ($flags['gbp_fails'] ?? 0) + 1;
      $flags['gbp_fails'] = $fails;
      $flags['gbp_error'] = $r['error'];
      ias_post_update($id, ['flags' => $flags]);
      if ($fails >= 3 && empty($flags['gbp_sending'])) {
        ias_gbp_switch_off(ias_post_get($id) ?: $p, 'failed', 'OneUp refused it three times — ' . $r['error'] . '. Google is off for this post; the Facebook post is not affected.', false);
      }
      return $fail('OneUp ' . (ias_oneup_uncertain($r) ? 'did not answer clearly' : 'refused the post') . ' for ' . $when . ' (try ' . $fails . '): ' . $r['error']);
    }

    $oneup_id = $r['id'] !== '' ? $r['id'] : (string) ias_gbp_find_scheduled($attempt['text'], $attempt['when']);
    unset($flags['gbp_sending'], $flags['gbp_fails'], $flags['gbp_error'], $flags['gbp_was']);
    $flags['gbp'] = ['id' => $oneup_id] + $attempt;
    ias_post_update($id, ['flags' => $flags]);
    ias_post_trail($id, 'Google: scheduled on OneUp for ' . $when . ($oneup_id !== '' ? ' (post #' . $oneup_id . ')' : ''));
    ias_log('pushed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'channel' => 'gbp', 'oneup' => $oneup_id,
      'summary' => 'Google: ' . ($old ? 're-scheduled' : 'scheduled') . ' on OneUp for ' . $when . ($oneup_id === '' ? ' — OneUp gave no post id; the read-back looks for it by its words and time' : '')]);
    return true;
  }
}

/** Take the Google copy back off OneUp. True when it is gone. Only by its id; "gone" only when OneUp's list confirms it. */
if (!function_exists('ias_gbp_delete')) {
  function ias_gbp_delete(array $p, string $why): bool {
    $id = (int) $p['id'];
    $p = ias_post_get($id) ?: $p;
    $flags = $p['flags'];
    if (empty($flags['gbp'])) return true;
    $oid = !empty($flags['gbp']['id']) ? (string) $flags['gbp']['id'] : ias_gbp_resolve_id($p);
    if ($oid === '') {
      if (empty($flags['gbp_manual_delete'])) {
        $flags['gbp_manual_delete'] = ias_now_sql();
        ias_post_update($id, ['flags' => $flags]);
        ias_post_trail($id, 'Google: it should come off OneUp (' . $why . '), but OneUp never gave its id — if it is still on the OneUp calendar, delete it there');
        ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'channel' => 'gbp', 'summary' => 'Google: could not take it off OneUp (' . $why . '): no post id yet — looked for each hour; delete it in OneUp by hand if it is there.']);
      }
      return false;
    }
    $d = ias_oneup_call('delete', ['post_id' => $oid]);
    ias_oneup_scheduled_forget();
    if (!$d['ok'] && !(ias_oneup_gone($d) && ias_oneup_confirm_absent($oid))) {
      ias_log('push_failed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'channel' => 'gbp', 'summary' => 'Google: could not take post #' . $oid . ' off OneUp (' . $why . '): ' . $d['error'] . ' — tried again next hour.']);
      return false;
    }
    unset($flags['gbp'], $flags['gbp_manual_delete'], $flags['gbp_missing'], $flags['gbp_locked']);
    ias_post_update($id, ['flags' => $flags]);
    ias_post_trail($id, 'Google: taken off OneUp — ' . $why);
    ias_log('pushed', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'channel' => 'gbp', 'oneup' => $oid, 'summary' => 'Google: taken off OneUp (' . $why . ')']);
    return true;
  }
}

/** A cancelled post's Google copy comes off at once, like its Facebook one. */
add_action('ias_post_cancelled', function ($post_id) {
  if (!ias_gbp_live()) return;
  $p = ias_post_get((int) $post_id);
  if ($p && !empty($p['flags']['gbp'])) ias_gbp_delete($p, 'cancelled: ' . (string) $p['note']);
}, 11, 1);

/**
 * Google's read-back, after Facebook's and on the same lists: its copies a
 * person moved or edited in OneUp are kept as they made them (`gbp_locked`),
 * published ones noted, failed ones switch Google off for that post, and ones
 * a person deleted stay deleted. Returns the changes, in words.
 */
if (!function_exists('ias_gbp_readback')) {
  function ias_gbp_readback(): array {
    $changes = [];
    if (ias_gbp_blockers_read()) return $changes;
    $now = ias_now_sql();
    $count = ['moved' => 0, 'edited' => 0, 'deleted' => 0, 'published' => 0, 'failed' => 0];

    $ours = [];
    $noid = [];
    foreach (ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'pushed', 'published', 'failed', 'skipped', 'cancelled', 'dropped'], 'limit' => 2000]) as $p) {
      if ($p['type'] === 'human') continue;
      $g = (array) ($p['flags']['gbp'] ?? []);
      if (!empty($g['id'])) $ours[(string) $g['id']] = $p;
      elseif ($g || !empty($p['flags']['gbp_sending'])) $noid[] = $p;
    }
    if (!$ours && !$noid) return $changes; // nothing of Google's out: nothing to read
    // The lists Facebook's read-back just read, this run; read afresh when it did not run.
    $lists = isset($GLOBALS['ias_readback_lists']) ? (array) $GLOBALS['ias_readback_lists'] : [];
    $sched = ias_oneup_scheduled(!$lists);
    if (!$sched['ok']) {
      if (!$lists) ias_log('readback', 0, ['channel' => 'gbp', 'summary' => 'Google: read-back from OneUp failed: ' . $sched['error']]);
      return $changes;
    }
    $pub = $lists['published'] ?? ias_oneup_list('published', 4);
    $fail = $lists['failed'] ?? ias_oneup_list('failed', 2);
    foreach ($noid as $p) {
      $trying = empty($p['flags']['gbp']);
      $o = $trying ? (array) $p['flags']['gbp_sending'] : (array) $p['flags']['gbp'];
      foreach ($sched['rows'] as $row) {
        $oid = (string) ($row['post_id'] ?? '');
        if ($oid === '' || isset($ours[$oid]) || !ias_gbp_row_ok($row)) continue;
        if (ias_norm_text((string) ($row['content'] ?? '')) === ias_norm_text((string) ($o['text'] ?? '')) && substr((string) ($row['date_time'] ?? ''), 0, 16) === substr((string) ($o['when'] ?? ''), 0, 16)) {
          $ours[$oid] = $trying ? ias_gbp_adopt_attempt($p, $oid) : (ias_gbp_resolve_id($p) !== '' ? (ias_post_get((int) $p['id']) ?: $p) : $p);
          break;
        }
      }
    }

    $seen = [];
    foreach ($sched['rows'] as $row) {
      $oid = (string) ($row['post_id'] ?? '');
      if ($oid === '' || !isset($ours[$oid])) continue;
      $seen[$oid] = true;
      $p = $ours[$oid];
      $flags = $p['flags'];
      $g = (array) $flags['gbp'];
      $was_missing = isset($flags['gbp_missing']);
      unset($flags['gbp_missing']);
      $at = ias_oneup_time((string) ($row['date_time'] ?? ''), false);
      $text = (string) ($row['content'] ?? '');
      $moved = $at !== '' && substr($at, 0, 16) !== substr(str_replace('T', ' ', (string) ($g['when'] ?? '')), 0, 16);
      $edited = ias_norm_text($text) !== ias_norm_text((string) ($g['text'] ?? ''));
      if (!$moved && !$edited) { if ($was_missing) ias_post_update((int) $p['id'], ['flags' => $flags]); continue; }
      $flags['gbp']['when'] = substr($at, 0, 16);
      $flags['gbp']['text'] = $text;
      $flags['gbp_locked'] = trim(($moved ? 'moved' : '') . ($moved && $edited ? ' and ' : '') . ($edited ? 'edited' : ''));
      ias_post_update((int) $p['id'], ['flags' => $flags]);
      $what = ($moved ? 'moved it to ' . date_i18n('D M j, g:i a', strtotime($at)) : '') . ($moved && $edited ? ' and ' : '') . ($edited ? 'edited its words' : '');
      ias_post_trail((int) $p['id'], 'Google: in OneUp a person ' . $what . ' — kept as they made it');
      ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'channel' => 'gbp', 'summary' => 'Google: a person ' . $what . ' in OneUp — kept as they made it.']);
      $count[$moved ? 'moved' : 'edited']++;
    }

    $seen_pub = [];
    foreach ($pub['ok'] ? $pub['rows'] : [] as $row) {
      $oid = (string) ($row['post_id'] ?? '');
      if ($oid === '' || !isset($ours[$oid])) continue;
      $seen_pub[$oid] = true;
      $p = $ours[$oid];
      if (!empty($p['flags']['gbp']['published'])) continue;
      $at = ias_oneup_time((string) ($row['created_at'] ?? $row['date_time'] ?? ''), true);
      $flags = $p['flags'];
      $flags['gbp']['published'] = $at !== '' ? $at : $now;
      unset($flags['gbp_missing']);
      ias_post_update((int) $p['id'], ['flags' => $flags]);
      ias_post_trail((int) $p['id'], 'Google: published' . ($at !== '' ? ' ' . date_i18n('D M j, g:i a', strtotime($at)) : ''));
      ias_log('readback', (int) $p['listing_id'], ['post' => (int) $p['id'], 'type' => $p['type'], 'channel' => 'gbp', 'summary' => 'Google: published (OneUp #' . $oid . ').']);
      $count['published']++;
    }

    $seen_fail = [];
    foreach ($fail['ok'] ? $fail['rows'] : [] as $row) {
      $oid = (string) ($row['post_id'] ?? '');
      if ($oid === '' || !isset($ours[$oid])) continue;
      $seen_fail[$oid] = true;
      $p = $ours[$oid];
      if (($p['flags']['gbp_off'] ?? '') === 'failed') continue;
      $why = (string) ($row['failure_reason'] ?? $row['reason'] ?? $row['error'] ?? $row['message'] ?? 'no reason given');
      ias_gbp_switch_off($p, 'failed', 'OneUp could not publish it on Google: ' . $why . ' — fix it in OneUp (retry there) or leave it; the Facebook post is not affected.', false);
      $count['failed']++;
    }

    // Google copies that vanished before their time: a person deleted them in OneUp. Facebook's rules.
    if ($sched['complete']) {
      $grace = ias_now()->modify('-10 minutes')->format('Y-m-d H:i:s');
      $expected = array_filter($ours, function ($p) use ($now, $grace) {
        $g = (array) ($p['flags']['gbp'] ?? []);
        return in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'pushed'], true) && (string) ($g['when'] ?? '') > substr($now, 0, 16)
          && (string) ($g['at'] ?? '') <= $grace && empty($p['flags']['gbp_off']);
      });
      $missing = array_diff_key($expected, $seen, $seen_pub, $seen_fail);
      if (count($expected) >= 2 && count($missing) === count($expected)) {
        ias_log('readback', 0, ['channel' => 'gbp', 'summary' => 'None of the ' . count($expected) . ' Google posts the plugin scheduled is on OneUp\'s list — nothing is concluded from that; check the profile in OneUp and run Verify for Google.']);
      } else {
        $limit = ias_now()->modify('-30 minutes')->format('Y-m-d H:i:s');
        foreach ($missing as $p) {
          $since = (string) ($p['flags']['gbp_missing'] ?? '');
          if ($since === '') {
            $flags = $p['flags'];
            $flags['gbp_missing'] = $now;
            ias_post_update((int) $p['id'], ['flags' => $flags]);
            continue;
          }
          if ($since > $limit) continue;
          ias_gbp_switch_off($p, 'person', 'deleted in OneUp by a person — Google stays off for this post until someone sends it again', false);
          $count['deleted']++;
        }
      }
    }

    foreach (['moved' => 'moved in OneUp', 'edited' => 'edited in OneUp', 'deleted' => 'deleted in OneUp', 'published' => 'published', 'failed' => 'failed'] as $k => $label) {
      if ($count[$k]) $changes[] = 'Google ' . $count[$k] . ' ' . $label;
    }
    return $changes;
  }
}

/**
 * Bring Google in line with the plan, like ias_push_run_locked() does for
 * Facebook: schedule the posts that are ready (outside the veto window unless
 * approved), re-schedule changed ones, take back the ones no longer ready.
 * Runs only while Google is live; its dry run is channels.php's. Returns
 * ['pushed', 'deleted', 'failed', 'skipped' => […]].
 */
if (!function_exists('ias_gbp_run')) {
  function ias_gbp_run(): array {
    $out = ['pushed' => 0, 'deleted' => 0, 'failed' => 0, 'skipped' => []];
    if (!ias_gbp_live()) return $out;
    $now = ias_now();
    $nowsql = $now->format('Y-m-d H:i:s');
    $until = $now->modify('+' . (int) ias_setting('push_days', 21) . ' days')->format('Y-m-d H:i:s');
    $floor = $now->modify('+' . (int) ias_setting('lead_hours', 48) . ' hours')->format('Y-m-d H:i:00');
    foreach (ias_posts_query(['status' => ['planned', 'held', 'waiting', 'approved', 'pushed', 'published', 'dropped', 'skipped', 'cancelled'], 'limit' => 2000]) as $p) {
      if ($p['type'] === 'human') continue;
      $flags = $p['flags'];
      $g = (array) ($flags['gbp'] ?? []);
      $at = (string) ($p['scheduled_at'] ?? '');
      if ($p['status'] === 'published' || ($at !== '' && $at <= $nowsql)) continue; // its time came: it stays as it went

      $in_range = $at !== '' && $at <= $until;
      $why_not = ias_gbp_ready($p);
      if ($why_not === '' && empty($g) && empty($flags['gbp_was']) && $p['lane'] !== 'A' && $at < $floor) $why_not = 'its time is inside the ' . (int) ias_setting('lead_hours', 48) . '-hour veto window';

      if ($in_range && $why_not === '') {
        $req = ias_render_gbp($p);
        $hash = md5((string) wp_json_encode($req));
        if ($hash === ($g['hash'] ?? '') || !empty($flags['gbp_locked'])) continue;
        $blockers = [];
        if (ias_setting('paused', false)) $blockers[] = 'pushes are paused';
        if (!ias_gbp_type_on((string) $p['type'])) $blockers[] = ias_post_type((string) $p['type'])['label'] . ' posts are off';
        if ($blockers) {
          $out['skipped'][] = 'Google #' . (int) $p['id'] . ': ' . implode('; ', $blockers);
          if ($g && ias_gbp_delete($p, 'it changed while ' . implode(' and ', $blockers) . ' — it goes again when they allow')) {
            $f = (ias_post_get((int) $p['id']) ?: $p)['flags'];
            $f['gbp_was'] = true;
            ias_post_update((int) $p['id'], ['flags' => $f]);
            $out['deleted']++;
          }
          continue;
        }
        if (ias_gbp_send($p, $req, $hash)) $out['pushed']++; else $out['failed']++;
        continue;
      }
      if ($g) {
        if (!empty($flags['gbp_locked']) && !in_array($p['status'], ['skipped', 'cancelled'], true) && empty($flags['gbp_off'])) continue; // a person's edit in OneUp stays unless the post is called off
        $why = $why_not !== '' ? $why_not : 'moved past the next ' . (int) ias_setting('push_days', 21) . ' days';
        if (ias_gbp_delete($p, $why)) $out['deleted']++;
      }
    }
    return $out;
  }
}

/** The toolkit asks: is Google posted from here? Only when Listing posts really go to Google. */
add_filter('ia_social_gbp_managed', function ($managed) {
  return $managed || (ias_gbp_live() && ias_gbp_type_on('listing'));
});

/** The switches strip: where Google stands. */
add_filter('ias_channel_state', function (array $state, string $ch): array {
  if ($ch !== 'gbp') return $state;
  if (ias_gbp_live()) return ['on', 'Google: sent for real' . (ias_setting('paused', false) ? ' (paused)' : '')];
  $why = ias_gbp_blockers();
  $setup = array_values(array_filter($why, function ($w) { return !in_array($w, ['dry run is on', 'Send to Google is off'], true); }));
  return ['on', 'Google: dry run' . ($setup ? ' — ' . $setup[0] : ' — not sent')];
}, 10, 2);

/** Under the Google preview on a post's page: where it stands on Google, and the switch for this one post. */
if (!function_exists('ias_gbp_post_line')) {
  function ias_gbp_post_line(array $p, array $req): string {
    $f = (array) $p['flags'];
    $g = (array) ($f['gbp'] ?? []);
    $off = (string) ($f['gbp_off'] ?? '');
    $live = ias_gbp_live();
    $bits = [];
    if ($g) {
      $when = !empty($g['when']) ? date_i18n('D M j, g:i a', strtotime((string) $g['when'])) : '';
      $bits[] = !empty($g['published']) ? '<strong>Published on Google</strong> ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $g['published']))) . '.'
        : '<strong>On OneUp for Google</strong>' . ($when !== '' ? ' for ' . esc_html($when) : '') . (!empty($g['id']) ? ' (post #' . esc_html((string) $g['id']) . ')' : '') . '.';
      if (!empty($f['gbp_locked'])) $bits[] = 'A person ' . esc_html((string) $f['gbp_locked']) . ' it in OneUp — kept as they made it.';
    }
    if ($off !== '') {
      $bits[] = '<strong>Google is off for this post:</strong> ' . esc_html($off === 'failed' ? 'Google could not take it' . (!empty($f['gbp_error']) ? ' (' . (string) $f['gbp_error'] . ')' : '') : 'a person took it off') . '. The Facebook post is not affected.';
    } elseif (!$g) {
      $why = ias_gbp_ready($p);
      if ($live) $bits[] = $why === '' ? 'Goes to Google through OneUp a minute after the Facebook post' . ($p['lane'] !== 'A' ? ', once its time is outside the ' . (int) ias_setting('lead_hours', 48) . '-hour veto window' : '') . '.' : 'Not sent to Google yet: ' . esc_html($why) . '.';
      else $bits[] = 'Not sent — Google is a dry run: ' . esc_html(implode('; ', ias_gbp_blockers())) . '.';
    }
    $problems = ias_channel_problems($req, 'gbp');
    if ($problems) $bits[] = '<strong>' . esc_html(ucfirst(implode('; ', $problems))) . '.</strong>';
    $bits[] = 'Made from the Facebook words, without hashtags or phone numbers.';
    $button = '';
    if ($p['type'] !== 'human' && !in_array($p['status'], ['published', 'cancelled'], true)) {
      $button = $off !== '' ? ias_post_action_form($p, 'gbp_on', 'Send it to Google again', 'button')
        : ias_post_action_form($p, 'gbp_off', 'Keep this one off Google', 'button-link', '', $g ? 'Take this post off Google? The Facebook post stays as it is.' : '');
    }
    return '<p class="description ias-gbp-line">' . implode(' ', $bits) . '</p>' . $button;
  }
}

/** Keep one post off Google, or send it again (the post page's Google tab). */
add_action('ias_calendar_action', function (string $action): void {
  if (!in_array($action, ['gbp_off', 'gbp_on'], true)) return;
  $id = isset($_POST['ias_post']) ? (int) $_POST['ias_post'] : 0;
  $p = $id > 0 ? ias_post_get($id) : null;
  if (!$p || $p['type'] === 'human') return;
  $actor = ias_actor();
  $flags = $p['flags'];
  if ($action === 'gbp_off') {
    $flags['gbp_off'] = 'person';
    $flags['gbp_off_by'] = $actor;
    ias_post_update($id, ['flags' => $flags]);
    ias_post_trail($id, 'Google: kept off Google by a person', $actor);
    ias_log('skipped', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'channel' => 'gbp', 'summary' => 'Google: kept off Google for this post'], $actor);
    $gone = true;
    if (!empty($flags['gbp']) && ias_gbp_live() && ias_push_lock()) {
      try { $gone = ias_gbp_delete(ias_post_get($id) ?: $p, 'kept off Google by ' . ias_actor_name($actor)); } finally { ias_push_unlock(); }
    } elseif (!empty($flags['gbp'])) {
      $gone = false;
    }
    ia_flash_set('<div class="notice notice-success"><p>' . ($gone ? 'This post stays off Google. The Facebook post is as it was.' : 'This post stays off Google; its copy already on OneUp comes off on the next hourly run.') . '</p></div>');
    return;
  }
  unset($flags['gbp_off'], $flags['gbp_off_by'], $flags['gbp_fails'], $flags['gbp_error'], $flags['gbp_missing']);
  $flags['gbp_was'] = true; // a person's yes: it goes even inside the veto window, like a replacement
  ias_post_update($id, ['flags' => $flags]);
  ias_post_trail($id, 'Google: back on for this post', $actor);
  ias_log('planned', (int) $p['listing_id'], ['post' => $id, 'type' => $p['type'], 'channel' => 'gbp', 'summary' => 'Google: back on for this post'], $actor);
  ia_flash_set('<div class="notice notice-success"><p>Google is back on for this post: it goes with the next push.</p></div>');
}, 20);
