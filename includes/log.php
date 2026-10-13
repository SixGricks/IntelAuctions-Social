<?php
/**
 * Intel Auctions Social — the log.
 *
 * Everything this plugin does goes into the toolkit's event log
 * (`{prefix}ia_events`) under the `ias.` prefix — generate, approve, push,
 * edit read-back, drop, cancel, and every dry-run payload — with the listing
 * as the event's object id and the social post's id in its meta. The Log
 * screen (Auctions → Social Calendar → Log) reads it back.
 */

if (!defined('ABSPATH')) exit;

/** Event names this plugin writes (without the `ias.` prefix), with what each means. */
if (!function_exists('ias_log_events')) {
  function ias_log_events(): array {
    return [
      'planned'    => 'A post was added to the plan',
      'generated'  => 'Claude wrote a draft',
      'gen_failed' => 'A draft could not be written',
      'held'       => 'A draft stopped for a person (fair-housing phrase, missing fact, [VERIFY])',
      'graded'     => 'Claude graded a listing\'s photos',
      'moved'      => 'The plan moved a post to another day',
      'dropped'    => 'A post had no room in its window and was dropped',
      'late'       => 'A guaranteed post could not fit its window and runs late',
      'cancelled'  => 'A post was cancelled',
      'skipped'    => 'A person skipped a post',
      'edited'     => 'A person changed a post\'s text',
      'approved'   => 'A person approved a post',
      'emailed'    => 'An approval email or reminder went out',
      'review'     => 'The Monday review email went out',
      'dryrun'     => 'Dry run: the exact payload that would have gone to OneUp',
      'pushed'     => 'A post went to OneUp',
      'push_failed'=> 'OneUp refused a post',
      'readback'   => 'What OneUp reported back (human posts, edits, published)',
      'run'        => 'A planning run finished',
      'settings'   => 'Settings were saved',
      'paused'     => 'Pushing was paused or resumed',
    ];
  }
}

/**
 * Write one entry. $object_id is the listing (0 for the roundup and for runs);
 * put the social post's id in $meta['post'].
 */
if (!function_exists('ias_log')) {
  function ias_log(string $event, int $object_id = 0, array $meta = [], string $actor = 'system'): void {
    if (function_exists('ia_event_log')) ia_event_log('ias.' . $event, $object_id, $meta, $actor);
  }
}

/** A stored actor (a login, or 'system') as a person reads it: their display name; the plugin for 'system'. */
if (!function_exists('ias_actor_name')) {
  function ias_actor_name(string $login): string {
    if ($login === '' || $login === 'system') return 'the plugin';
    if (strpos($login, '@') !== false) return $login; // an approver who said yes from an email
    $user = get_user_by('login', $login);
    return ($user && (string) $user->display_name !== '') ? (string) $user->display_name : $login;
  }
}

/** An approver's name from their email: the WordPress user's first or display name, else the email's first part ("dj" → "DJ"). */
if (!function_exists('ias_person_name')) {
  function ias_person_name(string $email): string {
    $user = get_user_by('email', $email);
    if ($user) {
      $first = trim((string) get_user_meta($user->ID, 'first_name', true));
      if ($first !== '') return $first;
      if ((string) $user->display_name !== '' && strpos((string) $user->display_name, '@') === false) return (string) $user->display_name;
    }
    $local = (string) strtok($email, '@');
    return strlen($local) <= 3 ? strtoupper($local) : ucfirst($local);
  }
}

/** The actor name for the current user ('system' outside a login). */
if (!function_exists('ias_actor')) {
  function ias_actor(): string {
    $user = wp_get_current_user();
    return ($user && $user->exists()) ? (string) $user->user_login : 'system';
  }
}

/** Recent entries, newest first; $event narrows to one kind ('dryrun', 'dropped', …). */
if (!function_exists('ias_log_recent')) {
  function ias_log_recent(string $event = '', int $limit = 100): array {
    if (!function_exists('ia_events_recent')) return [];
    $rows = ia_events_recent('ias.' . $event, $limit);
    foreach ($rows as &$row) {
      $meta = isset($row['meta']) ? json_decode((string) $row['meta'], true) : null;
      $row['meta'] = is_array($meta) ? $meta : [];
      $row['short'] = substr((string) $row['event'], 4);
    }
    return $rows;
  }
}
