<?php
/**
 * Intel Auctions Social — Talk it over (0.7.0; the whole post from 0.8.0).
 *
 * DJ, Oct 13, 2026: "a little AI chatbot I can discuss the post with and have
 * it edit real time" — and then, "give the AI agent a way to edit first
 * comments and anything else about the post". A chat beside each post: a
 * person says what they don't like, and Claude changes the post on their
 * screen there and then — the words, the first comment, which of the
 * property's pictures go with it and in what order, and their alt text — with
 * the preview following. Nothing is saved until the person presses **Save
 * changes**, which goes through the same checks as any edit (bidding, figures,
 * fair housing, [VERIFY], the listing link in the comment), makes the post
 * theirs and never approves it. What Claude cannot change — when the post goes
 * out, its type, the listing's facts — it says where to change.
 *
 * Money: every message is one call through the toolkit's `ia_ai_generate()`
 * (feature `social.chat`, metered against the monthly cap), tried only when
 * Claude can be asked at all (`ias_ai_blocker()`), one at a time per post (a
 * lock), never twice for the same message about the same screen (a double
 * click gets the stored answer), and at most `chat_turns` answers per post
 * (Settings; 0 = no chat). Claude works under the same rules as a draft
 * (`ias_prompt_rules()`) plus the house voice, and may use a fact the person
 * gives it — saying the listing lacks it — but never one of its own; it
 * chooses pictures only from the post's and the property's own, by key.
 *
 * The conversation is kept in {prefix}ias_chat for 45 days (voice.php
 * prunes it): what the person asked is the best evidence of the brand voice
 * there is, so the brand-voice log carries it, and a rewrite of the post
 * follows it. Only Claude's versions are kept; a person's message keeps a
 * fingerprint of the screen it was about, for the repeat guard.
 */

if (!defined('ABSPATH')) exit;

/* ---------------- Storage ---------------- */

if (!function_exists('ias_chat_decode')) {
  function ias_chat_decode(array $row): array {
    $row['id'] = (int) $row['id'];
    $row['post_id'] = (int) $row['post_id'];
    $meta = json_decode((string) ($row['meta'] ?? ''), true);
    $row['meta'] = is_array($meta) ? $meta : [];
    $row['message'] = (string) ($row['message'] ?? '');
    $row['proposal'] = (string) ($row['proposal'] ?? '');
    return $row;
  }
}

/** Add one message. $who 'person' (meta 'was' = a fingerprint of the words as they stood) or 'claude' (proposal = its new text, '' = unchanged). */
if (!function_exists('ias_chat_add')) {
  function ias_chat_add(int $post_id, string $who, string $actor, string $message, string $proposal = '', array $meta = []): int {
    global $wpdb;
    $wpdb->insert(ias_table('chat'), [
      'post_id' => $post_id, 'who' => $who, 'actor' => mb_substr($actor, 0, 100), 'message' => $message,
      'proposal' => $proposal, 'meta' => wp_json_encode($meta), 'created_at' => ias_now_sql(),
    ]);
    return (int) $wpdb->insert_id;
  }
}

/** The conversation about a post, oldest first: since the last Start over ($since_start) and at most the last $limit messages. */
if (!function_exists('ias_chat_rows')) {
  function ias_chat_rows(int $post_id, bool $since_start = true, int $limit = 60): array {
    if (!ias_db_ready()) return [];
    global $wpdb;
    $from = 0;
    if ($since_start) {
      $p = ias_post_get($post_id);
      $from = (int) ($p['flags']['chat_from'] ?? 0);
    }
    $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . ias_table('chat') . ' WHERE post_id = %d AND id > %d ORDER BY id DESC LIMIT %d', $post_id, $from, max(1, $limit)), ARRAY_A);
    return array_reverse(array_map('ias_chat_decode', $rows));
  }
}

/** What people asked for on this post, oldest first (the last $limit) — Start over does not forget them. */
if (!function_exists('ias_chat_asks')) {
  function ias_chat_asks(int $post_id, int $limit = 20): array {
    if (!ias_db_ready()) return [];
    global $wpdb;
    $rows = (array) $wpdb->get_col($wpdb->prepare('SELECT message FROM ' . ias_table('chat') . " WHERE post_id = %d AND who = 'person' ORDER BY id DESC LIMIT %d", $post_id, max(1, $limit)));
    return array_reverse(array_map(function ($m) { return mb_substr(trim((string) $m), 0, 300); }, $rows));
  }
}

/** Claude's answers on this post so far (all of them: Start over does not reset the cap). */
if (!function_exists('ias_chat_turns_used')) {
  function ias_chat_turns_used(int $post_id): int {
    if (!ias_db_ready()) return 0;
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . ias_table('chat') . " WHERE post_id = %d AND who = 'claude'", $post_id));
  }
}

/** Start over: the chat on screen (and sent to Claude) begins again; what was asked stays in the log. */
if (!function_exists('ias_chat_start_over')) {
  function ias_chat_start_over(int $post_id): void {
    global $wpdb;
    $p = ias_post_get($post_id);
    if (!$p) return;
    $flags = $p['flags'];
    $flags['chat_from'] = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM ' . ias_table('chat') . ' WHERE post_id = %d', $post_id));
    ias_post_update($post_id, ['flags' => $flags]);
  }
}

/** One answer at a time per post (atomic; a lock older than 150 seconds is a crashed request's). */
if (!function_exists('ias_chat_lock')) {
  function ias_chat_lock(int $post_id): bool {
    global $wpdb;
    $name = 'ias_chat_lock_' . $post_id;
    $now = time();
    wp_cache_delete($name, 'options');
    if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now))) return true;
    $held = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    if ((int) $held > $now - 150) return false;
    return (bool) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $name, $held));
  }
}
if (!function_exists('ias_chat_unlock')) {
  function ias_chat_unlock(int $post_id): void {
    global $wpdb;
    $wpdb->delete($wpdb->options, ['option_name' => 'ias_chat_lock_' . $post_id]);
    wp_cache_delete('ias_chat_lock_' . $post_id, 'options');
  }
}

/* ---------------- The conversation ---------------- */

/**
 * The post as it stands on the person's screen: ['text', 'comment' (null when
 * the post has no first comment), 'media' (picture keys, in order), 'alt'
 * (key => alt text)]. Anything not sent is taken from the saved post.
 */
if (!function_exists('ias_chat_screen')) {
  function ias_chat_screen(array $p, $screen): array {
    $screen = is_array($screen) ? $screen : ['text' => (string) $screen];
    $c = (array) $p['content'];
    $norm = function ($v) { return trim(str_replace("\r\n", "\n", (string) $v)); };
    $text = $norm($screen['text'] ?? '');
    $has_comment = !empty($c['first_comment']);
    $comment = $has_comment ? (isset($screen['comment']) && $screen['comment'] !== null ? $norm($screen['comment']) : trim((string) $c['first_comment'])) : null;
    $media = isset($screen['media']) && is_array($screen['media']) ? array_values(array_filter(array_map('strval', $screen['media']))) : ias_media_keys($p);
    $alt = [];
    foreach ((array) ($screen['alt'] ?? []) as $k => $v) if (preg_match('/^a\d+$/', (string) $k)) $alt[(string) $k] = mb_substr(trim((string) $v), 0, 250);
    return ['text' => $text !== '' ? $text : (string) ($c['text'] ?? ''), 'comment' => $comment, 'media' => array_slice($media, 0, 40), 'alt' => $alt];
  }
}

/** A fingerprint of a screen, for the repeat guard. */
if (!function_exists('ias_chat_fingerprint')) {
  function ias_chat_fingerprint(array $screen): string {
    return md5((string) wp_json_encode([$screen['text'], $screen['comment'], $screen['media'], $screen['alt']]));
  }
}

/**
 * The pictures Claude may choose from: the post's own (as on the screen) and
 * the photos of each property it covers (the first 40 each), keyed as the
 * editor keys them, with their kind, grade, alt text and whether another post
 * of the property already uses them. key => ['label', 'alt' (as saved, or
 * graded), 'now' (as on the screen), 'thumb', 'own'].
 */
if (!function_exists('ias_chat_pictures')) {
  function ias_chat_pictures(array $p, array $facts_list, array $screen): array {
    $out = [];
    $saved = (array) ($p['content']['media']['images'] ?? []);
    $saved_alt = [];
    foreach ($saved as $n => $img) {
      if ((int) ($img['id'] ?? 0) > 0) { $saved_alt[(int) $img['id']] = (string) ($img['alt'] ?? ''); continue; }
      $out['g' . $n] = ['label' => 'the post\'s own drawn graphic', 'alt' => (string) ($img['alt'] ?? ''), 'thumb' => (string) ($img['url'] ?? ''), 'own' => false];
    }
    $add = function (int $att, array $f) use (&$out, $p, $saved_alt) {
      $key = 'a' . $att;
      if (isset($out[$key]) || !wp_attachment_is_image($att)) return;
      $g = ias_photo_grade($att);
      $used = in_array($att, ias_listing_used_images((int) ($f['id'] ?? 0), (int) $p['id']), true);
      $out[$key] = [
        'label' => trim(($g ? ($g['kind'] !== '' ? $g['kind'] : 'photo') . ', ' . (int) $g['q'] . '/10' : 'not graded') . ($used ? ', already used by another post about this property' : '')),
        'alt'   => $saved_alt[$att] ?? ias_media_apply_alt(['images' => [['id' => $att, 'alt' => ias_photo_alt($att, $f)]]], (array) $p['flags'])['images'][0]['alt'],
        'thumb' => (string) (wp_get_attachment_image_url($att, 'medium') ?: ''),
        'own'   => true,
      ];
    };
    foreach ($screen['media'] as $key) {
      if (preg_match('/^a(\d+)$/', $key, $m)) {
        $f = $facts_list[0] ?? ['id' => 0, 'title' => ''];
        foreach ($facts_list as $one) if (in_array((int) $m[1], array_map('intval', (array) $one['photos']), true)) { $f = $one; break; }
        $add((int) $m[1], $f);
      }
    }
    foreach ($facts_list as $f) {
      foreach (array_slice(array_map('intval', (array) $f['photos']), 0, 40) as $att) $add($att, $f);
    }
    foreach ($out as $k => $pic) $out[$k]['now'] = isset($screen['alt'][$k]) && $screen['alt'][$k] !== '' ? $screen['alt'][$k] : $pic['alt'];
    return $out;
  }
}

/** The editor tiles for picture keys: [['key', 'thumb', 'alt', 'alt0' (as saved: a change is what differs)], …], with the alt text given. */
if (!function_exists('ias_chat_tiles')) {
  function ias_chat_tiles(array $keys, array $pictures, array $alt): array {
    $tiles = [];
    foreach ($keys as $k) if (isset($pictures[$k])) $tiles[] = ['key' => $k, 'thumb' => $pictures[$k]['thumb'], 'alt' => (string) ($alt[$k] ?? $pictures[$k]['now'] ?? $pictures[$k]['alt']), 'alt0' => (string) $pictures[$k]['alt']];
    return $tiles;
  }
}

/** [system, user] for one turn. $screen: ias_chat_screen(); $pictures: ias_chat_pictures(); $history: the conversation so far (rows). */
if (!function_exists('ias_chat_prompt')) {
  function ias_chat_prompt(array $post, array $facts_list, $screen, array $history, string $message, string $person, ?array $pictures = null): array {
    $screen = is_array($screen) ? $screen : ias_chat_screen($post, $screen);
    $pictures = $pictures ?? ias_chat_pictures($post, $facts_list, $screen);
    $has_comment = $screen['comment'] !== null;
    $house = function_exists('ias_voice_rules_block') ? ias_voice_rules_block() : '';
    $system = ia_ai_brand_context() . "\n\n"
      . "You are helping {$person} at Intel Auctions revise one Facebook post for Intel Auctions' own page before it goes out. {$person} tells you what to change; you change the post and say in a sentence or two what you did. When they only ask a question, answer it plainly and leave the post as it is.\n"
      . "WHAT YOU CAN CHANGE: the post text" . ($has_comment ? ', the first comment' : '') . ", which pictures go with the post and in what order (at most " . ias_media_max() . "; the first one leads), and each picture's alt text. You cannot change when the post goes out, its type, or the listing's facts: say so, and that facts are changed on the listing itself.\n"
      . ias_prompt_rules()
      . ($house !== '' ? $house . "\n" : '')
      . "KEEP: the hashtag line at the end of the text, and every web address in the text" . ($has_comment ? ' and the first comment' : '') . ", exactly as they are, unless {$person} asks to change them. It stays a Facebook post: plain text, no headings, no markdown, no bold.\n"
      . ($has_comment ? "FIRST COMMENT: it carries the listing link (the whole reason for it). Keep every web address in it exactly as it is, character for character. The house rules and fair housing apply to it like the text.\n" : '')
      . "PICTURES: choose only from the PICTURES list, by key. Alt text says plainly what is in the picture for someone who cannot see it, from what the list says about it — never something the list does not say.\n"
      . "FACTS FROM {$person}: {$person} knows the sale. When they give you a fact the FACTS block does not have (a start time, a feature), use it as they gave it, and say in your reply that the listing does not say it, so the listing can be fixed too. Never add a fact of your own. When they ask for something the FACTS contradict (another price, date or way of bidding), do not write it — say what the facts say.\n"
      . "FAIR HOUSING applies to whatever {$person} asks for: if a request would break it, say so in a sentence and offer words that describe the property instead.\n"
      . "RULE: when {$person} states a preference that should hold for every post — wording, tone, length, structure, not something about this property — also give it as one short rule (for example \"Never use the word nestled.\"). Otherwise rule is null.\n"
      . "OUTPUT: JSON only, no code fences, with null for anything you did not change: {\"reply\": \"one to three short sentences to {$person}\", \"text\": \"the whole revised post text, or null\", "
      . ($has_comment ? "\"comment\": \"the whole revised first comment, or null\", " : '')
      . "\"pictures\": [\"the keys of the pictures, in order\"] or null, \"alt\": {\"a123\": \"new alt text\"} or null, \"rule\": null}";

    $facts = count($facts_list) > 1 ? array_map('ias_facts_for_prompt', $facts_list) : ias_facts_for_prompt($facts_list[0]);
    $user = 'POST TYPE: ' . ias_type_brief($post, $facts_list) . "\n\n"
      . "FACTS:\n" . wp_json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n"
      . "THE POST TEXT NOW (as {$person} sees it, with any edits of their own):\n<<<\n" . $screen['text'] . "\n>>>\n\n";
    if ($has_comment) $user .= "THE FIRST COMMENT NOW:\n<<<\n" . $screen['comment'] . "\n>>>\n\n";
    $lines = [];
    foreach ($screen['media'] as $i => $k) {
      if (!isset($pictures[$k])) continue;
      $lines[] = ($i + 1) . '. ' . $k . ' — ' . $pictures[$k]['label'] . ' — alt: "' . ($pictures[$k]['now'] ?? $pictures[$k]['alt']) . '"';
    }
    $user .= "PICTURES ON THE POST NOW, IN ORDER (the first leads):\n" . ($lines ? implode("\n", $lines) : 'none') . "\n\n";
    $other = [];
    foreach ($pictures as $k => $pic) if (!in_array($k, $screen['media'], true)) $other[] = $k . ' — ' . $pic['label'] . ' — alt: "' . $pic['alt'] . '"';
    if ($other) $user .= "THE PROPERTY'S OTHER PHOTOS (you may use these too):\n" . implode("\n", array_slice($other, 0, 60)) . "\n\n";
    $hist = [];
    foreach ($history as $row) {
      if ($row['who'] === 'person') $hist[] = $person . ': ' . mb_substr($row['message'], 0, 600);
      elseif ($row['who'] === 'claude') $hist[] = 'You: ' . mb_substr($row['message'], 0, 600) . (!empty($row['meta']['changed']) ? ' [you changed ' . implode(', ', (array) $row['meta']['changed']) . ']' : ($row['proposal'] !== '' ? ' [you changed the text]' : ''));
    }
    if ($hist) $user .= "THE CONVERSATION SO FAR:\n" . implode("\n", $hist) . "\n\n";
    $user .= strtoupper($person) . ' SAYS: ' . $message;
    return [$system, $user];
  }
}

/** What an answer changed, in words: ['the text', 'the first comment', 'the pictures', 'the alt text']. */
if (!function_exists('ias_chat_changed')) {
  function ias_chat_changed(array $a): array {
    $out = [];
    if (($a['text'] ?? null) !== null) $out[] = 'the text';
    if (($a['comment'] ?? null) !== null) $out[] = 'the first comment';
    if (($a['pictures'] ?? null) !== null) $out[] = 'the pictures';
    if (!empty($a['alt'])) $out[] = 'the alt text';
    return $out;
  }
}

/** A stored answer as the client gets it (also for the repeat guard and Use this version). */
if (!function_exists('ias_chat_answer')) {
  function ias_chat_answer(array $row, array $pictures, array $screen_alt): array {
    $m = $row['meta'];
    $keys = isset($m['pictures']) && is_array($m['pictures']) ? $m['pictures'] : null;
    $alt = array_merge($screen_alt, (array) ($m['alt'] ?? []));
    $tiles = null;
    if ($keys !== null || !empty($m['alt'])) $tiles = ias_chat_tiles($keys ?? (array) ($m['media_was'] ?? []), $pictures, $alt);
    return [
      'reply'   => $row['message'],
      'text'    => $row['proposal'] !== '' ? $row['proposal'] : null,
      'comment' => isset($m['comment']) && is_string($m['comment']) ? $m['comment'] : null,
      'pics'    => $tiles,
      'changed' => (array) ($m['changed'] ?? ($row['proposal'] !== '' ? ['the text'] : [])),
      'diff'    => array_filter(['text' => (string) ($m['diff_text'] ?? ''), 'comment' => (string) ($m['diff_comment'] ?? '')]),
      'holds'   => (array) ($m['holds'] ?? []),
      'fixes'   => (array) ($m['fixes'] ?? []),
      'rule'    => ($m['rule'] ?? '') !== '' ? (string) $m['rule'] : null,
    ];
  }
}

/** The answer already given to this same message about this same screen, a moment ago (a double click, a resend) — or null. */
if (!function_exists('ias_chat_repeat')) {
  function ias_chat_repeat(int $post_id, string $message, string $fingerprint, array $pictures = [], array $screen_alt = []): ?array {
    global $wpdb;
    $rows = array_map('ias_chat_decode', (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . ias_table('chat') . ' WHERE post_id = %d ORDER BY id DESC LIMIT 2', $post_id), ARRAY_A));
    if (count($rows) < 2 || $rows[0]['who'] !== 'claude' || $rows[1]['who'] !== 'person') return null;
    if ($rows[1]['message'] !== $message || (string) ($rows[1]['meta']['was'] ?? '') !== $fingerprint) return null;
    if (strtotime((string) $rows[0]['created_at']) < strtotime(ias_now_sql()) - 120) return null;
    $answer = ias_chat_answer($rows[0], $pictures, $screen_alt);
    return ['ok' => true, 'repeat' => true, 'used' => ias_chat_turns_used($post_id), 'max' => (int) ias_setting('chat_turns', 30), 'id' => $rows[0]['id'], 'html' => ias_chat_change_html($answer)] + $answer;
  }
}

/**
 * One turn: the person's message about the post as it stands on their screen
 * ($screen: the text — a string, as before — or ['text', 'comment', 'media',
 * 'alt']; unsaved edits and all). Returns ['ok' => true, 'reply', 'text',
 * 'comment' (each the new version, or null), 'pics' (the new pictures as
 * editor tiles, or null), 'changed' (in words), 'holds' (what would stop it
 * on save), 'fixes', 'rule' (a house rule to offer, or null), 'used', 'max']
 * or ['ok' => false, 'error', 'code']. Never saves the post.
 */
if (!function_exists('ias_chat_send')) {
  function ias_chat_send(int $post_id, string $message, $screen, string $actor): array {
    $fail = function (string $error, int $code = 400) { return ['ok' => false, 'error' => $error, 'code' => $code]; };
    $p = ias_post_get($post_id);
    if (!$p) return $fail('No such post.', 404);
    if (!ias_post_editable($p) || trim((string) ($p['content']['text'] ?? '')) === '') return $fail('This post cannot be changed here now.', 409);
    $max = (int) ias_setting('chat_turns', 30);
    if ($max <= 0) return $fail('Talk it over is switched off (Settings → Talk it over).', 403);
    $message = trim(str_replace("\r\n", "\n", $message));
    if ($message === '') return $fail('Say what should change.');
    if (mb_strlen($message) > 1500) return $fail('Keep a message under 1,500 characters.');
    $screen = ias_chat_screen($p, $screen);
    if (mb_strlen($screen['text']) > 8000 || mb_strlen((string) $screen['comment']) > 4000) return $fail('The post is too long to talk over.');
    $facts_list = ias_post_facts($p);
    if (!$facts_list) return $fail('The listing this post was for is gone.', 409);
    $pictures = ias_chat_pictures($p, $facts_list, $screen);
    $fingerprint = ias_chat_fingerprint($screen);

    $again = ias_chat_repeat($post_id, $message, $fingerprint, $pictures, $screen['alt']);
    if ($again) return $again;
    $used = ias_chat_turns_used($post_id);
    if ($used >= $max) return $fail('This post has had its ' . $max . ' answers from Claude (Settings → Talk it over). Edit it yourself, or press Rewrite for a fresh draft.', 429);
    $why = ias_ai_blocker();
    if ($why !== '') return $fail($why, 503);
    if (!ias_chat_lock($post_id)) return $fail('Claude is still answering the last message about this post.', 409);

    try {
      $person = ias_actor_name($actor);
      [$system, $user] = ias_chat_prompt($p, $facts_list, $screen, ias_chat_rows($post_id, true, 12), $message, $person === 'the plugin' ? 'the team' : $person, $pictures);
      $raw = ia_ai_generate($system, $user, 2000, 'social.chat');
      if (is_wp_error($raw)) return $fail('Claude could not answer: ' . $raw->get_error_message(), 502);

      $json = function_exists('ias_json_object_from_text') ? ias_json_object_from_text((string) $raw) : null;
      $text = ''; $comment = null; $keys = null; $alt = []; $rule = '';
      if (is_array($json) && array_key_exists('reply', $json)) {
        $reply = trim((string) $json['reply']);
        if (isset($json['text']) && is_string($json['text'])) $text = trim(str_replace("\r\n", "\n", $json['text']));
        if ($screen['comment'] !== null && isset($json['comment']) && is_string($json['comment'])) $comment = trim(str_replace("\r\n", "\n", $json['comment']));
        if (isset($json['pictures']) && is_array($json['pictures'])) {
          $keys = [];
          foreach ($json['pictures'] as $k) { $k = trim((string) $k); if (isset($pictures[$k]) && !in_array($k, $keys, true)) $keys[] = $k; }
          $keys = array_slice($keys, 0, ias_media_max());
        }
        if (isset($json['alt']) && is_array($json['alt'])) {
          foreach ($json['alt'] as $k => $a) {
            $k = (string) $k; $a = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $a)), 0, 250);
            if ($a !== '' && preg_match('/^a\d+$/', $k) && isset($pictures[$k]) && $a !== ($pictures[$k]['now'] ?? $pictures[$k]['alt'])) $alt[$k] = $a;
          }
        }
        $rule = isset($json['rule']) && is_string($json['rule']) ? trim($json['rule']) : '';
      } else { // not the JSON asked for: show what it said, change nothing
        $reply = trim((string) $raw);
      }
      if ($text !== '' && ias_history_norm($text) === ias_history_norm($screen['text'])) $text = '';
      if ($comment !== null && ($comment === '' || $comment === $screen['comment'])) $comment = null;
      if ($keys !== null && $keys === $screen['media']) $keys = null;
      $after = $keys ?? $screen['media'];
      $alt = array_intersect_key($alt, array_flip($after)); // alt text only for pictures that stay on the post
      $changed = ias_chat_changed(['text' => $text !== '' ? $text : null, 'comment' => $comment, 'pictures' => $keys, 'alt' => $alt]);
      if ($reply === '') $reply = $changed ? 'Here it is.' : 'I left the post as it is.';
      $reply = mb_substr($reply, 0, 1500);

      $holds = []; $fixes = [];
      if ($text !== '') {
        $check = ias_recheck_post($p, $text);
        $holds = (array) $check['holds'];
        $fixes = (array) $check['fixes'];
      }
      if ($comment !== null) $holds = array_merge($holds, ias_comment_holds($p, $comment, $facts_list));
      foreach (ias_fair_housing_check(implode(' ', $alt), (string) ias_setting('fair_housing_extra', '')) as $hit) $holds[] = 'Fair housing in the alt text: "' . $hit['phrase'] . '" (' . $hit['why'] . ').';
      if ($keys !== null && count($keys) === 0) $holds[] = 'No pictures: the post would go out with none.';
      $holds = array_values(array_unique($holds));
      if ($rule !== '' && strtolower($rule) !== 'null' && function_exists('ias_voice_clean_rules')) {
        [$ok] = ias_voice_clean_rules([$rule]);
        $rule = (string) ($ok[0] ?? '');
        foreach (ias_voice_notes() as $have) if (mb_strtolower($have) === mb_strtolower($rule)) $rule = '';
      } else {
        $rule = '';
      }

      ias_chat_add($post_id, 'person', $actor, $message, '', ['was' => $fingerprint]); // a fingerprint, not a second copy of the post
      $meta = ['holds' => $holds, 'fixes' => $fixes, 'rule' => $rule, 'changed' => $changed];
      // What changed, word by word, to show in the answer itself: the field it changed is usually scrolled out of sight.
      if ($text !== '') $meta['diff_text'] = ias_word_diff_html(ias_word_diff($screen['text'], $text));
      if ($comment !== null) $meta['diff_comment'] = ias_word_diff_html(ias_word_diff((string) $screen['comment'], $comment));
      if ($comment !== null) $meta['comment'] = $comment;
      if ($keys !== null) $meta['pictures'] = $keys;
      if ($alt) { $meta['alt'] = $alt; $meta['media_was'] = $after; }
      $id = ias_chat_add($post_id, 'claude', 'claude', $reply, $text, $meta);
      ias_log('chat', (int) $p['listing_id'], ['post' => $post_id, 'type' => $p['type'],
        'summary' => 'Talked it over: "' . mb_substr($message, 0, 140) . '" — ' . ($changed ? 'Claude changed ' . implode(', ', $changed) . ' (not saved)' : 'Claude answered')], $actor);
      $row = ['message' => $reply, 'proposal' => $text, 'meta' => $meta];
      $answer = ias_chat_answer($row, $pictures, $screen['alt']);
      return ['ok' => true, 'used' => $used + 1, 'max' => $max, 'id' => $id, 'html' => ias_chat_change_html($answer)] + $answer;
    } finally {
      ias_chat_unlock($post_id);
    }
  }
}

/** The preview column for the post as it stands on the screen (words, comment, pictures, alt text — unsaved). */
if (!function_exists('ias_preview_html')) {
  function ias_preview_html(array $p, $text, ?string $comment = null, ?array $media = null, array $alt = []): string {
    $screen = is_array($text) ? $text : ['text' => (string) $text, 'comment' => $comment, 'media' => $media, 'alt' => $alt];
    $p['content']['text'] = trim(str_replace("\r\n", "\n", (string) ($screen['text'] ?? '')));
    if (isset($screen['comment']) && $screen['comment'] !== null && !empty($p['content']['first_comment'])) $p['content']['first_comment'] = (string) $screen['comment'];
    if (isset($screen['media']) && is_array($screen['media'])) {
      $images = ias_media_from_keys($p, $screen['media'], ias_post_facts($p));
      foreach ($images as $i => $img) if ((int) ($img['id'] ?? 0) > 0 && !empty($screen['alt']['a' . (int) $img['id']])) $images[$i]['alt'] = (string) $screen['alt']['a' . (int) $img['id']];
      $p['content']['media'] = ['kind' => $images ? 'images' : 'none', 'images' => $images, 'video_url' => ''];
    }
    ob_start();
    do_action('ias_post_view_preview', $p);
    return (string) ob_get_clean();
  }
}

/* ---------------- The site's answers (admin-ajax) ---------------- */

/** The post a request is about, after the capability and its nonce — or the JSON error and the end. */
if (!function_exists('ias_ajax_post')) {
  function ias_ajax_post(): array {
    $id = isset($_POST['post']) ? (int) $_POST['post'] : 0;
    if (!current_user_can('manage_options') || !check_ajax_referer('ias_post_' . $id, '_ajax_nonce', false)) {
      wp_send_json_error(['message' => 'Your login has run out, or this page is old — reload it.'], 403);
    }
    $p = ias_post_get($id);
    if (!$p) wp_send_json_error(['message' => 'No such post.'], 404);
    return $p;
  }
}

/** The screen a request carries: text, comment, media (comma keys) and alt (JSON). */
if (!function_exists('ias_ajax_screen')) {
  function ias_ajax_screen(): array {
    $alt = json_decode((string) wp_unslash($_POST['alt'] ?? ''), true);
    return [
      'text'    => (string) wp_unslash($_POST['text'] ?? ''),
      'comment' => isset($_POST['comment']) ? (string) wp_unslash($_POST['comment']) : null,
      'media'   => isset($_POST['media']) ? array_values(array_filter(array_map('trim', explode(',', (string) wp_unslash($_POST['media']))))) : null,
      'alt'     => is_array($alt) ? $alt : [],
    ];
  }
}

add_action('wp_ajax_ias_chat', function (): void {
  $p = ias_ajax_post();
  $r = ias_chat_send((int) $p['id'], (string) wp_unslash($_POST['message'] ?? ''), ias_ajax_screen(), ias_actor());
  if (empty($r['ok'])) wp_send_json_error(['message' => (string) $r['error']], (int) ($r['code'] ?? 400));
  wp_send_json_success($r);
});

add_action('wp_ajax_ias_chat_new', function (): void {
  $p = ias_ajax_post();
  ias_chat_start_over((int) $p['id']);
  wp_send_json_success(['ok' => true]);
});

add_action('wp_ajax_ias_preview', function (): void {
  $p = ias_ajax_post();
  $screen = ias_ajax_screen();
  $screen['text'] = trim(str_replace("\r\n", "\n", $screen['text']));
  if ($screen['text'] === '' || mb_strlen($screen['text']) > 8000) wp_send_json_error(['message' => 'No text to preview.'], 400);
  wp_send_json_success(['html' => ias_preview_html($p, $screen)]);
});

add_action('wp_ajax_ias_voice_rule', function (): void {
  ias_ajax_post();
  $r = ias_voice_rule_add((string) wp_unslash($_POST['rule'] ?? ''), ias_actor());
  if (!$r['ok']) wp_send_json_error(['message' => $r['message']], 400);
  wp_send_json_success(['message' => $r['message']]);
});

/* ---------------- The panel ---------------- */

/**
 * What an answer changed, shown in the answer itself (the field it changed is
 * usually scrolled out of sight): the words taken out and put in, the new
 * pictures as thumbnails, Show me, and Use this version. Its note — Saved, On
 * the post above, Not on the post now — is kept true by the script as the
 * post changes; drawn here, it compares the answer with the saved post.
 */
if (!function_exists('ias_chat_change_html')) {
  function ias_chat_change_html(array $a, ?array $saved = null): string {
    if (empty($a['changed'])) return '';
    $state = ['text' => $a['text'], 'comment' => $a['comment'], 'pics' => $a['pics']];
    $html = '<div class="ias-msg-change"><div class="ias-msg-what">Changed ' . esc_html(ias_chat_list((array) $a['changed'])) . '</div>';
    foreach (['text' => 'Text', 'comment' => 'First comment'] as $k => $label) {
      if (!empty($a['diff'][$k])) $html .= '<div class="ias-msg-diff"><span class="ias-msg-diff-h">' . esc_html($label) . '</span>' . $a['diff'][$k] . '</div>'; // built by ias_word_diff_html(), every word escaped
    }
    if (is_array($a['pics']) && in_array('the pictures', (array) $a['changed'], true)) {
      $html .= '<div class="ias-msg-pics" aria-label="The pictures, in order">';
      foreach (array_slice($a['pics'], 0, 10) as $i => $t) $html .= '<img src="' . esc_url((string) $t['thumb']) . '" alt="' . esc_attr(($i === 0 ? 'Lead: ' : '') . (string) $t['alt']) . '" title="' . esc_attr((string) $t['alt']) . '">';
      $html .= '</div>';
    }
    $field = $a['text'] !== null ? 'text' : ($a['comment'] !== null ? 'comment' : 'pics');
    $note = 'Not saved';
    if ($saved !== null) {
      $same = ($a['text'] === null || trim((string) $a['text']) === trim((string) $saved['text']))
        && ($a['comment'] === null || trim((string) $a['comment']) === trim((string) $saved['comment']))
        && ($a['pics'] === null || array_map(function ($t) { return $t['key'] . "\1" . $t['alt']; }, $a['pics']) === array_map(function ($t) { return $t['key'] . "\1" . $t['alt']; }, $saved['pics']));
      $note = $same ? 'Saved' : 'Not saved — not on the post now';
    }
    $html .= '<div class="ias-msg-acts"><span class="ias-msg-note">' . esc_html($note) . '</span> <button type="button" class="button-link ias-show" data-field="' . esc_attr($field) . '">Show me</button> <button type="button" class="button-link ias-use" data-state="' . esc_attr((string) wp_json_encode($state)) . '">Use this version</button></div></div>';
    if (!empty($a['holds'])) $html .= '<div class="ias-msg-warn">Saved like this, it would stop for a decision: ' . esc_html(implode(' ', (array) $a['holds'])) . '</div>';
    return $html;
  }
}

/** One message in the panel (the same markup the script builds). $answer: ias_chat_answer() for Claude's; $saved: the post as saved (text, comment, pics). */
if (!function_exists('ias_chat_bubble')) {
  function ias_chat_bubble(array $row, ?array $answer = null, ?array $saved = null): string {
    if ($row['who'] === 'person') {
      return '<div class="ias-msg me"><b>' . esc_html(ias_actor_name((string) $row['actor'])) . '</b><div class="ias-msg-t">' . esc_html($row['message']) . '</div></div>';
    }
    $a = $answer ?? ['changed' => $row['proposal'] !== '' ? ['the text'] : [], 'text' => $row['proposal'] !== '' ? $row['proposal'] : null, 'comment' => null, 'pics' => null, 'diff' => [], 'holds' => (array) ($row['meta']['holds'] ?? []), 'rule' => $row['meta']['rule'] ?? null];
    $out = '<div class="ias-msg ai"><b>Claude</b><div class="ias-msg-t">' . esc_html($row['message']) . '</div>' . ias_chat_change_html($a, $saved);
    $rule = (string) ($a['rule'] ?? '');
    if ($rule !== '' && !in_array(mb_strtolower($rule), array_map('mb_strtolower', ias_voice_notes()), true)) {
      $out .= '<div class="ias-msg-rule">Remember for every post? <em>' . esc_html($rule) . '</em> <button type="button" class="button button-small ias-rule-add" data-rule="' . esc_attr($rule) . '">Add to the house voice</button></div>';
    }
    return $out . '</div>';
  }
}

/** "the text, the first comment and the pictures". */
if (!function_exists('ias_chat_list')) {
  function ias_chat_list(array $items): string {
    $items = array_values($items);
    if (count($items) <= 1) return (string) ($items[0] ?? '');
    return implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
  }
}

/** Under the Edit card: the chat. */
add_action('ias_post_view_after_edit', function (array $p): void {
  $max = (int) ias_setting('chat_turns', 30);
  if ($max <= 0 || !ias_post_editable($p) || trim((string) ($p['content']['text'] ?? '')) === '') return;
  $why = ias_ai_blocker();
  $used = ias_chat_turns_used((int) $p['id']);
  $rows = ias_chat_rows((int) $p['id'], true, 40);
  $facts_list = ias_post_facts($p);
  $pictures = $facts_list ? ias_chat_pictures($p, $facts_list, ias_chat_screen($p, [])) : [];
  $saved = ['text' => (string) ($p['content']['text'] ?? ''), 'comment' => (string) ($p['content']['first_comment'] ?? ''), 'pics' => function_exists('ias_pic_items') ? ias_pic_items((array) ($p['content']['media']['images'] ?? [])) : []];
  $me = ias_actor_name(ias_actor());
  $off = $why !== '' || $used >= $max;
  $has_comment = !empty($p['content']['first_comment']);

  echo '<div class="ias-card ias-chat" id="ias-chat" data-me="' . esc_attr($me) . '" data-max="' . (int) $max . '">';
  echo '<h2>Talk it over <span class="description">with Claude</span></h2>';
  echo '<div class="ias-chat-log" id="ias-chat-log" aria-live="polite">';
  echo '<p class="description ias-chat-empty"' . ($rows ? ' hidden' : '') . '>Say what you don\'t like and Claude changes the post above for you to check and save — the text' . ($has_comment ? ', the first comment' : '') . ', which pictures go with it and in what order, and their alt text. "Shorter", "less salesy", "lead with the aerial", "say View full listing in the comment". It keeps to the listing\'s facts and the house voice.</p>';
  foreach ($rows as $r) echo ias_chat_bubble($r, $r['who'] === 'claude' ? ias_chat_answer($r, $pictures, []) : null, $saved);
  echo '</div>';
  if ($why !== '') {
    echo '<p class="description">' . esc_html($why) . '</p>';
  } elseif ($used >= $max) {
    echo '<p class="description">This post has had its ' . (int) $max . ' answers from Claude. Edit it yourself, or raise the number in Settings → Talk it over.</p>';
  }
  echo '<div class="ias-chat-quick">';
  foreach (['Shorter', 'Plainer — less salesy', 'Warmer', 'Lead with the auction date', 'Fix grammar only'] as $q) echo '<button type="button" class="ias-chat-chip"' . ($off ? ' disabled' : '') . '>' . esc_html($q) . '</button>';
  echo '</div>';
  echo '<div class="ias-chat-row"><label for="ias-chat-in" class="screen-reader-text">Your message to Claude</label><textarea id="ias-chat-in" rows="2" maxlength="1500" placeholder="What should change?"' . ($off ? ' disabled' : '') . '></textarea>'
    . '<button type="button" class="button" id="ias-chat-send"' . ($off ? ' disabled' : '') . '>Send</button></div>';
  echo '<div class="ias-chat-save notice notice-warning inline" id="ias-chat-save" hidden><p><strong>Not saved yet.</strong> <button form="ias-text-form" class="button ias-save" data-ia-busy="Saving…">Save changes</button> <button type="button" class="button-link ias-discard">Discard changes</button><br><span class="description">Saving never approves the post.</span></p></div>';
  echo '<div class="ias-foot"><div class="ias-actions"><button type="button" class="button-link" id="ias-chat-undo" hidden>↶ Undo the last change</button></div>'
    . '<div class="ias-actions"><span class="description" id="ias-chat-used">' . (int) $used . ' of ' . (int) $max . ' answers used</span>'
    . '<button type="button" class="button-link" id="ias-chat-new"' . ($rows ? '' : ' hidden') . '>Start over</button></div></div>';
  echo '<p class="description">Nothing changes until you press <strong>Save changes</strong>, and saving never approves. Each message is one Claude call, counted against the monthly cap. What you ask for is kept with the post for <a href="' . esc_url(ias_admin_url(IAS_PAGE_VOICE)) . '">Brand voice</a>.</p>';
  echo '</div>';
});

/** The panel's script (after the post editor's, which gives it window.iasPost). */
add_action('admin_enqueue_scripts', function (): void {
  if (($_GET['page'] ?? '') !== IAS_PAGE_CALENDAR || empty($_GET['post']) || !current_user_can('manage_options')) return;
  wp_enqueue_script('jquery-ui-sortable'); // the post editor's handle (admin-post.php): this script prints after it
  wp_add_inline_script('jquery-ui-sortable', <<<'JS'
jQuery(function ($) {
  var ed = window.iasPost, $chat = $('#ias-chat'), $text = $('#ias-text');
  if (!ed || !$text.length) return;
  var ajax = $text.data('ajax') || window.ajaxurl, nonce = $text.data('nonce'), post = $text.data('post'); // on the text box: the preview follows it with or without the chat
  var timer = null, seq = 0;

  // The preview follows the post as it is edited — by hand or by Claude — unsaved.
  function preview() {
    if (!nonce) return;
    var mine = ++seq, $col = $('.ias-post-preview');
    $col.addClass('is-updating');
    $.post(ajax, $.extend({ action: 'ias_preview', post: post, _ajax_nonce: nonce }, ed.payload()))
      .done(function (r) {
        if (mine !== seq || !r || !r.success) return;
        var net = $col.find('.ias-net.is-on').data('net');
        $col.html(r.data.html);
        if (net) $col.find('.ias-net[data-net="' + net + '"]').trigger('click');
      })
      .always(function () { if (mine === seq) $col.removeClass('is-updating'); });
  }
  $(document).on('ias:changed', function (e, dirty, now) {
    $('#ias-chat-save').prop('hidden', !dirty);
    clearTimeout(timer); timer = setTimeout(preview, now ? 0 : 700);
  });

  if (!$chat.length) return;
  var $log = $('#ias-chat-log'), $in = $('#ias-chat-in'), $send = $('#ias-chat-send'), $undo = $('#ias-chat-undo');
  var undo = [], busy = false, FIELDS = { text: 'the text', comment: 'the first comment', pics: 'the pictures' };

  function scroll() { $log.scrollTop($log[0].scrollHeight); }
  function bubble(cls, who, words) {
    $log.find('.ias-chat-empty').prop('hidden', true);
    $('#ias-chat-new').prop('hidden', false);
    var $b = $('<div class="ias-msg"/>').addClass(cls).append($('<b/>').text(who)).append($('<div class="ias-msg-t"/>').text(words));
    $log.append($b); scroll();
    return $b;
  }
  function picsSig(p) { return $.map(p || [], function (t) { return t.key + '\u0001' + t.alt; }).join('\u0002'); }
  function same(f, a, b) { return f === 'pics' ? picsSig(a) === picsSig(b) : $.trim(a || '') === $.trim(b || ''); }
  function fieldsOf(st) { return $.grep(['text', 'comment', 'pics'], function (f) { return st[f] !== null && st[f] !== undefined; }); }

  // Put a version on the screen. Undo puts back only what this changed, and asks before it would throw away typing done since.
  function apply(st) {
    var now = ed.state(), entry = { before: {}, after: {} };
    $.each(fieldsOf(st), function (i, f) { entry.before[f] = now[f]; entry.after[f] = st[f]; });
    if ($.isEmptyObject(entry.after)) return;
    undo.push(entry);
    ed.restore(st, true);
    $undo.prop('hidden', false);
  }
  $undo.on('click', function () {
    if (!undo.length) return;
    var entry = undo[undo.length - 1], now = ed.state(), since = [];
    $.each(entry.after, function (f) { if (!same(f, now[f], entry.after[f])) since.push(FIELDS[f]); });
    if (since.length && !window.confirm('You changed ' + since.join(' and ') + ' after Claude did. Undo puts back what was there before Claude\'s change and throws that away too. Undo anyway?')) return;
    undo.pop();
    ed.restore(entry.before, true);
    $undo.prop('hidden', !undo.length);
  });

  // Each answer says where its version stands: Saved, On the post above (not saved yet), or Not on the post now.
  function relabel() {
    var now = ed.state(), saved = ed.saved();
    $log.find('.ias-msg-change').each(function () {
      var st; try { st = JSON.parse($(this).find('.ias-use').attr('data-state')); } catch (e) { return; }
      var fs = fieldsOf(st), onScreen = true, isSaved = true;
      $.each(fs, function (i, f) { if (!same(f, now[f], st[f])) onScreen = false; if (!same(f, saved[f], st[f])) isSaved = false; });
      $(this).toggleClass('is-on', onScreen).toggleClass('is-off', !onScreen);
      $(this).find('.ias-msg-note').text(onScreen ? (isSaved ? 'Saved' : 'On the post above — not saved yet') : (isSaved ? 'Saved, but changed since on the screen' : 'Not on the post now'));
      $(this).find('.ias-use').prop('hidden', onScreen);
    });
  }
  $(document).on('ias:changed', relabel);
  $log.on('click', '.ias-show', function () {
    var f = $(this).attr('data-field'), $el = f === 'pics' ? $('#ias-pictures') : $(f === 'comment' ? '#ias-comment' : '#ias-text');
    if (!$el.length) return;
    $el[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    var $flash = f === 'pics' ? $('#ias-pics') : $el;
    $flash.removeClass('ias-flash'); setTimeout(function () { $flash.addClass('ias-flash'); setTimeout(function () { $flash.removeClass('ias-flash'); }, 1300); }, 350);
  });
  function lock(on) { busy = on; $send.prop('disabled', on); $chat.find('.ias-chat-chip').prop('disabled', on); }

  function send(msg) {
    msg = $.trim(msg || '');
    if (busy || !msg) return;
    lock(true);
    bubble('me', $chat.data('me'), msg);
    $in.val('');
    var $wait = bubble('ai is-wait', 'Claude', 'Thinking…');
    $.post(ajax, $.extend({ action: 'ias_chat', post: post, _ajax_nonce: nonce, message: msg }, ed.payload()))
      .done(function (r) { $wait.remove(); answer(r, msg); })
      .fail(function (x) { $wait.remove(); answer(x.responseJSON || null, msg); })
      .always(function () { lock(false); $in.trigger('focus'); });
  }
  function answer(r, msg) {
    if (!r || !r.success) {
      bubble('err', 'Not sent', (r && r.data && r.data.message) || 'No answer from the site — check the connection and send it again.');
      if (!$in.val()) $in.val(msg);
      return;
    }
    var d = r.data, $b = bubble('ai', 'Claude', d.reply);
    if (d.changed && d.changed.length) {
      $b.append(d.html); // built on the site: every word escaped there
      apply({ text: d.text, comment: d.comment, pics: d.pics });
    }
    if (d.rule) {
      $b.append($('<div class="ias-msg-rule"/>').append('Remember for every post? ').append($('<em/>').text(d.rule)).append(' ')
        .append($('<button type="button" class="button button-small ias-rule-add"/>').attr('data-rule', d.rule).text('Add to the house voice')));
    }
    $('#ias-chat-used').text(d.used + ' of ' + d.max + ' answers used');
    if (d.used >= d.max) { $in.prop('disabled', true); $send.prop('disabled', true); $chat.find('.ias-chat-chip').prop('disabled', true); }
    relabel();
    scroll();
  }

  $send.on('click', function () { send($in.val()); });
  $in.on('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send($in.val()); } });
  $chat.on('click', '.ias-chat-chip', function () { send($(this).text()); });
  $log.on('click', '.ias-use', function () { try { apply(JSON.parse($(this).attr('data-state'))); } catch (e) {} });
  $log.on('click', '.ias-rule-add', function () {
    var $btn = $(this).prop('disabled', true);
    $.post(ajax, { action: 'ias_voice_rule', post: post, _ajax_nonce: nonce, rule: $btn.attr('data-rule') })
      .always(function (r) {
        r = r && r.responseJSON ? r.responseJSON : r;
        $btn.replaceWith($('<strong/>').text((r && r.data && r.data.message) || 'Not added.'));
      });
  });
  $('#ias-chat-new').on('click', function () {
    if (!window.confirm('Start the conversation over? Claude forgets it; the post above stays as it is.')) return;
    $.post(ajax, { action: 'ias_chat_new', post: post, _ajax_nonce: nonce }).done(function () {
      $log.find('.ias-msg').remove();
      $log.find('.ias-chat-empty').prop('hidden', false);
      $('#ias-chat-new').prop('hidden', true);
    });
  });
  relabel();
  scroll();
});
JS
  );
});
