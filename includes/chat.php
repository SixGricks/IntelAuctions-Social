<?php
/**
 * Intel Auctions Social — Talk it over (0.7.0).
 *
 * DJ, Oct 13, 2026: "a little AI chatbot I can discuss the post with and have
 * it edit real time." A chat beside each post's text: a person says what they
 * don't like, Claude answers and rewrites the text in the editor there and
 * then, and the preview follows. Nothing is saved until the person presses
 * Save text — which goes through the same checks as any edit (bidding,
 * figures, fair housing, [VERIFY]), makes the post theirs (`edited_by`) and
 * needs a fresh yes on an approve-first post.
 *
 * Money: every message is one call through the toolkit's `ia_ai_generate()`
 * (feature `social.chat`, metered against the monthly cap), tried only when
 * Claude can be asked at all (`ias_ai_blocker()`), one at a time per post (a
 * lock), never twice for the same message about the same words (a double
 * click gets the stored answer), and at most `chat_turns` answers per post
 * (Settings; 0 = no chat). Claude works under the same rules as a draft
 * (`ias_prompt_rules()`) plus the house voice, and may use a fact the person
 * gives it — saying the listing lacks it — but never one of its own.
 *
 * The conversation is kept in {prefix}ias_chat for 45 days (voice.php
 * prunes it): what the person asked is the best evidence of the brand voice
 * there is, so the brand-voice log carries it, and a rewrite of the post
 * follows it. Only Claude's versions are kept as text; a person's message
 * keeps a fingerprint of the words it was about, for the repeat guard.
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

/** [system, user] for one turn. $history: the conversation so far (rows); $person: who is talking, by name. */
if (!function_exists('ias_chat_prompt')) {
  function ias_chat_prompt(array $post, array $facts_list, string $current, array $history, string $message, string $person): array {
    $house = function_exists('ias_voice_rules_block') ? ias_voice_rules_block() : '';
    $system = ia_ai_brand_context() . "\n\n"
      . "You are helping {$person} at Intel Auctions revise one Facebook post for Intel Auctions' own page before it goes out. {$person} tells you what to change; you change the post and say in a sentence or two what you did. When they only ask a question, answer it plainly and leave the post as it is.\n"
      . ias_prompt_rules()
      . ($house !== '' ? $house . "\n" : '')
      . "KEEP: the hashtag line at the end and any web address exactly as they are, unless {$person} asks to change them. It stays a Facebook post: plain text, no headings, no markdown, no bold.\n"
      . "FACTS FROM {$person}: {$person} knows the sale. When they give you a fact the FACTS block does not have (a start time, a feature), use it as they gave it, and say in your reply that the listing does not say it, so the listing can be fixed too. Never add a fact of your own. When they ask for something the FACTS contradict (another price, date or way of bidding), do not write it — say what the facts say.\n"
      . "FAIR HOUSING applies to whatever {$person} asks for: if a request would break it, say so in a sentence and offer words that describe the property instead.\n"
      . "RULE: when {$person} states a preference that should hold for every post — wording, tone, length, structure, not something about this property — also give it as one short rule (for example \"Never use the word nestled.\"). Otherwise rule is null.\n"
      . "OUTPUT: JSON only, no code fences: {\"reply\": \"one to three short sentences to {$person}\", \"text\": \"the whole revised post, or null if you did not change it\", \"rule\": null}";

    $facts = count($facts_list) > 1 ? array_map('ias_facts_for_prompt', $facts_list) : ias_facts_for_prompt($facts_list[0]);
    $user = 'POST TYPE: ' . ias_type_brief($post, $facts_list) . "\n\n"
      . "FACTS:\n" . wp_json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n"
      . "THE POST NOW (as {$person} sees it, with any edits of their own):\n<<<\n" . $current . "\n>>>\n\n";
    $lines = [];
    foreach ($history as $row) {
      if ($row['who'] === 'person') $lines[] = $person . ': ' . mb_substr($row['message'], 0, 600);
      elseif ($row['who'] === 'claude') $lines[] = 'You: ' . mb_substr($row['message'], 0, 600) . ($row['proposal'] !== '' ? ' [you changed the text]' : '');
    }
    if ($lines) $user .= "THE CONVERSATION SO FAR:\n" . implode("\n", $lines) . "\n\n";
    $user .= strtoupper($person) . ' SAYS: ' . $message;
    return [$system, $user];
  }
}

/** The answer already given to this same message about these same words, a moment ago (a double click, a resend) — or null. */
if (!function_exists('ias_chat_repeat')) {
  function ias_chat_repeat(int $post_id, string $message, string $current): ?array {
    global $wpdb;
    $rows = array_map('ias_chat_decode', (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . ias_table('chat') . ' WHERE post_id = %d ORDER BY id DESC LIMIT 2', $post_id), ARRAY_A));
    if (count($rows) < 2 || $rows[0]['who'] !== 'claude' || $rows[1]['who'] !== 'person') return null;
    if ($rows[1]['message'] !== $message || (string) ($rows[1]['meta']['was'] ?? '') !== md5($current)) return null;
    if (strtotime((string) $rows[0]['created_at']) < strtotime(ias_now_sql()) - 120) return null;
    $m = $rows[0]['meta'];
    return ['ok' => true, 'repeat' => true, 'reply' => $rows[0]['message'], 'text' => $rows[0]['proposal'] !== '' ? $rows[0]['proposal'] : null,
      'holds' => (array) ($m['holds'] ?? []), 'fixes' => (array) ($m['fixes'] ?? []), 'rule' => ($m['rule'] ?? '') !== '' ? (string) $m['rule'] : null,
      'used' => ias_chat_turns_used($post_id), 'max' => (int) ias_setting('chat_turns', 30), 'id' => $rows[0]['id']];
  }
}

/**
 * One turn: the person's message about the post's words as they stand on
 * their screen ($current, unsaved edits and all). Returns ['ok' => true,
 * 'reply', 'text' (the new words, or null), 'holds' (what would stop it on
 * save), 'fixes', 'rule' (a house rule to offer, or null), 'used', 'max'] or
 * ['ok' => false, 'error', 'code']. Never saves the post.
 */
if (!function_exists('ias_chat_send')) {
  function ias_chat_send(int $post_id, string $message, string $current, string $actor): array {
    $fail = function (string $error, int $code = 400) { return ['ok' => false, 'error' => $error, 'code' => $code]; };
    $p = ias_post_get($post_id);
    if (!$p) return $fail('No such post.', 404);
    if (!ias_post_editable($p) || trim((string) ($p['content']['text'] ?? '')) === '') return $fail('This post cannot be changed here now.', 409);
    $max = (int) ias_setting('chat_turns', 30);
    if ($max <= 0) return $fail('Talk it over is switched off (Settings → Talk it over).', 403);
    $message = trim(str_replace("\r\n", "\n", $message));
    if ($message === '') return $fail('Say what should change.');
    if (mb_strlen($message) > 1500) return $fail('Keep a message under 1,500 characters.');
    $current = trim(str_replace("\r\n", "\n", $current));
    if ($current === '') $current = (string) $p['content']['text'];
    if (mb_strlen($current) > 8000) return $fail('The text is too long to talk over.');

    $again = ias_chat_repeat($post_id, $message, $current);
    if ($again) return $again;
    $used = ias_chat_turns_used($post_id);
    if ($used >= $max) return $fail('This post has had its ' . $max . ' answers from Claude (Settings → Talk it over). Edit the text yourself, or press Rewrite for a fresh draft.', 429);
    $why = ias_ai_blocker();
    if ($why !== '') return $fail($why, 503);
    $facts_list = ias_post_facts($p);
    if (!$facts_list) return $fail('The listing this post was for is gone.', 409);
    if (!ias_chat_lock($post_id)) return $fail('Claude is still answering the last message about this post.', 409);

    try {
      $person = ias_actor_name($actor);
      [$system, $user] = ias_chat_prompt($p, $facts_list, $current, ias_chat_rows($post_id, true, 12), $message, $person === 'the plugin' ? 'the team' : $person);
      $raw = ia_ai_generate($system, $user, 1500, 'social.chat');
      if (is_wp_error($raw)) return $fail('Claude could not answer: ' . $raw->get_error_message(), 502);

      $json = function_exists('ias_json_object_from_text') ? ias_json_object_from_text((string) $raw) : null;
      if (is_array($json) && array_key_exists('reply', $json)) {
        $reply = trim((string) $json['reply']);
        $text = isset($json['text']) && is_string($json['text']) ? trim(str_replace("\r\n", "\n", $json['text'])) : '';
        $rule = isset($json['rule']) && is_string($json['rule']) ? trim($json['rule']) : '';
      } else { // not the JSON asked for: show what it said, change nothing
        $reply = trim((string) $raw); $text = ''; $rule = '';
      }
      if ($text !== '' && ias_history_norm($text) === ias_history_norm($current)) $text = '';
      if ($reply === '') $reply = $text !== '' ? 'Here it is.' : 'I left the post as it is.';
      $reply = mb_substr($reply, 0, 1500);

      $holds = []; $fixes = [];
      if ($text !== '') {
        $check = ias_recheck_post($p, $text);
        $holds = (array) $check['holds'];
        $fixes = (array) $check['fixes'];
      }
      if ($rule !== '' && strtolower($rule) !== 'null' && function_exists('ias_voice_clean_rules')) {
        [$ok] = ias_voice_clean_rules([$rule]);
        $rule = (string) ($ok[0] ?? '');
        foreach (ias_voice_notes() as $have) if (mb_strtolower($have) === mb_strtolower($rule)) $rule = '';
      } else {
        $rule = '';
      }

      ias_chat_add($post_id, 'person', $actor, $message, '', ['was' => md5($current)]); // a fingerprint, not a second copy of the words
      $id = ias_chat_add($post_id, 'claude', 'claude', $reply, $text, ['holds' => $holds, 'fixes' => $fixes, 'rule' => $rule]);
      ias_log('chat', (int) $p['listing_id'], ['post' => $post_id, 'type' => $p['type'],
        'summary' => 'Talked it over: "' . mb_substr($message, 0, 140) . '" — ' . ($text !== '' ? 'Claude changed the text (not saved)' : 'Claude answered')], $actor);
      return ['ok' => true, 'reply' => $reply, 'text' => $text !== '' ? $text : null, 'holds' => $holds, 'fixes' => $fixes,
        'rule' => $rule !== '' ? $rule : null, 'used' => $used + 1, 'max' => $max, 'id' => $id];
    } finally {
      ias_chat_unlock($post_id);
    }
  }
}

/** The preview column for a post with other words (as typed, not saved). */
if (!function_exists('ias_preview_html')) {
  function ias_preview_html(array $p, string $text, ?string $comment = null): string {
    $p['content']['text'] = $text;
    if ($comment !== null && !empty($p['content']['first_comment'])) $p['content']['first_comment'] = $comment;
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

add_action('wp_ajax_ias_chat', function (): void {
  $p = ias_ajax_post();
  $r = ias_chat_send((int) $p['id'], (string) wp_unslash($_POST['message'] ?? ''), (string) wp_unslash($_POST['text'] ?? ''), ias_actor());
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
  $text = trim(str_replace("\r\n", "\n", (string) wp_unslash($_POST['text'] ?? '')));
  if ($text === '' || mb_strlen($text) > 8000) wp_send_json_error(['message' => 'No text to preview.'], 400);
  $comment = isset($_POST['comment']) ? trim(str_replace("\r\n", "\n", (string) wp_unslash($_POST['comment']))) : null;
  wp_send_json_success(['html' => ias_preview_html($p, $text, $comment)]);
});

add_action('wp_ajax_ias_voice_rule', function (): void {
  ias_ajax_post();
  $r = ias_voice_rule_add((string) wp_unslash($_POST['rule'] ?? ''), ias_actor());
  if (!$r['ok']) wp_send_json_error(['message' => $r['message']], 400);
  wp_send_json_success(['message' => $r['message']]);
});

/* ---------------- The panel ---------------- */

/** One message in the panel (the same markup the script builds). */
if (!function_exists('ias_chat_bubble')) {
  function ias_chat_bubble(array $row, bool $last_change): string {
    if ($row['who'] === 'person') {
      return '<div class="ias-msg me"><b>' . esc_html(ias_actor_name((string) $row['actor'])) . '</b><div class="ias-msg-t">' . esc_html($row['message']) . '</div></div>';
    }
    $m = $row['meta'];
    $out = '<div class="ias-msg ai"><b>Claude</b><div class="ias-msg-t">' . esc_html($row['message']) . '</div>';
    if ($row['proposal'] !== '') {
      $out .= '<div class="ias-msg-acts"><span class="ias-msg-note">Changed the text' . ($last_change ? '' : ' (an earlier version)') . '</span> <button type="button" class="button-link ias-use" data-text="' . esc_attr($row['proposal']) . '">Use this version</button></div>';
      if (!empty($m['holds'])) $out .= '<div class="ias-msg-warn">Saved like this, it would stop for a decision: ' . esc_html(implode(' ', (array) $m['holds'])) . '</div>';
    }
    if (!empty($m['rule']) && !in_array(mb_strtolower((string) $m['rule']), array_map('mb_strtolower', ias_voice_notes()), true)) {
      $out .= '<div class="ias-msg-rule">Remember for every post? <em>' . esc_html((string) $m['rule']) . '</em> <button type="button" class="button button-small ias-rule-add" data-rule="' . esc_attr((string) $m['rule']) . '">Add to the house voice</button></div>';
    }
    return $out . '</div>';
  }
}

/** Under the Edit card: the chat. */
add_action('ias_post_view_after_edit', function (array $p): void {
  $max = (int) ias_setting('chat_turns', 30);
  if ($max <= 0 || !ias_post_editable($p) || trim((string) ($p['content']['text'] ?? '')) === '') return;
  $why = ias_ai_blocker();
  $used = ias_chat_turns_used((int) $p['id']);
  $rows = ias_chat_rows((int) $p['id'], true, 40);
  $last_change = 0;
  foreach ($rows as $r) if ($r['who'] === 'claude' && $r['proposal'] !== '') $last_change = $r['id'];
  $me = ias_actor_name(ias_actor());
  $off = $why !== '' || $used >= $max;

  echo '<div class="ias-card ias-chat" id="ias-chat" data-me="' . esc_attr($me) . '" data-max="' . (int) $max . '">';
  echo '<h2>Talk it over <span class="description">with Claude</span></h2>';
  echo '<div class="ias-chat-log" id="ias-chat-log" aria-live="polite">';
  echo '<p class="description ias-chat-empty"' . ($rows ? ' hidden' : '') . '>Say what you don\'t like — "shorter", "less salesy", "lead with the acreage", "the auction starts at 10" — and Claude rewrites the text above for you to check and save. It keeps to the listing\'s facts and the house voice.</p>';
  foreach ($rows as $r) echo ias_chat_bubble($r, $r['id'] === $last_change);
  echo '</div>';
  if ($why !== '') {
    echo '<p class="description">' . esc_html($why) . '</p>';
  } elseif ($used >= $max) {
    echo '<p class="description">This post has had its ' . (int) $max . ' answers from Claude. Edit the text yourself, or raise the number in Settings → Talk it over.</p>';
  }
  echo '<div class="ias-chat-quick">';
  foreach (['Shorter', 'Plainer — less salesy', 'Warmer', 'Lead with the auction date', 'Fix grammar only'] as $q) echo '<button type="button" class="ias-chat-chip"' . ($off ? ' disabled' : '') . '>' . esc_html($q) . '</button>';
  echo '</div>';
  echo '<div class="ias-chat-row"><label for="ias-chat-in" class="screen-reader-text">Your message to Claude</label><textarea id="ias-chat-in" rows="2" maxlength="1500" placeholder="What should change?"' . ($off ? ' disabled' : '') . '></textarea>'
    . '<button type="button" class="button" id="ias-chat-send"' . ($off ? ' disabled' : '') . '>Send</button></div>';
  echo '<div class="ias-foot"><div class="ias-actions"><button type="button" class="button-link" id="ias-chat-undo" hidden>↶ Undo the last change</button></div>'
    . '<div class="ias-actions"><span class="description" id="ias-chat-used">' . (int) $used . ' of ' . (int) $max . ' answers used</span>'
    . '<button type="button" class="button-link" id="ias-chat-new"' . ($rows ? '' : ' hidden') . '>Start over</button></div></div>';
  echo '<p class="description">Nothing changes until you press <strong>Save text</strong>. Each message is one Claude call, counted against the monthly cap. What you ask for is kept with the post for <a href="' . esc_url(ias_admin_url(IAS_PAGE_VOICE)) . '">Brand voice</a>.</p>';
  echo '</div>';
});

/** The panel's script (with the picture editor's, on a post's view). */
add_action('admin_enqueue_scripts', function (): void {
  if (($_GET['page'] ?? '') !== IAS_PAGE_CALENDAR || empty($_GET['post']) || !current_user_can('manage_options')) return;
  wp_enqueue_script('jquery-ui-sortable'); // the picture editor's handle (admin-post.php): this script prints after it
  wp_add_inline_script('jquery-ui-sortable', <<<'JS'
jQuery(function ($) {
  var $chat = $('#ias-chat'), $text = $('#ias-text'), $comment = $('#ias-comment');
  if (!$text.length) return;
  var ajax = $text.data('ajax') || window.ajaxurl, nonce = $text.data('nonce'), post = $text.data('post'); // on the text box: the preview follows it with or without the chat
  var timer = null, seq = 0;

  // The preview follows the words as they are typed (or as Claude changes them), unsaved.
  function preview() {
    if (!nonce) return;
    var mine = ++seq, $col = $('.ias-post-preview');
    $col.addClass('is-updating');
    $.post(ajax, { action: 'ias_preview', post: post, _ajax_nonce: nonce, text: $text.val(), comment: $comment.length ? $comment.val() : '' })
      .done(function (r) {
        if (mine !== seq || !r || !r.success) return;
        var net = $col.find('.ias-net.is-on').data('net');
        $col.html(r.data.html);
        if (net) $col.find('.ias-net[data-net="' + net + '"]').trigger('click');
      })
      .always(function () { if (mine === seq) $col.removeClass('is-updating'); });
  }
  $text.add($comment).on('input', function () { clearTimeout(timer); timer = setTimeout(preview, 700); });

  if (!$chat.length) return;
  var $log = $('#ias-chat-log'), $in = $('#ias-chat-in'), $send = $('#ias-chat-send'), $undo = $('#ias-chat-undo'), max = $chat.data('max');
  var undo = [], busy = false;

  function scroll() { $log.scrollTop($log[0].scrollHeight); }
  function bubble(cls, who, words) {
    $log.find('.ias-chat-empty').prop('hidden', true);
    $('#ias-chat-new').prop('hidden', false);
    var $b = $('<div class="ias-msg"/>').addClass(cls).append($('<b/>').text(who)).append($('<div class="ias-msg-t"/>').text(words));
    $log.append($b); scroll();
    return $b;
  }
  function setText(t) {
    undo.push($text.val());
    $text.val(t).trigger('input');
    $text.addClass('ias-flash'); setTimeout(function () { $text.removeClass('ias-flash'); }, 1200);
    $undo.prop('hidden', false);
    clearTimeout(timer); preview();
  }
  function lock(on) { busy = on; $send.prop('disabled', on); $chat.find('.ias-chat-chip').prop('disabled', on); }

  function send(msg) {
    msg = $.trim(msg || '');
    if (busy || !msg) return;
    lock(true);
    bubble('me', $chat.data('me'), msg);
    $in.val('');
    var $wait = bubble('ai is-wait', 'Claude', 'Thinking…');
    $.post(ajax, { action: 'ias_chat', post: post, _ajax_nonce: nonce, message: msg, text: $text.val() })
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
    if (d.text !== null && d.text !== undefined) {
      setText(d.text);
      $log.find('.ias-msg-note').text('Changed the text (an earlier version)');
      $b.append($('<div class="ias-msg-acts"/>').append($('<span class="ias-msg-note"/>').text('Changed the text above — not saved yet')).append(' ')
        .append($('<button type="button" class="button-link ias-use"/>').attr('data-text', d.text).text('Use this version')));
      if (d.holds && d.holds.length) $b.append($('<div class="ias-msg-warn"/>').text('Saved like this, it would stop for a decision: ' + d.holds.join(' ')));
    }
    if (d.rule) {
      $b.append($('<div class="ias-msg-rule"/>').append('Remember for every post? ').append($('<em/>').text(d.rule)).append(' ')
        .append($('<button type="button" class="button button-small ias-rule-add"/>').attr('data-rule', d.rule).text('Add to the house voice')));
    }
    $('#ias-chat-used').text(d.used + ' of ' + d.max + ' answers used');
    if (d.used >= d.max) { $in.prop('disabled', true); $send.prop('disabled', true); $chat.find('.ias-chat-chip').prop('disabled', true); }
    scroll();
  }

  $send.on('click', function () { send($in.val()); });
  $in.on('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send($in.val()); } });
  $chat.on('click', '.ias-chat-chip', function () { send($(this).text()); });
  $log.on('click', '.ias-use', function () { setText($(this).attr('data-text')); });
  $undo.on('click', function () {
    if (!undo.length) return;
    $text.val(undo.pop()).trigger('input');
    clearTimeout(timer); preview();
    $undo.prop('hidden', !undo.length);
  });
  $log.on('click', '.ias-rule-add', function () {
    var $btn = $(this).prop('disabled', true);
    $.post(ajax, { action: 'ias_voice_rule', post: post, _ajax_nonce: nonce, rule: $btn.attr('data-rule') })
      .always(function (r) {
        r = r && r.responseJSON ? r.responseJSON : r;
        $btn.replaceWith($('<strong/>').text((r && r.data && r.data.message) || 'Not added.'));
      });
  });
  $('#ias-chat-new').on('click', function () {
    if (!window.confirm('Start the conversation over? Claude forgets it; the text above stays as it is.')) return;
    $.post(ajax, { action: 'ias_chat_new', post: post, _ajax_nonce: nonce }).done(function () {
      $log.find('.ias-msg').remove();
      $log.find('.ias-chat-empty').prop('hidden', false);
      $('#ias-chat-new').prop('hidden', true);
    });
  });
  scroll();
});
JS
  );
});
