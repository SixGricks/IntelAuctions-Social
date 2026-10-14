<?php
/**
 * Intel Auctions Social — Auctions → Social Calendar → Brand voice (0.7.0).
 *
 * The house rules (a person's to write; Claude only suggests) — the long-term
 * memory — and the log of final posts they are learned from (a rolling
 * window), with Claude's draft beside the final words and the switches that
 * decide which posts teach: Learn from this, and a star for a post that is a
 * good example (a starred post is never forgotten). See voice.php.
 */

if (!defined('ABSPATH')) exit;

/* ---------------- Actions (POST → redirect → GET) ---------------- */

if (!function_exists('ias_voice_handle_post')) {
  function ias_voice_handle_post(): void {
    if (!isset($_POST['ias_voice_action']) || !current_user_can('manage_options')) return;
    check_admin_referer('ias_voice');
    $action = sanitize_key(wp_unslash($_POST['ias_voice_action']));
    $actor = ias_actor();
    $back = isset($_POST['ias_back']) ? esc_url_raw(wp_unslash($_POST['ias_back'])) : '';
    $flash = function (string $html, string $level = 'success') { ia_flash_set('<div class="notice notice-' . $level . '"><p>' . $html . '</p></div>'); };
    $refused = function (array $r) {
      return $r ? ' Left out: ' . esc_html(implode('; ', array_map(function ($x) { return '"' . $x[0] . '": ' . $x[1]; }, $r))) . '.' : '';
    };

    switch ($action) {
      case 'rules':
      case 'use_suggestion':
        $lines = preg_split('/\r\n|\r|\n/', (string) wp_unslash($_POST['ias_rules'] ?? ''));
        $r = ias_voice_notes_save(array_map('sanitize_text_field', $lines), $actor);
        if ($action === 'use_suggestion') delete_option('ias_voice_suggest');
        $flash(esc_html(count($r['rules']) . ' house rule' . (count($r['rules']) === 1 ? '' : 's') . ' saved — every new draft and chat follows ' . (count($r['rules']) === 1 ? 'it' : 'them') . '.') . $refused($r['refused']), $r['refused'] ? 'warning' : 'success');
        break;

      case 'discard_suggestion':
        delete_option('ias_voice_suggest');
        ias_log('voice', 0, ['summary' => 'Claude\'s suggested house rules discarded'], $actor);
        $flash('Suggestion discarded. The house rules are as they were.');
        break;

      case 'learn':
        $r = ias_voice_learn($actor);
        $r['ok'] ? $flash('Claude read the log and suggested house rules — below. Nothing changes until you save them.') : $flash(esc_html($r['error']), 'warning');
        break;

      case 'flag':
        $post_id = (int) ($_POST['ias_post'] ?? 0);
        $flag = sanitize_key(wp_unslash($_POST['ias_flag'] ?? ''));
        $on = !empty($_POST['ias_on']);
        if (ias_voice_flag($post_id, $flag, $on)) {
          ias_log('voice', 0, ['post' => $post_id, 'summary' => ($flag === 'star' ? ($on ? 'Starred as a good example' : 'Star taken off') : ($on ? 'Learned from again' : 'No longer learned from'))], $actor);
        }
        break;
    }
    wp_safe_redirect($back !== '' ? $back : ias_admin_url(IAS_PAGE_VOICE));
    exit;
  }
}
add_action('admin_init', 'ias_voice_handle_post');

/* ---------------- Render ---------------- */

/** "Adds 1 rule and takes out 3 of your 4." */
if (!function_exists('ias_voice_change_count')) {
  function ias_voice_change_count(int $added, int $gone, int $have): string {
    $bits = [];
    if ($added) $bits[] = 'adds ' . $added . ' rule' . ($added === 1 ? '' : 's');
    if ($gone) $bits[] = 'takes out ' . $gone . ' of your ' . $have;
    return $bits ? ucfirst(implode(' and ', $bits)) . '.' : 'Only rewords your rules.';
  }
}

/** Who a row's words belong to, in words. */
if (!function_exists('ias_voice_who')) {
  function ias_voice_who(array $r): string {
    $name = function (string $who) { return strpos($who, '@') !== false ? ias_person_name($who) : ias_actor_name($who); };
    if ($r['edited']) return 'Edited by ' . $name((string) $r['person']) . ($r['approved_by'] !== '' && $r['approved_by'] !== $r['person'] ? ', approved by ' . $name((string) $r['approved_by']) : '');
    if ($r['approved_by'] !== '') return 'Approved as written by ' . $name((string) $r['approved_by']);
    return 'As Claude wrote it';
  }
}

if (!function_exists('ias_voice_render')) {
  function ias_voice_render(): void {
    if (!current_user_can('manage_options')) return;
    ias_admin_styles();
    ia_ui_header('Brand voice', 'How Claude learns to sound like Intel. The house rules go into every draft and every Talk it over chat; the posts a person wrote, approved or starred are shown to Claude as examples of how the team sounds.');
    ias_header_end();
    echo ias_switches_strip();
    if (!ias_db_ready()) {
      echo '<div class="notice notice-warning"><p>The plugin\'s tables are not set up yet. Reload this page; if this stays, deactivate and activate the plugin.</p></div></div>';
      return;
    }
    $nonce = wp_nonce_field('ias_voice', '_wpnonce', true, false);
    $show = isset($_GET['show']) ? sanitize_key(wp_unslash($_GET['show'])) : 'all';
    if (!in_array($show, ['all', 'edited', 'approved', 'written', 'starred', 'off'], true)) $show = 'all';
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $here = ias_admin_url(IAS_PAGE_VOICE, array_filter(['show' => $show !== 'all' ? $show : '', 'paged' => $paged > 1 ? $paged : '']));
    $back = '<input type="hidden" name="ias_back" value="' . esc_attr($here) . '">';
    $rules = ias_voice_notes();
    $sugg = ias_voice_suggestion();
    $counts = ias_voice_counts();
    $lim = ias_voice_limits();

    echo '<div class="ias-cols"><div>';

    // Claude's suggestion, when there is one: nothing changes until a person saves it.
    if ($sugg) {
      $added = array_values(array_diff($sugg['rules'], $rules));
      $gone = array_values(array_diff($rules, $sugg['rules']));
      echo '<div class="ias-card ias-suggest"><h2>Claude\'s suggestion <span class="description">from ' . (int) $sugg['rows'] . ' post' . ((int) $sugg['rows'] === 1 ? '' : 's') . ', ' . esc_html(date_i18n('M j, g:i a', strtotime((string) $sugg['at']))) . '</span></h2>';
      if ((string) $sugg['why'] !== '') echo '<p>' . esc_html((string) $sugg['why']) . '</p>';
      if ($added || $gone) {
        echo '<ul class="ias-rule-diff">';
        foreach ($added as $a) echo '<li><ins>+ ' . esc_html($a) . '</ins></li>';
        foreach ($gone as $g) echo '<li><del>− ' . esc_html($g) . '</del></li>';
        echo '</ul>';
      } else {
        echo '<p class="description">The same rules you have now.</p>';
      }
      if (!empty($sugg['refused'])) echo '<p class="description">Left out of the suggestion: ' . esc_html(implode('; ', array_map(function ($x) { return '"' . $x[0] . '": ' . $x[1]; }, (array) $sugg['refused']))) . '.</p>';
      echo '<form method="post">' . $nonce . $back . '<input type="hidden" name="ias_voice_action" value="use_suggestion">'
        . '<p><label for="ias-sugg"><strong>The suggested rules</strong> — change them as you like before you save</label><textarea id="ias-sugg" name="ias_rules" rows="' . max(4, min(16, count($sugg['rules']) + 1)) . '" class="large-text">' . esc_textarea(implode("\n", $sugg['rules'])) . '</textarea></p>'
        . '<div class="ias-foot"><div class="ias-actions"><button class="button button-primary">' . esc_html($gone ? 'Replace my rules with these' : 'Use these rules') . '</button> <span class="description">' . esc_html(ias_voice_change_count(count($added), count($gone), count($rules))) . '</span></div></div></form>'
        . '<form method="post" class="ias-act">' . $nonce . $back . '<input type="hidden" name="ias_voice_action" value="discard_suggestion"><button class="button-link">Discard the suggestion</button></form></div>';
    }

    echo '<div class="ias-card"><h2>House rules <span class="description">(' . count($rules) . ' of ' . (int) $lim['rules'] . ')</span></h2>';
    echo '<form method="post">' . $nonce . $back . '<input type="hidden" name="ias_voice_action" value="rules">'
      . '<p><label for="ias-rules" class="screen-reader-text">House rules, one per line</label><textarea id="ias-rules" name="ias_rules" rows="' . max(6, min(18, count($rules) + 2)) . '" class="large-text" placeholder="Never use the word &quot;nestled&quot;.&#10;Open with the place, not with us.&#10;Say &quot;give us a call&quot;, not &quot;contact us today&quot;.">' . esc_textarea(implode("\n", $rules)) . '</textarea></p>'
      . '<p class="description">One rule per line, about style — wording, tone, length, how a post opens or asks people to call — never a fact about one property. Claude reads them after the facts, bidding and fair-housing rules, which they never overrule. A rule that would put a fair-housing phrase in a post is not kept.</p>'
      . '<div class="ias-foot"><div class="ias-actions"><button class="button' . ($sugg ? '' : ' button-primary') . '">Save rules</button></div></div></form></div>';

    // The log.
    $filters = ['all' => 'All', 'edited' => 'Edited by a person', 'approved' => 'Approved', 'written' => 'Went out as Claude wrote it', 'starred' => 'Starred', 'off' => 'Not learned from'];
    echo '<div class="ias-card"><h2>The log of final posts</h2><p class="description">Every post a person saved or approved, and every post that went out, for ' . (int) ias_voice_keep()['days'] . ' days — a starred one for good. The ones a person wrote, approved or starred teach Claude; a post that went out unread does not, unless you star it.</p><p class="ias-filters">';
    $links = [];
    foreach ($filters as $k => $label) {
      $n = (int) ($counts[$k] ?? 0);
      $links[] = $k === $show ? '<strong>' . esc_html($label) . ' (' . $n . ')</strong>' : '<a href="' . esc_url(ias_admin_url(IAS_PAGE_VOICE, $k === 'all' ? [] : ['show' => $k])) . '">' . esc_html($label) . '</a> <span class="description">(' . $n . ')</span>';
    }
    echo implode(' · ', $links) . '</p>';
    $per = 30;
    $rows = ias_voice_rows(['show' => $show, 'limit' => $per + 1, 'offset' => ($paged - 1) * $per]);
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    if (!$rows) {
      echo '<p class="description">' . ($show === 'all' ? 'Nothing yet. A post comes into the log when a person saves its text or approves it, and when it goes out.' : 'No posts here.') . '</p>';
    } else {
      echo '<div class="ias-summary-scroll"><table class="widefat striped ias-voice-table"><thead><tr><th>Post</th><th>Whose words</th><th>What changed</th><th style="width:150px">Teaches</th></tr></thead><tbody>';
      foreach ($rows as $r) {
        $p = ias_post_get((int) $r['post_id']);
        $subject = $p ? ias_post_subject($p) : html_entity_decode(get_the_title((int) $r['listing_id']), ENT_QUOTES, 'UTF-8');
        $when = $r['state'] === 'out' && !empty($r['out_at']) ? 'Went out ' . date_i18n('M j', strtotime((string) $r['out_at'])) : 'Kept ' . date_i18n('M j', strtotime((string) $r['updated_at'])) . ', not out yet';
        $diff = trim((string) $r['claude_text']) !== '' ? ias_word_diff((string) $r['claude_text'], (string) $r['final_text']) : [];
        $summary = $r['edited'] ? ($diff ? ias_word_diff_summary($diff) : 'rewritten by hand') : 'nothing';
        echo '<tr><td><strong>' . esc_html(ias_post_type((string) $r['type'])['short']) . '</strong> · ' . ($p ? '<a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $r['post_id']])) . '">' . esc_html($subject) . '</a>' : esc_html($subject))
          . '<br><span class="description">' . esc_html($when) . '</span></td>';
        echo '<td>' . esc_html(ias_voice_who($r)) . ($r['clean'] ? '' : '<br><span class="description">stopped for a decision — never an example</span>') . '</td>';
        echo '<td><details><summary>' . esc_html(ucfirst($summary)) . ($r['asks'] ? ' · asked Claude ' . count($r['asks']) . '×' : '') . '</summary>';
        if ($r['edited'] && $diff) {
          echo '<div class="ias-diff">' . ias_word_diff_html($diff) . '</div><p class="description">Struck through: Claude\'s words taken out. Underlined: the words put in.</p>';
        } else {
          echo '<div class="ias-diff">' . nl2br(esc_html((string) $r['final_text'])) . '</div>';
        }
        if ($r['asks']) echo '<p><strong>Asked in Talk it over:</strong></p><ul class="ias-asks">' . implode('', array_map(function ($a) { return '<li>' . esc_html((string) $a) . '</li>'; }, $r['asks'])) . '</ul>';
        echo '</details></td><td>';
        $toggle = function (string $flag, bool $on, string $label) use ($r, $nonce, $back) {
          return '<form method="post" class="ias-act">' . $nonce . $back . '<input type="hidden" name="ias_voice_action" value="flag"><input type="hidden" name="ias_post" value="' . (int) $r['post_id'] . '"><input type="hidden" name="ias_flag" value="' . esc_attr($flag) . '"><input type="hidden" name="ias_on" value="' . ($on ? '1' : '') . '">'
            . '<button class="button-link">' . esc_html($label) . '</button></form>';
        };
        $teaches = $r['learn'] && $r['clean'] && ($r['touched'] || $r['star']);
        echo ($teaches ? ia_ui_badge('Teaches', 'ok') : ia_ui_badge($r['learn'] ? 'Logged' : 'Not learned from', 'muted')) . '<br>'
          . ($r['clean'] || $r['star'] ? $toggle('star', !$r['star'], $r['star'] ? '★ Starred — take the star off' : '☆ Star as a good example') : '') // a post that stopped for a decision is never an example
          . $toggle('learn', !$r['learn'], $r['learn'] ? 'Don\'t learn from this' : 'Learn from this again');
        echo '</td></tr>';
      }
      echo '</tbody></table></div>';
      if ($paged > 1 || $more) {
        echo '<p>' . ($paged > 1 ? '<a class="button" href="' . esc_url(add_query_arg('paged', $paged - 1, $here)) . '">← Newer</a> ' : '') . ($more ? '<a class="button" href="' . esc_url(add_query_arg('paged', $paged + 1, $here)) . '">Older →</a>' : '') . '</p>';
      }
    }
    echo '</div>';

    echo '</div><div>';
    // How it learns, and Suggest updates.
    $new = ias_voice_new_since_learned();
    $why = ias_ai_blocker();
    $examples = (int) ias_setting('voice_examples', 3);
    echo '<div class="ias-card"><h3>How it learns</h3><table class="ias-kv">'
      . '<tr><th>Posts that teach</th><td>' . (int) ($counts['teaching'] ?? 0) . ' <span class="description">written, approved or starred by a person</span></td></tr>'
      . '<tr><th>In each new draft</th><td>' . ($examples > 0 ? 'the house rules and up to ' . $examples . ' example post' . ($examples === 1 ? '' : 's') : 'the house rules (examples are off)') . ' <a href="' . esc_url(ias_admin_url(IAS_PAGE_SETTINGS)) . '">Settings</a></td></tr>'
      . '<tr><th>In Talk it over</th><td>the house rules, and the Remember button when you ask for something that should hold for every post</td></tr>'
      . '<tr><th>Kept</th><td>the house rules for good; posts in the log for ' . (int) ias_voice_keep()['days'] . ' days (starred ones for good); chat messages for ' . (int) ias_voice_keep()['chat_days'] . ' days</td></tr>'
      . '</table>';
    echo '<h3>Suggest updates</h3><p>Claude reads the newest posts that teach — what was changed and what was asked for — and suggests house rules. You see them first; nothing changes until you save. The rules are what lasts: the log is the raw material, and old posts leave it.</p>';
    echo '<p class="description">' . (ias_setting('voice_auto', true) ? 'It also does this on its own: at most once a week, once ' . (int) ias_voice_auto_after() . ' new posts teach (Settings → Learn on its own).' : 'Learning on its own is off (Settings → Learn on its own).') . '</p>';
    $learned = (array) get_option('ias_voice_learned', []);
    if (!empty($learned['at'])) echo '<p class="description">Last read ' . esc_html(date_i18n('M j, g:i a', strtotime((string) $learned['at']))) . '; ' . (int) $new . ' new since.</p>';
    else echo '<p class="description">' . (int) $new . ' post' . ($new === 1 ? '' : 's') . ' to read.</p>';
    echo '<form method="post">' . $nonce . $back . '<input type="hidden" name="ias_voice_action" value="learn"><button class="button" data-ia-busy="Reading…"' . ($why !== '' || !($counts['teaching'] ?? 0) ? ' disabled' : '') . '>Suggest updates from the log</button></form>';
    $cannot = $why !== '' ? $why : (!($counts['teaching'] ?? 0) ? 'Nothing to read yet: no post in the log was written, approved or starred by a person. Edit or approve a post, or star one below.' : '');
    echo '<p class="description">' . esc_html($cannot !== '' ? $cannot : 'One Claude call, counted against the monthly cap.') . '</p></div>';
    echo '</div></div></div>';
  }
}
