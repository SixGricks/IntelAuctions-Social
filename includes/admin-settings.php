<?php
/**
 * Intel Auctions Social — Auctions → Social Calendar → Settings.
 *
 * The OneUp key status, the safety switches, weights and multipliers, the
 * daily cap and posting windows, and who approves. One form, saved with a
 * nonce and POST → redirect → GET; every save is logged with what changed.
 */

if (!defined('ABSPATH')) exit;

/** "06:00" → minutes after midnight, or -1 when it is not a time. */
if (!function_exists('ias_hm_minutes')) {
  function ias_hm_minutes(string $hm): int {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hm), $m)) return -1;
    $h = (int) $m[1]; $i = (int) $m[2];
    return ($h > 23 || $i > 59) ? -1 : $h * 60 + $i;
  }
}

/** Minutes after midnight → "06:00". */
if (!function_exists('ias_minutes_hm')) {
  function ias_minutes_hm(int $minutes): string {
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
  }
}

/**
 * Clean a posted settings form into the stored shape. Returns [settings,
 * problems]; a problem keeps the old value for that field.
 */
if (!function_exists('ias_settings_sanitize')) {
  function ias_settings_sanitize(array $in, array $old): array {
    $out = [];
    $problems = [];
    $int = function (string $key, int $min, int $max) use ($in, $old, &$out, &$problems) {
      if (!isset($in[$key]) || $in[$key] === '') return;
      $v = (int) $in[$key];
      if ($v < $min || $v > $max) {
        $problems[] = $key . ' must be between ' . $min . ' and ' . $max . ' (kept ' . $old[$key] . ').';
        return;
      }
      $out[$key] = $v;
    };

    foreach (['master', 'dry_run', 'paused', 'link_in_comment'] as $key) $out[$key] = !empty($in[$key]);
    $types = [];
    foreach (array_keys(ias_settings_defaults()['types']) as $type) $types[$type] = !empty($in['types'][$type]);
    $out['types'] = $types;

    $weights = [];
    foreach (array_keys(ias_settings_defaults()['weights']) as $type) {
      $v = isset($in['weights'][$type]) ? (int) $in['weights'][$type] : (int) $old['weights'][$type];
      $weights[$type] = max(1, min(1000, $v));
    }
    $out['weights'] = $weights;

    // Starting-bid tiers: rows of [min bid, multiplier], highest first, the last catching everything.
    $tiers = [];
    foreach ((array) ($in['bid_tiers'] ?? []) as $row) {
      $min = isset($row[0]) ? (int) preg_replace('/[^0-9]/', '', (string) $row[0]) : -1;
      $mul = isset($row[1]) ? (float) $row[1] : 0;
      if ($min < 0 || (string) ($row[0] ?? '') === '' || $mul <= 0) continue;
      $tiers[] = [$min, round(min(5, max(0.1, $mul)), 2)];
    }
    if ($tiers) {
      usort($tiers, function ($a, $b) { return $b[0] <=> $a[0]; });
      if (end($tiers)[0] !== 0) $problems[] = 'The last starting-bid row should start at $0 so every listing gets a multiplier — listings below it count as ×1.0.';
      $out['bid_tiers'] = $tiers;
    }

    $int('daily_cap', 1, 10);
    $int('daily_min', 0, 10);
    $int('min_gap_hours', 0, 12);
    $int('lead_hours', 0, 168);
    $int('plan_days', 7, 60);
    $int('push_days', 1, 60);
    $int('plan_hour', 0, 23);
    $int('jitter_minutes', 0, 59);
    $int('review_day', 1, 6); // never on Sunday
    $int('review_hour', 0, 23);
    $int('generate_per_run', 0, 30);

    // Posting windows: rows of start–end, each a real time with start before end.
    $slots = [];
    foreach ((array) ($in['slots'] ?? []) as $row) {
      $a = ias_hm_minutes((string) ($row[0] ?? ''));
      $b = ias_hm_minutes((string) ($row[1] ?? ''));
      if ($a < 0 && $b < 0) continue;
      if ($a < 0 || $b < 0 || $b <= $a) { $problems[] = 'A posting window needs a start before its end, like 06:00 and 07:30.'; continue; }
      $slots[] = [ias_minutes_hm($a), ias_minutes_hm($b)];
    }
    if ($slots) {
      usort($slots, function ($x, $y) { return strcmp($x[0], $y[0]); });
      $out['slots'] = $slots;
    } else {
      $problems[] = 'At least one posting window is needed (kept the old ones).';
    }

    $out['approvers'] = implode(', ', array_filter(array_map(function ($e) {
      $e = sanitize_email($e);
      return is_email($e) ? strtolower($e) : '';
    }, preg_split('/[\s,;]+/', (string) ($in['approvers'] ?? '')))));
    if ($out['approvers'] === '') $problems[] = 'With no approver, Lane A posts (the roundup and Results) wait on the calendar with no email going out.';

    $out['oneup_category_id']  = sanitize_text_field((string) ($in['oneup_category_id'] ?? ''));
    $out['oneup_account_id']   = sanitize_text_field((string) ($in['oneup_account_id'] ?? ''));
    $out['oneup_calendar_url'] = esc_url_raw((string) ($in['oneup_calendar_url'] ?? ''));
    $out['utm_source'] = sanitize_key((string) ($in['utm_source'] ?? 'facebook')) ?: 'facebook';
    $out['utm_medium'] = sanitize_key((string) ($in['utm_medium'] ?? 'social')) ?: 'social';

    $out['brand_font_id'] = max(0, (int) ($in['brand_font_id'] ?? 0));
    $out['fair_housing_extra'] = sanitize_textarea_field((string) ($in['fair_housing_extra'] ?? ''));
    $grade = (string) ($in['grade_size'] ?? 'medium');
    $out['grade_size'] = in_array($grade, ['thumbnail', 'medium', 'medium_large'], true) ? $grade : 'medium';

    /** Later phases clean their own fields. */
    $out = (array) apply_filters('ias_settings_sanitize', $out, $in, $old);

    $daily_cap = $out['daily_cap'] ?? $old['daily_cap'];
    if (($out['daily_min'] ?? $old['daily_min']) > $daily_cap) $out['daily_min'] = $daily_cap;
    $plan_days = $out['plan_days'] ?? $old['plan_days'];
    if (($out['push_days'] ?? $old['push_days']) > $plan_days) $out['push_days'] = $plan_days;
    if (($out['lead_hours'] ?? $old['lead_hours']) < 48) {
      $problems[] = 'The veto window is under 48 hours — the spec promises DJ and Jacob two days to veto a scheduled post.';
    }
    return [$out, $problems];
  }
}

if (!function_exists('ias_settings_handle_post')) {
  function ias_settings_handle_post(): void {
    if (!isset($_POST['ias_settings_save']) || !current_user_can('manage_options')) return;
    check_admin_referer('ias_settings');
    $old = ias_settings();
    [$new, $problems] = ias_settings_sanitize(wp_unslash((array) ($_POST['ias'] ?? [])), $old);
    ias_settings_update($new);

    $changed = [];
    foreach ($new as $key => $value) {
      if (wp_json_encode($old[$key] ?? null) !== wp_json_encode($value)) $changed[] = $key;
    }
    if ($changed) ias_log('settings', 0, ['changed' => $changed], ias_actor());

    $html = '<div class="notice notice-success"><p>Settings saved' . ($changed ? ' (' . esc_html(implode(', ', $changed)) . ')' : ' — nothing changed') . '.</p></div>';
    if ($problems) {
      $html .= '<div class="notice notice-warning"><p>' . implode('<br>', array_map('esc_html', $problems)) . '</p></div>';
    }
    ia_flash_set($html);
    wp_safe_redirect(ias_admin_url(IAS_PAGE_SETTINGS));
    exit;
  }
}
add_action('admin_init', 'ias_settings_handle_post');

if (!function_exists('ias_settings_render')) {
  function ias_settings_render(): void {
    if (!current_user_can('manage_options')) return;
    ias_admin_styles();
    $s = ias_settings();
    ia_ui_header('Social Settings', 'Everything that can make a post public starts <strong>off</strong>. Turn on the master switch and one post type at a time, Listing posts first.');
    echo ias_switches_strip();

    $check = function (string $name, bool $on, string $label, string $help = '') {
      return '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($on, true, false) . '> ' . $label . '</label>'
        . ($help !== '' ? '<br><span class="description">' . $help . '</span>' : '');
    };
    $num = function (string $name, $value, int $min, int $max, string $after = '') {
      $value = max($min, min($max, (int) $value)); // a stored value out of range must never make the form unsaveable
      return '<input type="number" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" min="' . $min . '" max="' . $max . '" class="small-text"> ' . $after;
    };

    echo '<form method="post">';
    wp_nonce_field('ias_settings');
    echo '<input type="hidden" name="ias_settings_save" value="1">';

    echo '<h2>Safety switches</h2><table class="form-table" role="presentation">';
    echo '<tr><th scope="row">Master switch</th><td>' . $check('ias[master]', !empty($s['master']), '<strong>On</strong> — let the plugin call OneUp', 'Off means the plugin plans, drafts and logs, and never calls OneUp. Live pushing arrives in Phase 5; until then nothing is sent whatever this says.') . '</td></tr>';
    echo '<tr><th scope="row">Dry run</th><td>' . $check('ias[dry_run]', !empty($s['dry_run']), 'Write the exact payloads it would send to the <a href="' . esc_url(ias_admin_url(IAS_PAGE_LOG, ['event' => 'dryrun'])) . '">log</a> instead of sending them') . '</td></tr>';
    echo '<tr><th scope="row">Paused</th><td>' . $check('ias[paused]', !empty($s['paused']), 'Stop every push at once (the same as the Pause button on the calendar)') . '</td></tr>';
    echo '<tr><th scope="row">Post types</th><td>';
    foreach (ias_post_types() as $type => $def) {
      if ($type === 'human') continue;
      echo $check('ias[types][' . $type . ']', ias_type_on($type), '<strong>' . esc_html($def['label']) . '</strong> <span class="description">— lane ' . esc_html($def['lane']) . ', ' . esc_html($def['window']) . '</span>') . '<br>';
    }
    echo $check('ias[types][events]', ias_type_on('events'), '<strong>Facebook Event cards</strong> <span class="description">— a ready-to-copy event for each open house and auction day</span>');
    echo '<p class="description">A type that is off is still planned and drafted, so you can read it on the calendar; it just never goes to OneUp.</p></td></tr>';
    echo '</table>';

    $key = ias_oneup_key();
    echo '<h2>OneUp</h2><table class="form-table" role="presentation">';
    echo '<tr><th scope="row">API key</th><td>' . ($key !== ''
      ? ia_ui_badge('Set in wp-config', 'ok') . ' <code>…' . esc_html(substr($key, -4)) . '</code>'
      : ia_ui_badge('Not set', 'warn') . '<br><span class="description">Add <code>define(\'INTEL_ONEUP_API_KEY\', \'…\');</code> to wp-config.php (from oneupapp.io → API access). It is never stored in the database.</span>') . '</td></tr>';
    echo '<tr><th scope="row"><label for="ias-cat">Category id</label></th><td><input id="ias-cat" type="text" name="ias[oneup_category_id]" value="' . esc_attr((string) $s['oneup_category_id']) . '" class="regular-text"><br><span class="description">The OneUp category that holds the Intel Facebook page.</span></td></tr>';
    echo '<tr><th scope="row"><label for="ias-acct">Facebook account id</label></th><td><input id="ias-acct" type="text" name="ias[oneup_account_id]" value="' . esc_attr((string) $s['oneup_account_id']) . '" class="regular-text"><br><span class="description">The page\'s <code>social_network_id</code> in that category. Facebook is the only channel at launch.</span></td></tr>';
    echo '<tr><th scope="row"><label for="ias-calurl">Calendar link</label></th><td><input id="ias-calurl" type="url" name="ias[oneup_calendar_url]" value="' . esc_attr((string) $s['oneup_calendar_url']) . '" class="regular-text"><br><span class="description">Where the Monday email\'s "Open the OneUp calendar" button goes.</span></td></tr>';
    echo '<tr><th scope="row">Listing link</th><td>' . $check('ias[link_in_comment]', !empty($s['link_in_comment']), 'Put the link in the first comment', 'Facebook shows posts with a link in them to fewer people. Untick only if OneUp cannot post first comments: the link then goes at the end of the post.') . '</td></tr>';
    echo '</table>';

    echo '<h2>Priority</h2><p class="description">Priority = the post type\'s weight × the starting-bid multiplier. When a day is full, the lowest priority moves first; Listing, Results and the roundup are never dropped whatever their priority.</p>';
    echo '<table class="form-table" role="presentation"><tr><th scope="row">Weights</th><td>';
    foreach ($s['weights'] as $type => $w) {
      echo '<label style="display:inline-block;margin:0 14px 6px 0">' . esc_html(ias_post_type($type)['label']) . ' ' . $num('ias[weights][' . $type . ']', (int) $w, 1, 1000) . '</label>';
    }
    echo '</td></tr><tr><th scope="row">Starting-bid multiplier</th><td><table><thead><tr><th style="text-align:left">Starting bid from</th><th style="text-align:left">Multiplier</th></tr></thead><tbody>';
    $tiers = array_values((array) $s['bid_tiers']);
    for ($i = 0; $i < max(4, count($tiers) + 1); $i++) {
      $row = $tiers[$i] ?? ['', ''];
      echo '<tr><td>$ <input type="text" name="ias[bid_tiers][' . $i . '][0]" value="' . esc_attr($row[0] === '' ? '' : number_format((int) $row[0])) . '" class="regular-text" style="width:120px"></td>'
        . '<td>× <input type="number" step="0.05" min="0.1" max="5" name="ias[bid_tiers][' . $i . '][1]" value="' . esc_attr((string) $row[1]) . '" class="small-text"></td></tr>';
    }
    echo '</tbody></table><span class="description">No starting bid listed counts as $0. Leave a row empty to remove it.</span></td></tr></table>';

    echo '<h2>Limits and posting windows</h2><table class="form-table" role="presentation">';
    echo '<tr><th scope="row">Posts a day</th><td>at most ' . $num('ias[daily_cap]', $s['daily_cap'], 1, 10) . ', at least ' . $num('ias[daily_min]', $s['daily_min'], 0, 10) . ' when posts can be pulled in, ' . $num('ias[min_gap_hours]', $s['min_gap_hours'], 0, 12, 'hours apart') . '<br><span class="description">Never on Sunday — that is a rule, not a setting.</span></td></tr>';
    echo '<tr><th scope="row">Posting windows</th><td>';
    $slots = array_values((array) $s['slots']);
    for ($i = 0; $i < max(3, count($slots)); $i++) {
      $row = $slots[$i] ?? ['', ''];
      echo '<div style="margin-bottom:4px"><input type="time" name="ias[slots][' . $i . '][0]" value="' . esc_attr((string) $row[0]) . '"> – <input type="time" name="ias[slots][' . $i . '][1]" value="' . esc_attr((string) $row[1]) . '"></div>';
    }
    echo '<span class="description">Each post time shifts by up to ' . $num('ias[jitter_minutes]', $s['jitter_minutes'], 0, 59, 'minutes') . ' so nothing lands on the hour.</span></td></tr>';
    echo '<tr><th scope="row">Veto window</th><td>' . $num('ias[lead_hours]', $s['lead_hours'], 0, 168, 'hours') . '<br><span class="description">Nothing new is scheduled closer than this, so either of you can edit, move or delete it in OneUp first. Approved roundup and Results posts are not held to it — the approval was the yes.</span></td></tr>';
    echo '<tr><th scope="row">Planning</th><td>Re-plan the next ' . $num('ias[plan_days]', $s['plan_days'], 7, 60, 'days') . ' every morning from ' . $num('ias[plan_hour]', $s['plan_hour'], 0, 23, ':00') . ', and push the next ' . $num('ias[push_days]', $s['push_days'], 1, 60, 'days') . ' to OneUp.</td></tr>';
    echo '</table>';

    echo '<h2>Approvals and the weekly email</h2><table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="ias-appr">Approvers</label></th><td><input id="ias-appr" type="text" name="ias[approvers]" value="' . esc_attr((string) $s['approvers']) . '" class="large-text"><br><span class="description">Who gets the roundup and Results posts to approve (Lane A), the morning reminders and the Monday email. The first to approve wins.</span></td></tr>';
    $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday']; // never Sunday
    echo '<tr><th scope="row">Weekly review email</th><td><select name="ias[review_day]">';
    foreach ($days as $n => $name) echo '<option value="' . $n . '" ' . selected((int) $s['review_day'], $n, false) . '>' . esc_html($name) . '</option>';
    echo '</select> at ' . $num('ias[review_hour]', $s['review_hour'], 0, 23, ':00') . '</td></tr>';
    echo '</table>';

    echo '<h2>Links back to the site</h2><table class="form-table" role="presentation">';
    echo '<tr><th scope="row">Tracking tags</th><td><code>utm_source=</code><input type="text" name="ias[utm_source]" value="' . esc_attr((string) $s['utm_source']) . '" class="small-text" style="width:90px"> <code>utm_medium=</code><input type="text" name="ias[utm_medium]" value="' . esc_attr((string) $s['utm_medium']) . '" class="small-text" style="width:90px">'
      . '<br><span class="description">Every link also carries <code>utm_campaign=social_&lt;post type&gt;</code> and the post\'s id in <code>utm_content</code>, so Analytics can tell a Listing post\'s visits from a Results post\'s.</span></td></tr>';
    echo '</table>';

    echo '<h2>Copy and pictures</h2><table class="form-table" role="presentation">';
    $font = (int) $s['brand_font_id'];
    $font_file = $font > 0 ? (string) get_attached_file($font) : '';
    echo '<tr><th scope="row"><label for="ias-font">Brand font</label></th><td><input id="ias-font" type="number" min="0" name="ias[brand_font_id]" value="' . ($font > 0 ? $font : '') . '" class="small-text"> '
      . ($font > 0 ? ($font_file !== '' && preg_match('/\.(ttf|otf)$/i', $font_file) ? ia_ui_badge(basename($font_file), 'ok') : ia_ui_badge('not a .ttf/.otf file — using Inter', 'warn')) : ia_ui_badge('Inter (bundled)', 'muted'))
      . '<br><span class="description">The Media Library id of a .ttf or .otf file for the Results graphic and the roundup collage. Blank uses the bundled Inter. (WordPress may need the font file types allowed to upload them.)</span></td></tr>';
    echo '<tr><th scope="row"><label for="ias-fh">More fair-housing phrases</label></th><td><textarea id="ias-fh" name="ias[fair_housing_extra]" rows="4" class="large-text">' . esc_textarea((string) $s['fair_housing_extra']) . '</textarea>'
      . '<br><span class="description">One per line. A draft containing one stops for a decision, like "perfect for families" or "safe neighborhood" already do.</span></td></tr>';
    echo '<tr><th scope="row">Photo grading</th><td><select name="ias[grade_size]">';
    foreach (['thumbnail' => 'Thumbnail (cheapest, rough)', 'medium' => 'Medium — 300px (default)', 'medium_large' => 'Medium large — 768px (about 6× the tokens)'] as $k => $label) {
      echo '<option value="' . esc_attr($k) . '" ' . selected((string) $s['grade_size'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select><br><span class="description">Claude looks at each photo once, at this size, to score it and tag its kind.</span></td></tr>';
    echo '<tr><th scope="row">Drafts per run</th><td>' . $num('ias[generate_per_run]', $s['generate_per_run'], 0, 30, 'drafts an hour at most') . '<br><span class="description">Each is one Claude call against the toolkit\'s monthly cap. 0 stops new drafts.</span></td></tr>';
    echo '</table>';

    /** Later phases add their sections here. */
    do_action('ias_settings_sections', $s);

    submit_button('Save settings');
    echo '</form>';
    /** Later phases add forms of their own below the settings (the OneUp connection check). */
    do_action('ias_settings_after_form');
    echo '</div>';
  }
}
