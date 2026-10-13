<?php
/**
 * Intel Auctions Social — the brand voice (0.7.0).
 *
 * DJ, Oct 13, 2026: "keep a log of final posts to learn the brand voice over
 * time" — and then, that it should "write short rules and guides based on the
 * changes made over time" rather than pile up posts. So the house rules are
 * the long-term memory, and the log is a rolling window of raw material:
 * Claude reads it (on its own at most weekly, or when a person asks) and
 * suggests rules; once a person settles the suggestion those posts are
 * learned, and the log forgets posts after 120 days (starred ones stay;
 * Talk it over's messages after 45). Three parts:
 *
 *   The log      {prefix}ias_voice, one row per post: Claude's draft beside
 *                the final words, who changed or approved them, and what they
 *                asked for in Talk it over. A row is written when a person
 *                saves a post's text or approves it, and when the post goes
 *                out (dry run included) — every write recomputes the row from
 *                the post as it stands, so a rewrite or a later edit never
 *                leaves stale words behind.
 *   House rules  short style rules (option `ias_voice_notes`) that go into
 *                every draft and every Talk it over turn, after the facts,
 *                bidding and fair-housing rules and never in their place. A
 *                person writes them; Claude only suggests (Suggest updates,
 *                `social.voice`, one metered call when a person presses it),
 *                and a suggestion changes nothing until a person saves it.
 *   Examples     the few most recent posts a person wrote or approved go into
 *                each new draft as "how the team sounds" (Settings: examples
 *                per draft; 0 = none) — never posts about the same listing,
 *                never a held one, never one with a [VERIFY] marker.
 *
 * The voice is learned only from what a person wrote or said yes to: a post
 * that went out as Claude wrote it, unread, is in the log but teaches nothing
 * unless a person stars it.
 */

if (!defined('ABSPATH')) exit;

/* ---------------- House rules ---------------- */

/** The house voice rules, in order. */
if (!function_exists('ias_voice_notes')) {
  function ias_voice_notes(): array {
    $rules = get_option('ias_voice_notes', []);
    return array_values(array_filter(array_map('strval', is_array($rules) ? $rules : []), function ($r) { return trim($r) !== ''; }));
  }
}

/** Most rules kept, and the longest one. */
if (!function_exists('ias_voice_limits')) {
  function ias_voice_limits(): array {
    return ['rules' => 30, 'chars' => 200];
  }
}

/**
 * Clean a list of rules: trimmed, bullets dropped, no repeats, within the
 * limits — and never one that would teach a fair-housing phrase. Returns
 * [kept rules, refused [[rule, why]]]. Pure apart from the fair-housing list.
 */
if (!function_exists('ias_voice_clean_rules')) {
  function ias_voice_clean_rules(array $rules): array {
    $lim = ias_voice_limits();
    $kept = []; $seen = []; $refused = [];
    foreach ($rules as $r) {
      $r = trim((string) preg_replace('/^\s*(?:[-*•]+|\d+[.)])\s*/u', '', (string) $r));
      $r = trim((string) preg_replace('/\s+/u', ' ', $r));
      if ($r === '') continue;
      if (mb_strlen($r) > $lim['chars']) { $refused[] = [$r, 'longer than ' . $lim['chars'] . ' characters']; continue; }
      $key = mb_strtolower($r);
      if (isset($seen[$key])) continue;
      $hits = function_exists('ias_fair_housing_check') ? ias_fair_housing_check($r, function_exists('ias_setting') ? (string) ias_setting('fair_housing_extra', '') : '') : [];
      if ($hits) { $refused[] = [$r, 'it would put "' . $hits[0]['phrase'] . '" in posts — ' . $hits[0]['why']]; continue; }
      if (count($kept) >= $lim['rules']) { $refused[] = [$r, 'there are already ' . $lim['rules'] . ' rules']; continue; }
      $seen[$key] = true;
      $kept[] = $r;
    }
    return [$kept, $refused];
  }
}

/** Save the house rules (a person's list replaces the old one). Returns ['rules' => kept, 'refused' => [[rule, why]]]. */
if (!function_exists('ias_voice_notes_save')) {
  function ias_voice_notes_save(array $rules, string $actor): array {
    [$kept, $refused] = ias_voice_clean_rules($rules);
    $before = ias_voice_notes();
    update_option('ias_voice_notes', $kept, false);
    if ($kept !== $before) {
      $added = array_values(array_diff($kept, $before));
      $gone = array_values(array_diff($before, $kept));
      ias_log('voice', 0, ['summary' => 'House voice rules saved (' . count($kept) . ')' . ($added ? '; added: ' . implode(' | ', $added) : '') . ($gone ? '; taken out: ' . implode(' | ', $gone) : '')], $actor);
    }
    return ['rules' => $kept, 'refused' => $refused];
  }
}

/** Add one rule (from Talk it over's "Remember for every post"). Returns ['ok' => bool, 'message' => …]. */
if (!function_exists('ias_voice_rule_add')) {
  function ias_voice_rule_add(string $rule, string $actor): array {
    $rules = ias_voice_notes();
    [$one, $refused] = ias_voice_clean_rules([$rule]);
    if (!$one) return ['ok' => false, 'message' => $refused ? 'Not added: ' . $refused[0][1] . '.' : 'There was no rule to add.'];
    foreach ($rules as $r) if (mb_strtolower($r) === mb_strtolower($one[0])) return ['ok' => true, 'message' => 'Already a house rule.'];
    if (count($rules) >= ias_voice_limits()['rules']) return ['ok' => false, 'message' => 'There are already ' . ias_voice_limits()['rules'] . ' house rules — take one out on Brand voice first.'];
    $rules[] = $one[0];
    ias_voice_notes_save($rules, $actor);
    return ['ok' => true, 'message' => 'Added to the house voice — every new draft and chat follows it.'];
  }
}

/* ---------------- The log ---------------- */

/** A text as an example: no hashtag line, no web addresses, at most $max characters. */
if (!function_exists('ias_voice_clean_text')) {
  function ias_voice_clean_text(string $text, int $max = 900): string {
    $lines = preg_split('/\r\n|\r|\n/', trim($text));
    while ($lines && preg_match('/^\s*(#[\p{L}\p{N}_]+\s*)+$/u', (string) end($lines))) array_pop($lines);
    $text = trim((string) preg_replace('~\s*\(?(?:https?://|www\.)[^\s)]+\)?~i', '', implode("\n", $lines)));
    $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
  }
}

/** A row from the table, typed. */
if (!function_exists('ias_voice_decode')) {
  function ias_voice_decode(array $row): array {
    foreach (['id', 'post_id', 'listing_id', 'edited', 'touched', 'clean', 'learn', 'star'] as $k) $row[$k] = (int) ($row[$k] ?? 0);
    $asks = json_decode((string) ($row['asks'] ?? ''), true);
    $row['asks'] = is_array($asks) ? $asks : [];
    return $row;
  }
}

if (!function_exists('ias_voice_get')) {
  function ias_voice_get(int $post_id): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . ias_table('voice') . ' WHERE post_id = %d', $post_id), ARRAY_A);
    return $row ? ias_voice_decode($row) : null;
  }
}

/**
 * Write (or rewrite) a post's row from the post as it stands. $state 'kept'
 * (a person saved or approved it) or 'out' (it went out); a row that went out
 * stays out. A 'kept' write for a post no person has touched (a rewrite threw
 * their words away) removes its row; an 'out' write always keeps one — the log
 * holds every final post.
 */
if (!function_exists('ias_voice_record')) {
  function ias_voice_record(int $post_id, string $state = 'kept'): void {
    if (!ias_db_ready()) return;
    $p = ias_post_get($post_id);
    if (!$p || $p['type'] === 'human') return;
    $c = (array) $p['content'];
    $final = trim((string) ($c['text'] ?? ''));
    if ($final === '') return;
    global $wpdb;
    $table = ias_table('voice');
    $row = ias_voice_get($post_id);
    $claude = (string) ($c['claude_text'] ?? (($c['generated_by'] ?? '') === 'claude' ? $final : ''));
    $edited = ($c['generated_by'] ?? '') === 'edited' && ias_history_norm($claude) !== ias_history_norm($final);
    $approved = trim((string) ($p['approved_by'] ?? ''));
    $touched = $edited || $approved !== '';
    $state = ($row && $row['state'] === 'out') || $state === 'out' ? 'out' : 'kept';
    if ($state === 'kept' && !$touched) {
      if ($row) $wpdb->delete($table, ['post_id' => $post_id]);
      return;
    }
    $asks = function_exists('ias_chat_asks') ? ias_chat_asks($post_id, 20) : [];
    $now = ias_now_sql();
    $data = [
      'listing_id'  => (int) $p['listing_id'],
      'type'        => (string) $p['type'],
      'division'    => (string) ($c['voice'] ?? $p['division'] ?? ''),
      'claude_text' => $claude,
      'final_text'  => $final,
      'edited'      => $edited ? 1 : 0,
      'approved_by' => mb_substr($approved, 0, 100),
      'person'      => mb_substr($edited ? (string) ($p['flags']['edited_by'] ?? '') : $approved, 0, 100),
      'asks'        => wp_json_encode($asks),
      'touched'     => $touched ? 1 : 0,
      'clean'       => empty($p['flags']['holds']) && !ias_has_verify($c) ? 1 : 0,
      'state'       => $state,
      'updated_at'  => $now,
    ];
    if ($state === 'out' && (!$row || empty($row['out_at']))) $data['out_at'] = !empty($p['published_at']) ? (string) $p['published_at'] : (!empty($p['scheduled_at']) ? (string) $p['scheduled_at'] : $now);
    if ($row) {
      $wpdb->update($table, $data, ['post_id' => $post_id]);
    } else {
      $wpdb->insert($table, $data + ['post_id' => $post_id, 'created_at' => $now]);
    }
  }
}

/**
 * Rows of the log, newest first. $args: 'show' => all | edited | approved |
 * written (went out as Claude wrote it) | starred | off, 'type', 'limit', 'offset'.
 */
if (!function_exists('ias_voice_rows')) {
  function ias_voice_rows(array $args = []): array {
    if (!ias_db_ready()) return [];
    global $wpdb;
    $where = ['1=1']; $vals = [];
    switch ((string) ($args['show'] ?? 'all')) {
      case 'edited':   $where[] = 'edited = 1'; break;
      case 'approved': $where[] = "approved_by <> ''"; break;
      case 'written':  $where[] = 'touched = 0'; break;
      case 'starred':  $where[] = 'star = 1'; break;
      case 'off':      $where[] = 'learn = 0'; break;
    }
    if (!empty($args['type'])) { $where[] = 'type = %s'; $vals[] = (string) $args['type']; }
    $sql = 'SELECT * FROM ' . ias_table('voice') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d';
    $vals[] = max(1, (int) ($args['limit'] ?? 50));
    $vals[] = max(0, (int) ($args['offset'] ?? 0));
    return array_map('ias_voice_decode', (array) $wpdb->get_results($wpdb->prepare($sql, $vals), ARRAY_A));
  }
}

/** How many rows each filter shows. */
if (!function_exists('ias_voice_counts')) {
  function ias_voice_counts(): array {
    if (!ias_db_ready()) return [];
    global $wpdb;
    $r = (array) $wpdb->get_row('SELECT COUNT(*) AS all_rows, SUM(edited = 1) AS edited, SUM(approved_by <> \'\') AS approved, SUM(touched = 0) AS written, SUM(star = 1) AS starred, SUM(learn = 0) AS off_rows, SUM(learn = 1 AND clean = 1 AND (touched = 1 OR star = 1)) AS teaching FROM ' . ias_table('voice'), ARRAY_A);
    return ['all' => (int) ($r['all_rows'] ?? 0), 'edited' => (int) ($r['edited'] ?? 0), 'approved' => (int) ($r['approved'] ?? 0), 'written' => (int) ($r['written'] ?? 0), 'starred' => (int) ($r['starred'] ?? 0), 'off' => (int) ($r['off_rows'] ?? 0), 'teaching' => (int) ($r['teaching'] ?? 0)];
  }
}

/** Set a row's Learn from this / Star switch. */
if (!function_exists('ias_voice_flag')) {
  function ias_voice_flag(int $post_id, string $flag, bool $on): bool {
    if (!in_array($flag, ['learn', 'star'], true) || !ias_voice_get($post_id)) return false;
    global $wpdb;
    return $wpdb->update(ias_table('voice'), [$flag => $on ? 1 : 0], ['post_id' => $post_id]) !== false;
  }
}

/* ---------------- What goes into the prompts ---------------- */

/**
 * The posts that teach: a person wrote, approved or starred them, they were
 * clean, Learn from this is on, and they are about another listing. Starred
 * first, then this post type's, then the newest.
 */
if (!function_exists('ias_voice_examples')) {
  function ias_voice_examples(array $post, int $n): array {
    if ($n <= 0 || !ias_db_ready()) return [];
    global $wpdb;
    $listings = array_map('intval', (array) ($post['listing_ids'] ?? [])) ?: [(int) ($post['listing_id'] ?? 0)];
    $rows = array_map('ias_voice_decode', (array) $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . ias_table('voice') . ' WHERE learn = 1 AND clean = 1 AND (touched = 1 OR star = 1) AND post_id <> %d ORDER BY updated_at DESC, id DESC LIMIT 60',
      (int) ($post['id'] ?? 0)
    ), ARRAY_A));
    $rows = array_values(array_filter($rows, function ($r) use ($listings) { return !in_array((int) $r['listing_id'], $listings, true) && trim((string) $r['final_text']) !== ''; }));
    $type = (string) ($post['type'] ?? '');
    usort($rows, function ($a, $b) use ($type) {
      return [$b['star'], (int) ($b['type'] === $type), (string) $b['updated_at'], $b['id']] <=> [$a['star'], (int) ($a['type'] === $type), (string) $a['updated_at'], $a['id']];
    });
    return array_slice($rows, 0, $n);
  }
}

/** The house rules as a block for a system prompt ('' with none). */
if (!function_exists('ias_voice_rules_block')) {
  function ias_voice_rules_block(): string {
    $rules = ias_voice_notes();
    if (!$rules) return '';
    return "HOUSE VOICE — rules the Intel team set for every post. Follow them over the general VOICE guidance; they never override FACTS, BIDDING or FAIR HOUSING:\n- " . implode("\n- ", $rules);
  }
}

/** The examples as a block for a user prompt ('' with none): one shown as Claude's draft and the team's change. */
if (!function_exists('ias_voice_examples_block')) {
  function ias_voice_examples_block(array $post): string {
    $rows = ias_voice_examples($post, (int) ias_setting('voice_examples', 3));
    if (!$rows) return '';
    $out = "HOW THE TEAM SOUNDS — posts a person at Intel wrote or approved, for other properties. Match their plainness and rhythm. Never take a fact, name, place, number or phrase about a property from them, and do not copy their opening lines.\n";
    $pair = false;
    foreach ($rows as $i => $r) {
      $label = ias_post_type((string) $r['type'])['short'];
      if (!$pair && $r['edited'] && trim((string) $r['claude_text']) !== '') {
        $pair = true;
        $out .= "\nEXAMPLE " . ($i + 1) . ' (' . $label . ") — a draft like yours, and what the team changed it to:\nDRAFT:\n" . ias_voice_clean_text((string) $r['claude_text'], 700) . "\nCHANGED TO:\n" . ias_voice_clean_text((string) $r['final_text'], 700) . "\n";
      } else {
        $out .= "\nEXAMPLE " . ($i + 1) . ' (' . $label . "):\n" . ias_voice_clean_text((string) $r['final_text']) . "\n";
      }
    }
    return rtrim($out);
  }
}

/* ---------------- Word diff (what a person changed) ---------------- */

/**
 * Word-level difference between two texts: [[op, words], …] with op '=' kept,
 * '-' taken out, '+' put in. Line breaks count as words, so a moved paragraph
 * shows. Pure.
 */
if (!function_exists('ias_word_diff')) {
  function ias_word_diff(string $a, string $b): array {
    $split = function (string $s) { return preg_split('/(\n)|[ \t]+/u', trim(str_replace("\r\n", "\n", $s)), -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) ?: []; };
    $x = $split($a); $y = $split($b);
    // Trim the common ends first: most edits touch a few words in the middle.
    $pre = 0;
    while ($pre < count($x) && $pre < count($y) && $x[$pre] === $y[$pre]) $pre++;
    $suf = 0;
    while ($suf < count($x) - $pre && $suf < count($y) - $pre && $x[count($x) - 1 - $suf] === $y[count($y) - 1 - $suf]) $suf++;
    $mx = array_slice($x, $pre, count($x) - $pre - $suf);
    $my = array_slice($y, $pre, count($y) - $pre - $suf);
    $ops = [];
    if (count($mx) * count($my) > 250000) { // too long to compare word by word: all out, all in
      foreach ($mx as $w) $ops[] = ['-', $w];
      foreach ($my as $w) $ops[] = ['+', $w];
    } else {
      $n = count($mx); $m = count($my);
      $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
      for ($i = $n - 1; $i >= 0; $i--) for ($j = $m - 1; $j >= 0; $j--) $L[$i][$j] = $mx[$i] === $my[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
      $i = 0; $j = 0;
      while ($i < $n && $j < $m) {
        if ($mx[$i] === $my[$j]) { $ops[] = ['=', $mx[$i]]; $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $ops[] = ['-', $mx[$i]]; $i++; }
        else { $ops[] = ['+', $my[$j]]; $j++; }
      }
      for (; $i < $n; $i++) $ops[] = ['-', $mx[$i]];
      for (; $j < $m; $j++) $ops[] = ['+', $my[$j]];
    }
    $all = array_merge(array_map(function ($w) { return ['=', $w]; }, array_slice($x, 0, $pre)), $ops, array_map(function ($w) { return ['=', $w]; }, array_slice($x, count($x) - $suf)));
    // Runs of one op become one entry.
    $out = [];
    foreach ($all as [$op, $w]) {
      $k = count($out) - 1;
      if ($k >= 0 && $out[$k][0] === $op) $out[$k][1][] = $w; else $out[] = [$op, [$w]];
    }
    return array_map(function ($run) { return [$run[0], $run[1]]; }, $out);
  }
}

/** "9 words taken out, 14 put in" (or "as Claude wrote it"). */
if (!function_exists('ias_word_diff_summary')) {
  function ias_word_diff_summary(array $diff): string {
    $out = 0; $in = 0;
    foreach ($diff as [$op, $words]) {
      $words = array_filter($words, function ($w) { return $w !== "\n"; });
      if ($op === '-') $out += count($words);
      if ($op === '+') $in += count($words);
    }
    if (!$out && !$in) return 'as Claude wrote it';
    $part = function (int $n, string $what) { return $n . ' word' . ($n === 1 ? '' : 's') . ' ' . $what; };
    return implode(', ', array_filter([$out ? $part($out, 'taken out') : '', $in ? $part($in, 'put in') : '']));
  }
}

/** The diff as HTML: <del> and <ins>, line breaks kept. */
if (!function_exists('ias_word_diff_html')) {
  function ias_word_diff_html(array $diff): string {
    $html = '';
    foreach ($diff as [$op, $words]) {
      $text = esc_html((string) preg_replace('/ ?\n ?/', "\n", implode(' ', $words)));
      $text = str_replace("\n", '<br>', $text);
      $html .= ($op === '-' ? '<del>' . $text . '</del>' : ($op === '+' ? '<ins>' . $text . '</ins>' : $text)) . ' ';
    }
    return trim($html);
  }
}

/* ---------------- Suggest updates (Claude reads the log) ---------------- */

/** The last suggestion Claude made and has not been settled: ['rules', 'why', 'at', 'by', 'rows'] or null. */
if (!function_exists('ias_voice_suggestion')) {
  function ias_voice_suggestion(): ?array {
    $s = get_option('ias_voice_suggest', null);
    return is_array($s) && isset($s['rules']) ? $s : null;
  }
}

/** Rows a person touched since the rules were last learned from the log. */
if (!function_exists('ias_voice_new_since_learned')) {
  function ias_voice_new_since_learned(): int {
    if (!ias_db_ready()) return 0;
    global $wpdb;
    $since = (string) (get_option('ias_voice_learned', [])['at'] ?? '');
    $sql = 'SELECT COUNT(*) FROM ' . ias_table('voice') . ' WHERE learn = 1 AND clean = 1 AND (touched = 1 OR star = 1)';
    return (int) ($since !== '' ? $wpdb->get_var($wpdb->prepare($sql . ' AND updated_at > %s', $since)) : $wpdb->get_var($sql));
  }
}

/** [system, user] for Suggest updates. */
if (!function_exists('ias_voice_learn_prompt')) {
  function ias_voice_learn_prompt(array $rules, array $rows): array {
    $lim = ias_voice_limits();
    $system = ia_ai_brand_context() . "\n\n"
      . "You keep the HOUSE VOICE rules for Intel Auctions' Facebook posts: short, plain instructions that Claude follows whenever it writes or revises a post for Intel's page.\n"
      . "Read the team's recent posts: Claude's draft, what a person changed it to, and what they asked for. Work out what the team keeps changing — words they cut, how they open, length, tone, punctuation, how they ask people to call — and write it down as rules.\n"
      . "Each rule is one sentence, under " . (int) $lim['chars'] . " characters, about style, wording, structure or tone. Never a fact about one property, price, date, place, person or phone number. Never a rule that would break the facts-only rule, the bidding rule or fair housing.\n"
      . "Keep the rules the team already has unless their recent posts clearly go against one. Merge rules that say the same thing. At most " . (int) $lim['rules'] . " rules; fewer, sharper rules beat many.\n"
      . "OUTPUT: JSON only, no code fences: {\"rules\": [\"the whole list, in order of importance\"], \"why\": \"two or three sentences on what you changed and what in the posts showed it\"}";
    $user = 'THE HOUSE RULES NOW:' . ($rules ? "\n- " . implode("\n- ", $rules) : ' none yet') . "\n\nTHE TEAM'S RECENT POSTS (newest first):\n";
    foreach ($rows as $i => $r) {
      $user .= "\n--- POST " . ($i + 1) . ' (' . ias_post_type((string) $r['type'])['short'] . ($r['star'] ? ', starred as a good example' : '') . ")\n";
      if ($r['edited'] && trim((string) $r['claude_text']) !== '') {
        $user .= "CLAUDE WROTE:\n" . ias_voice_clean_text((string) $r['claude_text'], 900) . "\nTHE TEAM CHANGED IT TO:\n" . ias_voice_clean_text((string) $r['final_text'], 900) . "\n";
      } else {
        $user .= ($r['approved_by'] !== '' ? 'APPROVED AS WRITTEN' : 'KEPT AS WRITTEN') . ":\n" . ias_voice_clean_text((string) $r['final_text'], 900) . "\n";
      }
      if ($r['asks']) $user .= 'THEY ASKED: ' . implode(' | ', array_map(function ($a) { return mb_substr((string) $a, 0, 200); }, array_slice($r['asks'], 0, 8))) . "\n";
    }
    return [$system, $user];
  }
}

/**
 * Suggest updates: one Claude call (`social.voice`) over the newest rows that
 * teach. The answer is stored as a suggestion — the house rules do not change
 * until a person saves them. Not twice within 10 minutes with nothing new.
 * Returns ['ok' => bool, 'error' => …].
 */
if (!function_exists('ias_voice_learn')) {
  function ias_voice_learn(string $actor): array {
    $why = ias_ai_blocker();
    if ($why !== '') return ['ok' => false, 'error' => $why];
    $learned = (array) get_option('ias_voice_learned', []);
    $now = ias_now()->getTimestamp();
    if ((int) ($learned['ts'] ?? 0) > $now - 600 && ias_voice_new_since_learned() === 0) {
      return ['ok' => false, 'error' => 'Claude read the log less than 10 minutes ago and nothing new has come in since.'];
    }
    global $wpdb;
    $rows = array_map('ias_voice_decode', (array) $wpdb->get_results('SELECT * FROM ' . ias_table('voice') . ' WHERE learn = 1 AND clean = 1 AND (touched = 1 OR star = 1) ORDER BY star DESC, updated_at DESC, id DESC LIMIT 15', ARRAY_A));
    if (!$rows) return ['ok' => false, 'error' => 'Nothing to learn from yet: no post in the log was written, edited, approved or starred by a person.'];
    [$system, $user] = ias_voice_learn_prompt(ias_voice_notes(), $rows);
    $raw = ia_ai_generate($system, $user, 1500, 'social.voice');
    update_option('ias_voice_learned', ['ts' => $now, 'at' => ias_now_sql(), 'by' => $actor], false);
    if (is_wp_error($raw)) return ['ok' => false, 'error' => $raw->get_error_message()];
    $json = ias_json_object_from_text((string) $raw);
    if (!is_array($json) || !isset($json['rules']) || !is_array($json['rules'])) return ['ok' => false, 'error' => 'Claude did not answer with a list of rules. Try again later.'];
    [$kept, $refused] = ias_voice_clean_rules($json['rules']);
    update_option('ias_voice_suggest', [
      'rules' => $kept, 'refused' => $refused, 'why' => mb_substr(trim((string) ($json['why'] ?? '')), 0, 1200),
      'at' => ias_now_sql(), 'by' => $actor, 'rows' => count($rows),
    ], false);
    ias_log('voice', 0, ['summary' => 'Claude suggested house voice rules from ' . count($rows) . ' posts (not saved until a person saves them)'], $actor);
    return ['ok' => true, 'error' => ''];
  }
}

/** A JSON object out of Claude's answer (an object, never a list inside it). */
if (!function_exists('ias_json_object_from_text')) {
  function ias_json_object_from_text(string $text) {
    $text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)));
    $decoded = json_decode($text, true);
    if (is_array($decoded) && !ias_array_is_list($decoded)) return $decoded;
    $a = strpos($text, '{');
    $b = strrpos($text, '}');
    if ($a !== false && $b !== false && $b > $a) {
      $decoded = json_decode(substr($text, $a, $b - $a + 1), true);
      if (is_array($decoded)) return $decoded;
    }
    return null;
  }
}

/** array_is_list() before PHP 8.1. */
if (!function_exists('ias_array_is_list')) {
  function ias_array_is_list(array $a): bool {
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
  }
}

/* ---------------- Keeping it small ---------------- */

/** How long the log keeps a post (a starred one stays), the most posts it keeps, and how long chats are kept. Filterable. */
if (!function_exists('ias_voice_keep')) {
  function ias_voice_keep(): array {
    return [
      'days'      => max(30, (int) apply_filters('ias_voice_keep_days', 120)),
      'rows'      => max(50, (int) apply_filters('ias_voice_keep_rows', 400)),
      'chat_days' => max(7, (int) apply_filters('ias_chat_keep_days', 45)),
    ];
  }
}

/**
 * Once a day (the hourly job): the log forgets posts older than its window
 * and the oldest past its cap — never a starred one — and Talk it over's
 * messages go after their window (what was asked is already on the post's
 * log row). The house rules are what lasts. Returns ['voice' => n, 'chat' => n].
 */
if (!function_exists('ias_voice_prune')) {
  function ias_voice_prune(bool $force = false): array {
    $out = ['voice' => 0, 'chat' => 0];
    if (!ias_db_ready()) return $out;
    $today = ias_now()->format('Y-m-d');
    if (!$force && get_option('ias_voice_pruned') === $today) return $out;
    update_option('ias_voice_pruned', $today, false);
    global $wpdb;
    $k = ias_voice_keep();
    $voice = ias_table('voice');
    $out['voice'] = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$voice} WHERE star = 0 AND updated_at < %s", ias_now()->modify('-' . $k['days'] . ' days')->format('Y-m-d H:i:s')));
    $over = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$voice} WHERE star = 0") - $k['rows'];
    if ($over > 0) {
      $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$voice} WHERE star = 0 ORDER BY updated_at ASC, id ASC LIMIT %d", $over)));
      if ($ids) $out['voice'] += (int) $wpdb->query("DELETE FROM {$voice} WHERE id IN (" . implode(',', $ids) . ')');
    }
    $out['chat'] = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . ias_table('chat') . ' WHERE created_at < %s', ias_now()->modify('-' . $k['chat_days'] . ' days')->format('Y-m-d H:i:s')));
    return $out;
  }
}

/** How many new posts that teach set Claude reading the log on its own (Settings: Learn on its own). */
if (!function_exists('ias_voice_auto_after')) {
  function ias_voice_auto_after(): int {
    return max(1, (int) apply_filters('ias_voice_auto_after', 8));
  }
}

/**
 * The hourly job's turn: when enough new posts teach (8) and Claude last read
 * the log a week or more ago, it reads it again and suggests rules — one
 * metered call a week at most, never while the last suggestion waits for a
 * person, never without Claude. Returns a line for the run summary ('' = not now).
 */
if (!function_exists('ias_voice_auto_learn')) {
  function ias_voice_auto_learn(): string {
    if (!ias_setting('voice_auto', true) || ias_voice_suggestion() || ias_ai_blocker() !== '') return '';
    $learned = (array) get_option('ias_voice_learned', []);
    if ((int) ($learned['ts'] ?? 0) > ias_now()->getTimestamp() - 7 * DAY_IN_SECONDS) return '';
    $new = ias_voice_new_since_learned();
    if ($new < ias_voice_auto_after()) return '';
    $r = ias_voice_learn('system');
    return $r['ok'] ? 'Claude read ' . $new . ' new posts and suggested house rules (waiting on Brand voice)' : 'house rules not suggested: ' . $r['error'];
  }
}

add_filter('ias_job_run', function (array $result): array {
  $pruned = ias_voice_prune();
  if ($pruned['voice'] || $pruned['chat']) $result['parts'][] = 'brand voice: forgot ' . $pruned['voice'] . ' old posts and ' . $pruned['chat'] . ' old chat messages';
  $learned = ias_voice_auto_learn();
  if ($learned !== '') $result['parts'][] = $learned;
  return $result;
}, 40);

/** On the calendar, while it waits: Claude's suggested rules. */
add_action('ias_calendar_attention', function (): void {
  $s = ias_voice_suggestion();
  if (!$s) return;
  echo '<div class="ias-card"><h3>House rules: Claude suggested changes</h3><p>From ' . (int) $s['rows'] . ' post' . ((int) $s['rows'] === 1 ? '' : 's') . ' the team wrote or approved. Nothing changes until someone saves them. <a href="' . esc_url(ias_admin_url(IAS_PAGE_VOICE)) . '">Review them on Brand voice</a></p></div>';
}, 30);

/** The suggestion as a few lines for the Monday email ('' with none). */
if (!function_exists('ias_voice_review_html')) {
  function ias_voice_review_html(): string {
    $s = ias_voice_suggestion();
    if (!$s) return '';
    $rules = ias_voice_notes();
    $lines = array_merge(array_map(function ($r) { return '+ ' . $r; }, array_values(array_diff($s['rules'], $rules))), array_map(function ($r) { return '− ' . $r; }, array_values(array_diff($rules, $s['rules']))));
    return '<p>Claude read ' . (int) $s['rows'] . ' post' . ((int) $s['rows'] === 1 ? '' : 's') . ' the team wrote or approved and suggests these changes to the house rules. Nothing changes until someone saves them on Brand voice.</p>'
      . ($lines ? '<ul>' . implode('', array_map(function ($l) { return '<li>' . esc_html($l) . '</li>'; }, array_slice($lines, 0, 12))) . '</ul>' : '')
      . '<p><a href="' . esc_url(ias_admin_url(IAS_PAGE_VOICE)) . '">Review the house rules</a></p>';
  }
}

/* ---------------- Where the rows are written ---------------- */

/** A rewrite replaces the words a person kept: the row follows the post (and goes, if no person touched the new words). */
add_action('ias_post_generated', function (int $post_id): void {
  if (ias_voice_get($post_id)) ias_voice_record($post_id, 'kept');
}, 20);
