<?php
/**
 * Intel Auctions Social — one post on the Social Calendar, and what a person
 * can do with it: read the preview (text, pictures with their alt text, the
 * first comment), edit the text, have it rewritten, skip it or bring it back,
 * and decide on a draft that stopped for a person ("Keep as written").
 *
 * Also the lists under the calendar grid — posts needing a decision, listings
 * waiting for photos, drafts not written yet — and the "Write drafts now"
 * button. Every action is a nonce-checked POST that redirects back.
 */

if (!defined('ABSPATH')) exit;

/** Statuses a person may still change the words of. */
if (!function_exists('ias_post_editable')) {
  function ias_post_editable(array $p): bool {
    // A post a person edited in OneUp is changed there: the plugin never replaces their version.
    return in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'skipped', 'dropped'], true) && $p['type'] !== 'human' && empty($p['locked']);
  }
}

/* ---------------- Actions ---------------- */

add_action('ias_calendar_action', function (string $action): void {
  $id = isset($_POST['ias_post']) ? (int) $_POST['ias_post'] : 0;
  $actor = ias_actor();

  if ($action === 'drafts_now') {
    $sync = ias_sync();
    $r = ias_drafts_run(4, 100);
    $msg = $r['written'] ? $r['written'] . ' draft' . ($r['written'] === 1 ? '' : 's') . ' written' . ($r['held'] ? ' (' . $r['held'] . ' need a decision)' : '') . '.' : 'No drafts were due.';
    if ($r['left']) $msg .= ' ' . $r['left'] . ' more will be written on the next hourly runs.';
    if ($r['errors']) $msg .= ' Problem: ' . implode(' ', $r['errors']);
    ia_flash_set('<div class="notice notice-' . ($r['errors'] ? 'warning' : 'success') . '"><p>' . esc_html($msg) . ' <span class="description">(' . (int) $sync['added'] . ' posts added to the plan.)</span></p></div>');
    return;
  }

  $p = $id > 0 ? ias_post_get($id) : null;
  if (!$p) return;
  $back = ias_admin_url(IAS_PAGE_CALENDAR, ['post' => $id]);
  $_POST['ias_back'] = $back;
  $flash = function (string $html, string $level = 'success') { ia_flash_set('<div class="notice notice-' . $level . '"><p>' . $html . '</p></div>'); };

  switch ($action) {
    case 'edit':
      if (!ias_post_editable($p)) { $flash('This post can no longer be edited here.', 'error'); return; }
      $text = trim(str_replace("\r\n", "\n", (string) wp_unslash($_POST['ias_text'] ?? '')));
      if ($text === '') { $flash('The text cannot be empty — use Skip to drop the post.', 'error'); return; }
      $check = ias_recheck_post($p, $text);
      $content = $p['content'];
      $content['text'] = $check['text'];
      $content['opener'] = ias_first_line($check['text']);
      if (isset($_POST['ias_comment'])) $content['first_comment'] = trim(str_replace("\r\n", "\n", (string) wp_unslash($_POST['ias_comment'])));
      $content['generated_by'] = 'edited';
      $flags = $p['flags'];
      $flags['edited_by'] = $actor;
      $flags['holds'] = $check['holds'];
      $flags['fixes'] = $check['fixes'];
      unset($flags['stale'], $flags['released_by']); // a person read it against today's listing; any old "keep as written" was for other words
      $status = $check['holds'] ? 'held' : ($p['status'] === 'held' ? 'planned' : $p['status']);
      if (in_array($p['status'], ['waiting', 'approved'], true) && !$check['holds']) $status = 'planned'; // changed words need a fresh yes
      if (in_array($p['status'], ['waiting', 'approved'], true) && function_exists('ias_approval_void')) ias_approval_void($id); // old links must not approve new words
      $update = ['content' => $content, 'flags' => $flags, 'status' => $status, 'note' => $check['holds'] ? 'Needs a decision: ' . $check['holds'][0] : '', 'facts_hash' => ias_facts_hash($p, ias_post_facts($p))];
      if ($p['status'] === 'approved') $update += ['approved_at' => null, 'approved_by' => ''];
      ias_post_update($id, $update);
      ias_history_forget_post($id, ['opener']);
      ias_history_add('opener', $content['opener'], (int) $p['listing_id'], $id);
      ias_post_trail($id, 'Text edited' . ($check['holds'] ? ' — still needs a decision: ' . $check['holds'][0] : ''), $actor);
      ias_log('edited', (int) $p['listing_id'], ['post' => $id, 'summary' => 'Text edited' . ($check['holds'] ? '; held: ' . implode(' ', $check['holds']) : '')], $actor);
      if (!empty($_POST['ias_and_approve']) && !$check['holds'] && $p['lane'] === 'A' && function_exists('ias_approve')) {
        $r = ias_approve($id, $actor, 'edited and approved in WordPress');
        $flash(esc_html('Saved. ' . $r['message']), $r['ok'] ? 'success' : 'warning');
        return;
      }
      do_action('ias_post_edited', $id, $check['holds']);
      $flash($check['holds'] ? 'Saved, but it still needs a decision: ' . esc_html(implode(' ', $check['holds'])) : 'Saved.', $check['holds'] ? 'warning' : 'success');
      return;

    case 'media':
      if (!ias_post_editable($p)) { $flash('This post\'s pictures can no longer be changed here.', 'error'); return; }
      $keys = array_filter(explode(',', (string) wp_unslash($_POST['ias_media'] ?? '')));
      $r = ias_media_save($id, $keys, $actor);
      if (!$r['ok']) { $flash(esc_html($r['error']), 'error'); return; }
      $was_lane_a = in_array($p['status'], ['waiting', 'approved'], true);
      $flash(esc_html('Pictures saved (' . $r['count'] . ').' . ($was_lane_a ? ' It needs approving again with the new pictures.' : '') . ' A rewrite keeps them; "Let the plugin choose again" hands them back.'));
      return;

    case 'media_reset':
      if (!ias_post_editable($p)) { $flash('This post\'s pictures can no longer be changed here.', 'error'); return; }
      $flash(ias_media_reset($id, $actor) ? 'The plugin has chosen the pictures again.' : 'The plugin was already choosing this post\'s pictures.');
      return;

    case 'regenerate':
      if (!ias_post_editable($p) || $p['status'] === 'skipped') { $flash('This post cannot be rewritten now.', 'error'); return; }
      $last = (int) ($p['flags']['regen_at'] ?? 0);
      if ($last > time() - HOUR_IN_SECONDS) { $flash('It was rewritten less than an hour ago — edit it instead, or try again later.', 'warning'); return; }
      $flags = $p['flags'];
      $flags['regen_at'] = time();
      unset($flags['gen_error_at']);
      ias_post_update($id, ['flags' => $flags]);
      $r = ias_generate_post($id, true, $actor);
      if (!$r['ok']) { $flash('Could not rewrite it: ' . esc_html((string) $r['error']), 'error'); return; }
      $flash(!empty($r['holds']) ? 'Rewritten — it needs a decision: ' . esc_html(implode(' ', $r['holds'])) : 'Rewritten.', !empty($r['holds']) ? 'warning' : 'success');
      return;

    case 'skip':
      if (!in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'dropped', 'failed'], true)) { $flash('Only a post that has not gone out can be skipped.', 'error'); return; }
      $why = sanitize_text_field((string) wp_unslash($_POST['ias_why'] ?? ''));
      $flags = $p['flags'];
      $flags['skipped_by'] = 'person';
      ias_post_update($id, ['status' => 'skipped', 'flags' => $flags, 'note' => 'Skipped by ' . $actor . ($why !== '' ? ': ' . $why : '')]);
      ias_post_trail($id, 'Skipped' . ($why !== '' ? ': ' . $why : ''), $actor);
      ias_log('skipped', (int) $p['listing_id'], ['post' => $id, 'summary' => $why !== '' ? $why : 'Skipped'], $actor);
      $flash('Skipped. It stays off the plan until you press Restore.');
      return;

    case 'restore':
      if (!in_array($p['status'], ['skipped', 'cancelled', 'dropped'], true)) return;
      $flags = $p['flags'];
      // Restoring over the system's reason ("Facebook already got it", "photos never arrived"): the sync leaves it be.
      if (($flags[$p['status'] . '_by'] ?? '') === 'system') $flags['restored_over'] = (string) $p['note'];
      unset($flags['skipped_by'], $flags['cancelled_by'], $flags['dropped_by']);
      $status = ias_back_on_plan_status(['flags' => $flags]);
      ias_post_update($id, ['status' => $status, 'flags' => $flags, 'note' => $status === 'held' ? 'Needs a decision: ' . (string) ($flags['holds'][0] ?? '') : '']);
      ias_post_trail($id, 'Restored to the plan', $actor);
      ias_log('planned', (int) $p['listing_id'], ['post' => $id, 'summary' => 'Restored to the plan'], $actor);
      $flash('Back on the plan.');
      return;

    case 'correction_done':
      if ($p['status'] !== 'published' || empty($p['flags']['correction']['text'])) return;
      $flags = $p['flags'];
      $done = (string) ($_POST['ias_done'] ?? '') === 'posted' ? 'posted' : 'not needed';
      $flags['correction']['done'] = $done . ' by ' . $actor . ' ' . ias_now_sql();
      ias_post_update($id, ['flags' => $flags]);
      ias_post_trail($id, 'Correction ' . $done, $actor);
      ias_log('edited', (int) $p['listing_id'], ['post' => $id, 'summary' => 'Correction comment ' . $done], $actor);
      $flash($done === 'posted' ? 'Marked: the correction is posted.' : 'Marked: no correction needed.');
      return;

    case 'release':
      if ($p['status'] !== 'held' || empty($p['content']['text'])) return;
      if (ias_has_verify((array) $p['content'])) { $flash('Put the right fact in place of the <strong>[VERIFY: …]</strong> words and press <strong>Save text</strong> first — a marked fact never goes out as it is.', 'error'); return; }
      $flags = $p['flags'];
      $flags['released_by'] = $actor;
      unset($flags['stale']); // the reasons it stopped include the listing having changed: kept as written over that too
      ias_post_update($id, ['status' => 'planned', 'flags' => $flags, 'note' => 'Kept as written by ' . $actor, 'facts_hash' => ias_facts_hash($p, ias_post_facts($p))]);
      ias_post_trail($id, 'Kept as written despite: ' . implode(' ', (array) ($flags['holds'] ?? [])), $actor);
      ias_log('edited', (int) $p['listing_id'], ['post' => $id, 'summary' => 'Kept as written despite the hold'], $actor);
      do_action('ias_post_released', $id);
      $flash('Kept as written.');
      return;
  }
});

/* ---------------- The post view ---------------- */

/** One button in its own nonce-checked form (the post view's actions). */
if (!function_exists('ias_post_action_form')) {
  function ias_post_action_form(array $p, string $action, string $label, string $class = 'button', string $extra = '', string $confirm = ''): string {
    return '<form method="post" class="ias-act">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
      . '<input type="hidden" name="ias_cal_action" value="' . esc_attr($action) . '"><input type="hidden" name="ias_post" value="' . (int) $p['id'] . '">'
      . $extra . '<button class="' . esc_attr($class) . '"' . ($confirm !== '' ? ' onclick="return confirm(\'' . esc_js($confirm) . '\')"' : '') . ' data-ia-busy="Working…">' . esc_html($label) . '</button></form>';
  }
}

/** Text as HTML with every [VERIFY: …] marker picked out. */
if (!function_exists('ias_mark_verify')) {
  function ias_mark_verify(string $text): string {
    return (string) preg_replace('/\[VERIFY[^\]]*\]/i', '<mark class="ias-verify">$0</mark>', esc_html($text));
  }
}

/** "Skip this post…", folded: the reason and the button open on a click. */
if (!function_exists('ias_skip_details')) {
  function ias_skip_details(array $p): string {
    if (!in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'dropped', 'failed'], true) || $p['type'] === 'human') return '';
    return '<details class="ias-skip"><summary>Skip this post…</summary>'
      . ias_post_action_form($p, 'skip', 'Skip it', 'button', '<input type="text" name="ias_why" placeholder="Why? (optional)" class="regular-text" style="width:220px">') . '</details>';
  }
}

/**
 * The bar across the top of a post: what it is waiting for, in a sentence,
 * with the buttons that settle it beside the reason — not a screen further
 * down. A post that waits for nothing has no bar.
 */
add_action('ias_post_view_bar', function (array $p): void {
  $flags = (array) $p['flags'];
  $c = (array) $p['content'];
  $text = (string) ($c['text'] ?? '');
  $form = function (string $action, string $label, string $class = 'button', string $extra = '', string $confirm = '') use ($p) { return ias_post_action_form($p, $action, $label, $class, $extra, $confirm); };
  $editable = ias_post_editable($p);
  $rewrite = $editable && $p['status'] !== 'skipped' && $text !== '' ? $form('regenerate', 'Rewrite', 'button', '', 'Throw away this draft and have Claude write a new one? (Counts against the monthly Claude cap.)') : '';
  $level = ''; $lines = ''; $buttons = '';

  if ($p['status'] === 'held' && !empty($flags['holds'])) {
    $verify = ias_has_verify($c);
    $level = 'error';
    $lines = '<p><strong>Needs a decision — this post goes nowhere until someone decides.</strong></p><ul class="ias-reasons">';
    foreach ((array) $flags['holds'] as $h) $lines .= '<li>' . ias_mark_verify((string) $h) . '</li>';
    $lines .= '</ul><p>' . ($verify
      ? 'Put the right fact in place of the marked words and press <strong>Save text</strong> — or fix the listing, and the post is rewritten on the next hourly run.'
      : 'Fix the text and press <strong>Save text</strong>, press <strong>Rewrite</strong> — or, if it is right as it is, <strong>Keep as written</strong>.') . '</p>';
    $buttons = $rewrite . ($text !== '' && !$verify ? $form('release', 'Keep as written', 'button', '', 'Keep this draft exactly as it is, despite the reasons it stopped?') : '');
  } elseif ($p['status'] === 'waiting') {
    $sent = (array) ($p['approvals']['sent'] ?? []);
    $first = $sent ? (array) $sent[0] : [];
    $level = 'warning';
    $lines = '<p><strong>Waiting for approval.</strong> Nothing goes to OneUp until ' . esc_html(implode(' or ', array_map('ias_person_name', ias_approvers())) ?: 'an approver') . ' says yes.'
      . ($first ? ' Emailed ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $first['at']))) . (count($sent) > 1 ? ', ' . count($sent) . ' emails so far' : '') . '.' : '') . '</p>';
  } elseif (!empty($flags['stale'])) {
    $level = 'warning';
    $lines = '<p>' . (!empty($flags['edited_by'])
      ? 'The listing changed since <strong>' . esc_html(ias_actor_name((string) $flags['edited_by'])) . '</strong> edited this post. Check the text and save it, or press Rewrite.'
      : 'The listing changed since this draft was written; it is rewritten on the next hourly run, and goes nowhere until then.') . '</p>';
    $buttons = $rewrite;
  } elseif (!empty($flags['needs'])) {
    $level = 'info';
    $lines = '<p>' . esc_html((string) $p['note']) . '</p>';
  } elseif (in_array($p['status'], ['skipped', 'cancelled', 'dropped'], true)) {
    $level = 'info';
    $lines = '<p><strong>' . esc_html(ias_statuses()[$p['status']]['label']) . '.</strong> ' . esc_html((string) $p['note']) . ' It stays off the plan until you restore it.</p>';
    $buttons = $form('restore', 'Restore to the plan', 'button');
  } elseif ($p['status'] === 'failed') {
    $level = 'error';
    $lines = '<p><strong>Failed.</strong> ' . esc_html((string) $p['note']) . '</p>';
  } elseif ($p['lane'] === 'A' && $p['status'] === 'planned' && $text !== '' && !ias_post_needs_decision($p)) {
    $level = 'info';
    $lines = '<p><strong>Approve first.</strong> This post carries hard facts, so it waits for a yes — it is emailed for one as soon as its draft is clean, or approve it here.</p>';
  }
  if ($level === '') return;
  ob_start();
  /** Approve, Send for approval, a reminder (approval.php adds them here, first). */
  do_action('ias_post_view_actions', $p, $form);
  $buttons = (string) ob_get_clean() . $buttons;
  $skip = in_array($p['status'], ['skipped', 'cancelled', 'dropped'], true) ? '' : ias_skip_details($p);
  echo '<div class="notice notice-' . esc_attr($level) . ' inline ias-bar"><div class="ias-bar-text">' . $lines . '</div>'
    . ($buttons . $skip !== '' ? '<div class="ias-actions">' . $buttons . $skip . '</div>' : '') . '</div>';
});

/** The left column: the words (Edit) and the pictures (Pictures). */
add_action('ias_post_view_main', function (array $p): void {
  $c = (array) $p['content'];
  $text = (string) ($c['text'] ?? '');
  $flags = (array) $p['flags'];
  $editable = ias_post_editable($p);
  // Where the bar speaks for the post, Rewrite and Skip are there; otherwise they end the Edit card.
  $bar_owns = in_array($p['status'], ['held', 'waiting', 'skipped', 'cancelled', 'dropped', 'failed'], true) || !empty($flags['stale']) || !empty($flags['needs']) || ($p['lane'] === 'A' && $p['status'] === 'planned');

  echo '<div class="ias-card ias-edit"><h2>Edit</h2>';
  if ($text === '') {
    echo '<p class="description">' . esc_html(!empty($flags['gen_error']) ? 'No draft yet: ' . $flags['gen_error'] : 'No draft written yet — the next hourly run writes it.') . '</p>';
    if ($editable && $p['status'] !== 'skipped') echo '<div class="ias-foot"><div class="ias-actions">' . ias_post_action_form($p, 'regenerate', 'Write it now', 'button') . '</div><div class="ias-actions">' . ias_skip_details($p) . '</div></div>';
  } elseif ($editable) {
    $rows = (int) min(30, max(8, substr_count($text, "\n") + 1 + (int) ceil(mb_strlen($text) / 80)));
    $primary = !($p['lane'] === 'A' && in_array($p['status'], ['waiting', 'planned'], true)); // the bar's Approve is the one primary button there
    echo '<form method="post" id="ias-text-form">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
      . '<input type="hidden" name="ias_cal_action" value="edit"><input type="hidden" name="ias_post" value="' . (int) $p['id'] . '">'
      . '<p><label for="ias-text"><strong>Post text</strong></label><textarea id="ias-text" name="ias_text" rows="' . $rows . '" class="large-text">' . esc_textarea($text) . '</textarea></p>';
    if (!empty($c['first_comment'])) {
      echo '<p><label for="ias-comment"><strong>First comment</strong></label><textarea id="ias-comment" name="ias_comment" rows="3" class="large-text">' . esc_textarea((string) $c['first_comment']) . '</textarea></p>';
    }
    if (!empty($flags['fixes'])) echo '<p class="description">' . esc_html(implode(' ', (array) $flags['fixes'])) . '</p>';
    echo '<p class="description">Saving checks the text again (bidding, figures, fair housing).' . ($p['lane'] === 'A' ? ' Changed words need a new yes.' : '') . (ias_channels_extra_on() ? ' Instagram, X and LinkedIn follow these words.' : '') . '</p></form>';
    // The foot sits outside the form (Save points at it with form="…"), so Rewrite and Skip post their own forms.
    echo '<div class="ias-foot"><div class="ias-actions"><button form="ias-text-form" class="button' . ($primary ? ' button-primary' : '') . '">Save text</button>'
      . ($p['lane'] === 'A' && in_array($p['status'], ['planned', 'waiting', 'held'], true) ? ' <button form="ias-text-form" class="button" name="ias_and_approve" value="1">Save and approve</button>' : '') . '</div>'
      . ($bar_owns ? '' : '<div class="ias-actions">' . ($p['status'] !== 'skipped' ? ias_post_action_form($p, 'regenerate', 'Rewrite', 'button', '', 'Throw away this draft and have Claude write a new one? (Counts against the monthly Claude cap.)') : '') . ias_skip_details($p) . '</div>') . '</div>';
  } else {
    $why = !empty($p['locked']) ? 'A person moved or edited this post in OneUp, so it is changed there now — the plugin leaves their version as it is.'
      : (in_array(ias_post_shown_status($p), ['pushed', 'published'], true) ? 'This post has gone to OneUp. Live posts are never edited — editing resets their reach — so a correction is posted as a comment.' : 'This post cannot be edited now.');
    echo '<p class="description">' . esc_html($why) . '</p><div class="ias-preview">' . esc_html($text) . '</div>';
  }
  echo '</div>';

  if (!$editable) return;
  $media = (array) ($c['media'] ?? []);
  $max = ias_media_max();
  $items = [];
  foreach ((array) ($media['images'] ?? []) as $n => $img) {
    $att = (int) ($img['id'] ?? 0);
    $items[] = ['key' => $att > 0 ? 'a' . $att : 'g' . (int) $n, 'thumb' => $att > 0 ? (string) (wp_get_attachment_image_url($att, 'medium') ?: $img['url']) : (string) $img['url'], 'alt' => (string) ($img['alt'] ?? '')];
  }
  $tile = function (array $it, int $i) {
    return '<li class="ias-pic" data-key="' . esc_attr($it['key']) . '" title="' . esc_attr($it['alt']) . '"><img src="' . esc_url($it['thumb']) . '" alt="" draggable="false">'
      . '<span class="ias-pic-n">' . ($i === 0 ? '1 · Lead' : $i + 1) . '</span>'
      . '<button type="button" class="ias-pic-x" title="Take out of this post" aria-label="Take picture ' . ($i + 1) . ' out of this post">&times;</button>'
      . '<span class="ias-pic-move"><button type="button" class="ias-pic-l" aria-label="Move earlier">&lsaquo;</button><button type="button" class="ias-pic-r" aria-label="Move later">&rsaquo;</button></span>'
      . '<span class="ias-pic-alt">' . esc_html($it['alt']) . '</span></li>';
  };
  echo '<div class="ias-card" id="ias-pictures"><h2>Pictures <span class="description">(<span id="ias-pic-count">' . count($items) . ' of ' . $max . '</span> — the first one leads)</span></h2>';
  if (($media['kind'] ?? '') === 'video') echo '<p class="description">This post shows the property video. Add pictures to use them instead.</p>';
  echo '<form method="post" id="ias-pic-form">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
    . '<input type="hidden" name="ias_cal_action" value="media"><input type="hidden" name="ias_post" value="' . (int) $p['id'] . '">'
    . '<input type="hidden" name="ias_media" id="ias-media-order" value="' . esc_attr(implode(',', array_column($items, 'key'))) . '">'
    . '<ul class="ias-pics" id="ias-pics" data-max="' . (int) $max . '" aria-label="Pictures, in the order they post">';
  foreach ($items as $i => $it) echo $tile($it, $i);
  echo '</ul></form>';
  echo '<div class="ias-foot"><div class="ias-actions"><button type="button" class="button" id="ias-pic-add">+ Add pictures</button> <button form="ias-pic-form" class="button" id="ias-pic-save" disabled>Save pictures</button> <span class="ias-pic-dirty" id="ias-pic-dirty" hidden>Not saved yet</span>'
    . '<span class="description" id="ias-pic-full"' . (count($items) >= $max ? '' : ' hidden') . '>' . (int) $max . ' is the most a post can carry — take one out first.</span></div>'
    . '<div class="ias-actions">' . (!empty($flags['media_by']) ? ias_post_action_form($p, 'media_reset', 'Let the plugin choose again', 'button-link', '', 'Throw away the pictures chosen by hand and let the plugin pick again?') : '') . '</div></div>';
  echo '<p class="description">Drag a picture to move it, or use ‹ ›. The first one leads the post. × takes it out of this post, not out of the Media Library. Up to ' . (int) $max . ' go with the post'
    . (!empty($flags['media_by']) ? ' — chosen by ' . esc_html(ias_actor_name((string) $flags['media_by'])) . '; a rewrite keeps them.' : ' — chosen by the plugin; once you save, your choice holds through rewrites.') . '</p></div>';
});

/** The middle column: the post as each network would show it (Facebook, and any other switched on). */
add_action('ias_post_view_preview', function (array $p): void {
  $c = (array) $p['content'];
  $flags = (array) $p['flags'];
  $text = (string) ($c['text'] ?? '');
  $chs = array_merge(['facebook'], ias_channels_extra_on());
  echo '<div class="ias-card"><div class="ias-prev-head"><h2>Preview</h2>';
  if (count($chs) > 1) {
    echo '<div class="ias-nets" role="tablist">';
    foreach ($chs as $i => $ch) echo '<button type="button" role="tab" class="ias-net' . ($i === 0 ? ' is-on' : '') . '" data-net="' . esc_attr($ch) . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '">' . esc_html((string) ias_channel($ch)['label']) . '</button>';
    echo '</div>';
  } else {
    echo '<span class="description">Facebook</span>';
  }
  echo '</div>';
  if ($text === '') { echo '<p class="description">No draft yet.</p></div>'; return; }
  $when = !empty($p['scheduled_at']) ? date_i18n('D M j, g:i a', strtotime((string) $p['scheduled_at'])) : 'Not scheduled yet';
  $icon = function_exists('get_site_icon_url') ? (string) get_site_icon_url(40) : '';
  $avatar = function (string $cls = '') use ($icon) { return '<div class="ias-fb-avatar ' . esc_attr($cls) . '">' . ($icon !== '' ? '<img src="' . esc_url($icon) . '" alt="">' : 'IA') . '</div>'; };
  $facts = ias_post_facts($p);
  foreach ($chs as $i => $ch) {
    $req = ias_render_channel($p, $ch, $facts);
    $body = (string) ($req['params']['content'] ?? '');
    $urls = !empty($req['params']['image_url']) ? explode('~~', (string) $req['params']['image_url']) : [];
    $alts = (array) ($req['alt'] ?? []);
    echo '<div class="ias-net-panel" data-net="' . esc_attr($ch) . '"' . ($i === 0 ? '' : ' hidden') . '>';
    echo '<div class="ias-fb ias-fb-' . esc_attr($ch) . '"><div class="ias-fb-head">' . $avatar() . '<div><div class="ias-fb-name">' . esc_html($ch === 'instagram' ? 'intelauctions' : 'Intel Auctions') . '</div><div class="ias-fb-time">' . esc_html($when) . ($ch === 'facebook' ? ' · Public' : '') . '</div></div></div>';
    $photos = '';
    if ($urls) {
      $show = $ch === 'facebook' ? 5 : ($ch === 'instagram' ? 1 : count($urls));
      foreach (array_slice($urls, 0, $show) as $k => $u) {
        $more = ($ch === 'facebook' && $k === $show - 1 && count($urls) > $show) ? '<span class="more">+' . (count($urls) - $show) . '</span>' : ''; // Instagram says "1 of N" instead
        $photos .= '<a href="' . esc_url($u) . '" target="_blank" rel="noopener"><img src="' . esc_url($u) . '" alt="' . esc_attr((string) ($alts[$k] ?? '')) . '">' . $more . '</a>';
      }
      $photos = '<div class="ias-fb-photos ias-photos-' . esc_attr($ch) . '">' . $photos . '</div>' . ($ch === 'instagram' && count($urls) > 1 ? '<div class="ias-fb-label">1 of ' . count($urls) . ' — a carousel</div>' : '');
    } elseif (($c['media']['kind'] ?? '') === 'video' && !empty($c['media']['video_url'])) {
      $photos = '<div class="ias-fb-text"><a href="' . esc_url((string) $c['media']['video_url']) . '" target="_blank" rel="noopener">▶ ' . esc_html((string) $c['media']['video_url']) . '</a></div>';
    }
    $words = '<div class="ias-fb-text">' . ias_mark_verify($body) . '</div>';
    echo $ch === 'instagram' ? $photos . $words : $words . $photos; // Instagram: the picture first, the caption under it
    if ($ch === 'facebook' && !empty($req['params']['first_comment'])) {
      echo '<div class="ias-fb-comment">' . $avatar() . '<div class="ias-fb-bubble"><b>Intel Auctions</b>' . ias_mark_verify((string) $req['params']['first_comment']) . '</div></div><div class="ias-fb-label">First comment</div>';
    }
    echo '</div>';
    if ($ch === 'x') {
      $len = ias_x_length($body);
      echo '<p class="description">' . (int) $len . ' of 280 characters (a web address counts as 23).</p>';
    }
    if ($ch !== 'facebook') {
      $problems = ias_channel_problems($req, $ch);
      echo '<p class="description">Made from the Facebook words' . ($ch === 'x' ? ' and the listing\'s facts' : '') . '. Not sent — ' . esc_html((string) ias_channel($ch)['label']) . ' is in the dry run only.' . ($problems ? ' <strong>' . esc_html(ucfirst(implode('; ', $problems))) . '.</strong>' : '') . '</p>';
    }
    echo '</div>';
  }
  $bits = [];
  if (!empty($c['template'])) $bits[] = 'Written as: ' . (ias_templates()[$c['template']][0] ?? $c['template']);
  if (!empty($c['voice'])) $bits[] = 'voice: ' . ias_division_label((string) $c['voice']);
  if (!empty($c['cta_kind'])) $bits[] = 'call to action: ' . (ias_cta_kinds()[$c['cta_kind']][0] ?? $c['cta_kind']);
  if (!empty($p['generated_at'])) $bits[] = ($c['generated_by'] ?? '') === 'edited' ? 'edited by ' . ias_actor_name((string) ($flags['edited_by'] ?? '')) : 'written ' . date_i18n('M j, g:i a', strtotime((string) $p['generated_at']));
  if ($bits) echo '<p class="description">' . esc_html(implode(' · ', $bits)) . '</p>';
  if (!ias_post_editable($p) && !empty($c['media']['images'])) {
    echo '<details><summary>' . count((array) $c['media']['images']) . ' picture(s): alt text</summary><ol>';
    foreach ((array) $c['media']['images'] as $img) echo '<li>' . esc_html((string) $img['alt']) . '</li>';
    echo '</ol></details>';
  }
  echo '</div>';
});

/* ---------------- Around the grid ---------------- */

/** A short list of posts, each linked, with its day and (for $why = 'note') its note. */
if (!function_exists('ias_calendar_list')) {
  function ias_calendar_list(string $title, array $rows, string $why = ''): string {
    $out = '<div class="ias-card"><h3>' . esc_html($title) . ' <span class="description">(' . count($rows) . ')</span></h3><ul style="margin:0">';
    foreach (array_slice($rows, 0, 25) as $p) {
      $when = ias_post_when($p);
      $out .= '<li><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '"><strong>' . esc_html(ias_post_type((string) $p['type'])['short']) . '</strong> · ' . esc_html(ias_post_subject($p)) . '</a>'
        . ($when ? ' <span class="description">' . esc_html($when->format('D M j')) . '</span>' : '')
        . ($why === 'note' && $p['note'] !== '' ? '<br><span class="description">' . esc_html((string) $p['note']) . '</span>' : '') . '</li>';
    }
    return $out . '</ul></div>';
  }
}

/** Above the grid, only when there is something: what waits on a person (decisions, approvals, corrections). */
add_action('ias_calendar_before_grid', function (): void {
  ob_start();
  $held = ias_posts_query(['status' => ['held', 'failed'], 'limit' => 100]);
  if ($held) echo ias_calendar_list('Needs a decision', $held, 'note');
  /** Approval adds Waiting for approval here. */
  do_action('ias_calendar_attention');
  $fixes = function_exists('ias_corrections_waiting') ? ias_corrections_waiting() : [];
  if ($fixes) {
    echo '<div class="ias-card"><h3>Corrections to post as a comment <span class="description">(' . count($fixes) . ')</span></h3><p class="description">A fact changed after these went out. A post that has gone out is never edited — that resets its reach — so post the correction as a comment on it in Facebook, then mark it.</p>';
    foreach ($fixes as $p) {
      echo '<div style="border-top:1px solid #f0f0f1;padding:8px 0"><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '"><strong>' . esc_html(ias_post_type((string) $p['type'])['short']) . '</strong> · ' . esc_html(ias_post_subject($p)) . '</a>'
        . '<textarea readonly rows="2" class="large-text" onclick="this.select()" style="margin:6px 0">' . esc_textarea((string) $p['flags']['correction']['text']) . '</textarea>';
      foreach (['posted' => 'Comment posted', 'not_needed' => 'Not needed'] as $done => $label) {
        echo '<form method="post" style="display:inline-block;margin-right:6px">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
          . '<input type="hidden" name="ias_cal_action" value="correction_done"><input type="hidden" name="ias_post" value="' . (int) $p['id'] . '"><input type="hidden" name="ias_done" value="' . esc_attr($done) . '">'
          . '<button class="button' . ($done === 'posted' ? ' button-primary' : '') . '">' . esc_html($label) . '</button></form>';
      }
      echo '</div>';
    }
    echo '</div>';
  }
  $html = (string) ob_get_clean();
  if ($html !== '') echo '<div class="ias-attn">' . $html . '</div>';
}, 20);

/** Under the grid: drafts still to write, and listings waiting for photos. */
add_action('ias_calendar_after_grid', function (array $posts_in_range): void {
  $planned = ias_posts_query(['status' => 'planned', 'limit' => 1000]);
  $photos  = array_values(array_filter($planned, function ($p) { return !empty($p['flags']['needs']); }));
  $nodraft = array_values(array_filter($planned, function ($p) { return empty($p['flags']['needs']) && empty($p['content']['text']); }));
  $button = '<form method="post" style="display:inline">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
    . '<input type="hidden" name="ias_cal_action" value="drafts_now"><button class="button" data-ia-busy="Writing…">Write drafts now</button></form>';
  echo '<div class="ias-cols" style="margin-top:16px"><div>';
  echo '<div class="ias-card"><h3>Drafts</h3><p>' . count($nodraft) . ' planned post' . (count($nodraft) === 1 ? ' has' : 's have') . ' no draft yet; a few are written every hour (Settings: drafts per run). '
    . $button . '</p><p class="description">Each draft is one Claude call, counted against the toolkit\'s monthly Claude cap.</p></div>';
  echo '</div><div>';
  echo $photos ? ias_calendar_list('Waiting for photos', $photos) : '<p class="description">Every Upcoming listing has photos.</p>';
  echo '</div></div>';
});

/* ---------------- The picture editor's script ---------------- */

/** On a post's view: the Media Library, drag to reorder (jQuery UI sortable), and the editor's few lines of script. */
add_action('admin_enqueue_scripts', function (): void {
  if (($_GET['page'] ?? '') !== IAS_PAGE_CALENDAR || empty($_GET['post']) || !current_user_can('manage_options')) return;
  wp_enqueue_media();
  wp_enqueue_script('jquery-ui-sortable');
  wp_add_inline_script('jquery-ui-sortable', <<<'JS'
jQuery(function ($) {
  // The preview's network tabs.
  $('.ias-nets').on('click', '.ias-net', function () {
    var net = $(this).data('net'), $card = $(this).closest('.ias-card');
    $card.find('.ias-net').removeClass('is-on').attr('aria-selected', 'false');
    $(this).addClass('is-on').attr('aria-selected', 'true');
    $card.find('.ias-net-panel').each(function () { this.hidden = $(this).data('net') !== net; });
  });

  // Leaving with unsaved words or pictures asks first (every other button reloads the page).
  var dirtyText = false, dirtyPics = false, $text = $('#ias-text, #ias-comment'), textStart = $text.map(function () { return this.value; }).get().join('\u0000');
  $text.on('input', function () { dirtyText = $text.map(function () { return this.value; }).get().join('\u0000') !== textStart; });
  $(window).on('beforeunload', function () { if (dirtyText || dirtyPics) return 'Leave without saving?'; });
  $('#ias-text-form').on('submit', function () { dirtyText = false; });
  $('#ias-pic-form').on('submit', function () { dirtyPics = false; });

  var $list = $('#ias-pics');
  if (!$list.length) return;
  var max = parseInt($list.data('max'), 10) || 10, start = $('#ias-media-order').val(), frame;
  function sync() {
    var keys = $list.children('li').map(function () { return $(this).attr('data-key'); }).get(), clean = keys.join(',') === start;
    $('#ias-media-order').val(keys.join(','));
    $('#ias-pic-count').text(keys.length + ' of ' + max);
    $list.children('li').each(function (i) {
      $(this).find('.ias-pic-n').text(i === 0 ? '1 · Lead' : i + 1);
      $(this).find('.ias-pic-x').attr('aria-label', 'Take picture ' + (i + 1) + ' out of this post');
    });
    $('#ias-pic-add').prop('disabled', keys.length >= max);
    $('#ias-pic-full').prop('hidden', keys.length < max);
    $('#ias-pic-dirty').prop('hidden', clean);
    $('#ias-pic-save').prop('disabled', clean).toggleClass('button-primary', !clean);
    dirtyPics = !clean;
  }
  $list.sortable({ items: '> li', tolerance: 'pointer', placeholder: 'ias-pic-hole', update: sync });
  $list.on('click', '.ias-pic-x', function () { $(this).closest('li').remove(); sync(); });
  $list.on('click', '.ias-pic-l', function () { var $li = $(this).closest('li'); $li.prev().before($li); sync(); $li.find('.ias-pic-l').trigger('focus'); });
  $list.on('click', '.ias-pic-r', function () { var $li = $(this).closest('li'); $li.next().after($li); sync(); $li.find('.ias-pic-r').trigger('focus'); });
  $('#ias-pic-add').on('click', function (e) {
    e.preventDefault();
    if (!window.wp || !wp.media) return;
    if (!frame) {
      frame = wp.media({ title: 'Add pictures to this post', button: { text: 'Add to the post' }, library: { type: 'image' }, multiple: true });
      frame.on('open', function () { frame.content.mode('browse'); }); // the library, not the upload box
      frame.on('select', function () {
        frame.state().get('selection').each(function (a) {
          var j = a.toJSON(), key = 'a' + j.id;
          if ($list.children('li[data-key="' + key + '"]').length || $list.children('li').length >= max) return;
          var src = (j.sizes && (j.sizes.medium || j.sizes.thumbnail) || j).url, alt = j.alt || j.title || '';
          $('<li class="ias-pic"/>').attr({ 'data-key': key, title: alt })
            .append($('<img/>').attr({ src: src, alt: '', draggable: 'false' }))
            .append('<span class="ias-pic-n"></span><button type="button" class="ias-pic-x" title="Take out of this post">&times;</button>'
              + '<span class="ias-pic-move"><button type="button" class="ias-pic-l" aria-label="Move earlier">&lsaquo;</button><button type="button" class="ias-pic-r" aria-label="Move later">&rsaquo;</button></span>')
            .append($('<span class="ias-pic-alt"/>').text(alt))
            .appendTo($list);
        });
        sync();
      });
    }
    frame.open();
  });
  sync();
});
JS
  );
});
