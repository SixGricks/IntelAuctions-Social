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
    return in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'skipped', 'dropped'], true) && $p['type'] !== 'human';
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
      unset($flags['stale']);
      $status = $check['holds'] ? 'held' : ($p['status'] === 'held' ? 'planned' : $p['status']);
      if (in_array($p['status'], ['waiting', 'approved'], true) && !$check['holds']) $status = 'planned'; // changed words need a fresh yes
      ias_post_update($id, ['content' => $content, 'flags' => $flags, 'status' => $status, 'note' => $check['holds'] ? 'Needs a decision: ' . $check['holds'][0] : '']);
      ias_history_forget_post($id, ['opener']);
      ias_history_add('opener', $content['opener'], (int) $p['listing_id'], $id);
      ias_post_trail($id, 'Text edited' . ($check['holds'] ? ' — still needs a decision: ' . $check['holds'][0] : ''), $actor);
      ias_log('edited', (int) $p['listing_id'], ['post' => $id, 'summary' => 'Text edited' . ($check['holds'] ? '; held: ' . implode(' ', $check['holds']) : '')], $actor);
      do_action('ias_post_edited', $id, $check['holds']);
      $flash($check['holds'] ? 'Saved, but it still needs a decision: ' . esc_html(implode(' ', $check['holds'])) : 'Saved.', $check['holds'] ? 'warning' : 'success');
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
      if (!in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'dropped'], true)) { $flash('Only a post that has not gone out can be skipped.', 'error'); return; }
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
      unset($flags['skipped_by'], $flags['cancelled_by'], $flags['dropped_by']);
      $status = !empty($flags['holds']) && empty($flags['released_by']) ? 'held' : 'planned';
      ias_post_update($id, ['status' => $status, 'flags' => $flags, 'note' => '']);
      ias_post_trail($id, 'Restored to the plan', $actor);
      ias_log('planned', (int) $p['listing_id'], ['post' => $id, 'summary' => 'Restored to the plan'], $actor);
      $flash('Back on the plan.');
      return;

    case 'release':
      if ($p['status'] !== 'held' || empty($p['content']['text'])) return;
      $flags = $p['flags'];
      $flags['released_by'] = $actor;
      ias_post_update($id, ['status' => 'planned', 'flags' => $flags, 'note' => 'Kept as written by ' . $actor]);
      ias_post_trail($id, 'Kept as written despite: ' . implode(' ', (array) ($flags['holds'] ?? [])), $actor);
      ias_log('edited', (int) $p['listing_id'], ['post' => $id, 'summary' => 'Kept as written despite the hold'], $actor);
      do_action('ias_post_released', $id);
      $flash('Kept as written.');
      return;
  }
});

/* ---------------- The post view ---------------- */

add_action('ias_post_view_main', function (array $p): void {
  $c = $p['content'];
  $text = (string) ($c['text'] ?? '');
  $flags = $p['flags'];
  $form = function (string $action, string $label, string $class = 'button', string $extra = '', string $confirm = '') use ($p) {
    return '<form method="post" style="display:inline-block;margin:0 6px 6px 0">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
      . '<input type="hidden" name="ias_cal_action" value="' . esc_attr($action) . '"><input type="hidden" name="ias_post" value="' . (int) $p['id'] . '">'
      . $extra . '<button class="' . esc_attr($class) . '"' . ($confirm !== '' ? ' onclick="return confirm(\'' . esc_js($confirm) . '\')"' : '') . ' data-ia-busy="Working…">' . esc_html($label) . '</button></form>';
  };

  if (!empty($flags['holds']) && $p['status'] === 'held') {
    echo '<div class="notice notice-error inline"><p><strong>Needs a decision.</strong> This draft stopped before going anywhere:</p><ul style="list-style:disc;margin-left:20px">';
    foreach ((array) $flags['holds'] as $h) echo '<li>' . esc_html((string) $h) . '</li>';
    echo '</ul><p>Edit the text below, rewrite it, skip it — or, if it is right as it is, keep it as written.</p></div>';
  }
  if (!empty($flags['stale'])) {
    echo '<div class="notice notice-warning inline"><p>' . (!empty($flags['edited_by'])
      ? 'The listing changed since <strong>' . esc_html((string) $flags['edited_by']) . '</strong> edited this post. Check the text, or press Rewrite.'
      : 'The listing changed since this draft was written; it is rewritten on the next run.') . '</p></div>';
  }
  if (!empty($flags['fixes'])) {
    echo '<p class="description">' . esc_html(implode(' ', (array) $flags['fixes'])) . '</p>';
  }
  if (!empty($flags['needs'])) {
    echo '<div class="notice notice-info inline"><p>' . esc_html((string) $p['note']) . '</p></div>';
  }

  echo '<div class="ias-card"><h2>Preview</h2>';
  if ($text === '') {
    echo '<p class="description">' . esc_html(!empty($flags['gen_error']) ? 'No draft yet: ' . $flags['gen_error'] : 'No draft written yet — the next hourly run writes it, or press Write drafts now on the calendar.') . '</p>';
  } else {
    echo '<div class="ias-preview">' . esc_html($text) . '</div>';
    $media = (array) ($c['media'] ?? []);
    if (($media['kind'] ?? '') === 'video') {
      echo '<p><strong>Video:</strong> <a href="' . esc_url((string) $media['video_url']) . '" target="_blank" rel="noopener">' . esc_html((string) $media['video_url']) . '</a></p>';
    }
    if (!empty($media['images'])) {
      echo '<div class="ias-thumbs">';
      foreach ((array) $media['images'] as $img) {
        echo '<a href="' . esc_url((string) $img['url']) . '" target="_blank" rel="noopener"><img src="' . esc_url((string) $img['url']) . '" alt="' . esc_attr((string) $img['alt']) . '" title="' . esc_attr((string) $img['alt']) . '"></a>';
      }
      echo '</div><details><summary>' . count($media['images']) . ' picture(s): alt text and crops</summary><ol>';
      foreach ((array) $media['images'] as $img) {
        echo '<li>' . esc_html((string) $img['alt']) . (!empty($img['kind']) ? ' <span class="description">(' . esc_html((string) $img['kind']) . ')</span>' : '');
        foreach ((array) ($img['crops'] ?? []) as $name => $u) echo ' · <a href="' . esc_url((string) $u) . '" target="_blank" rel="noopener">' . esc_html((string) $name) . '</a>';
        echo '</li>';
      }
      echo '</ol></details>';
    }
    if (!empty($c['first_comment'])) {
      echo '<p style="margin-bottom:4px"><strong>First comment</strong></p><div class="ias-preview" style="font-size:13px">' . esc_html((string) $c['first_comment']) . '</div>';
    }
    $bits = [];
    if (!empty($c['template'])) $bits[] = 'template: ' . (ias_templates()[$c['template']][0] ?? $c['template']);
    if (!empty($c['voice'])) $bits[] = 'voice: ' . ias_division_label((string) $c['voice']);
    if (!empty($c['cta_kind'])) $bits[] = 'call to action: ' . (ias_cta_kinds()[$c['cta_kind']][0] ?? $c['cta_kind']);
    if (!empty($p['generated_at'])) $bits[] = ($c['generated_by'] ?? '') === 'edited' ? 'edited by ' . ($flags['edited_by'] ?? 'a person') : 'written ' . date_i18n('M j, g:i a', strtotime((string) $p['generated_at']));
    if ($bits) echo '<p class="description">' . esc_html(implode(' · ', $bits)) . '</p>';
  }
  echo '</div>';

  echo '<div class="ias-card"><h3>Do something with it</h3>';
  if (ias_post_editable($p) && $text !== '') {
    echo '<form method="post">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
      . '<input type="hidden" name="ias_cal_action" value="edit"><input type="hidden" name="ias_post" value="' . (int) $p['id'] . '">'
      . '<p><label for="ias-text"><strong>Text</strong></label><br><textarea id="ias-text" name="ias_text" rows="12" class="large-text" style="max-width:640px">' . esc_textarea($text) . '</textarea></p>';
    if (!empty($c['first_comment'])) {
      echo '<p><label for="ias-comment"><strong>First comment</strong></label><br><textarea id="ias-comment" name="ias_comment" rows="3" class="large-text" style="max-width:640px">' . esc_textarea((string) $c['first_comment']) . '</textarea></p>';
    }
    echo '<p class="description">Saved text is checked again (bidding, figures, fair housing). A roundup or Results post that was approved needs approving again once its words change.</p>'
      . '<p><button class="button button-primary">Save text</button></p></form>';
  }
  if (in_array($p['status'], ['pushed', 'published'], true)) {
    echo '<p class="description">This post has gone to OneUp. Live posts are never edited — editing resets their reach — so a correction is posted as a comment.</p>';
  }
  if ($p['status'] === 'held' && $text !== '') echo $form('release', 'Keep as written', 'button', '', 'Keep this draft exactly as it is, despite the reasons it stopped?');
  if (ias_post_editable($p) && $p['status'] !== 'skipped') echo $form('regenerate', $text === '' ? 'Write it now' : 'Rewrite', 'button', '', $text === '' ? '' : 'Throw away this draft and have Claude write a new one? (Counts against the monthly Claude cap.)');
  if (in_array($p['status'], ['planned', 'held', 'waiting', 'approved', 'dropped'], true) && $p['type'] !== 'human') {
    echo $form('skip', 'Skip this post', 'button', '<input type="text" name="ias_why" placeholder="Why (optional)" class="regular-text" style="width:180px;margin-right:4px">');
  }
  if (in_array($p['status'], ['skipped', 'cancelled', 'dropped'], true)) echo $form('restore', 'Restore to the plan', 'button');
  /** Phase 4 adds Approve here. */
  do_action('ias_post_view_actions', $p, $form);
  echo '</div>';
});

/* ---------------- Under the grid ---------------- */

add_action('ias_calendar_after_grid', function (array $posts_in_range): void {
  $held    = ias_posts_query(['status' => 'held', 'limit' => 100]);
  $planned = ias_posts_query(['status' => 'planned', 'limit' => 1000]);
  $photos  = array_values(array_filter($planned, function ($p) { return !empty($p['flags']['needs']); }));
  $nodraft = array_values(array_filter($planned, function ($p) { return empty($p['flags']['needs']) && empty($p['content']['text']); }));

  $list = function (string $title, array $rows, string $empty, string $why = '') {
    echo '<div class="ias-card"><h3>' . esc_html($title) . ' <span class="description">(' . count($rows) . ')</span></h3>';
    if (!$rows) { echo '<p class="description">' . esc_html($empty) . '</p></div>'; return; }
    echo '<ul style="margin:0">';
    foreach (array_slice($rows, 0, 25) as $p) {
      $when = ias_post_when($p);
      echo '<li><a href="' . esc_url(ias_admin_url(IAS_PAGE_CALENDAR, ['post' => (int) $p['id']])) . '"><strong>' . esc_html(ias_post_type((string) $p['type'])['short']) . '</strong> · ' . esc_html(ias_post_subject($p)) . '</a>'
        . ($when ? ' <span class="description">' . esc_html($when->format('D M j')) . '</span>' : '')
        . ($why === 'note' && $p['note'] !== '' ? '<br><span class="description">' . esc_html((string) $p['note']) . '</span>' : '') . '</li>';
    }
    echo '</ul></div>';
  };

  $button = '<form method="post" style="display:inline">' . wp_nonce_field('ias_calendar', '_wpnonce', true, false)
    . '<input type="hidden" name="ias_cal_action" value="drafts_now"><button class="button" data-ia-busy="Writing…">Write drafts now</button></form>';

  echo '<div class="ias-cols" style="margin-top:16px"><div>';
  $list('Needs a decision', $held, 'Nothing is waiting on a decision.', 'note');
  echo '</div><div>';
  echo '<div class="ias-card"><h3>Drafts</h3><p>' . count($nodraft) . ' planned post' . (count($nodraft) === 1 ? ' has' : 's have') . ' no draft yet; a few are written every hour (Settings: drafts per run). '
    . $button . '</p><p class="description">Each draft is one Claude call, counted against the toolkit\'s monthly Claude cap.</p></div>';
  $list('Waiting for photos', $photos, 'Every Upcoming listing has photos.');
  echo '</div></div>';
});
