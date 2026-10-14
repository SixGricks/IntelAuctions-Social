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

    foreach (['master', 'paused', 'link_in_comment', 'voice_auto'] as $key) $out[$key] = !empty($in[$key]);
    $networks = [];
    foreach (array_keys(ias_settings_defaults()['networks']) as $ch) {
      $networks[$ch] = ['on' => !empty($in['networks'][$ch]['on']), 'account' => isset($in['networks'][$ch]['account']) ? substr(sanitize_text_field((string) $in['networks'][$ch]['account']), 0, 200) : (string) ($old['networks'][$ch]['account'] ?? '')];
      if ($ch === 'gbp') {
        $all = array_keys(ias_settings_defaults()['types']);
        $networks[$ch]['types'] = isset($in['networks'][$ch]['types']) ? array_values(array_intersect($all, array_map('strval', (array) $in['networks'][$ch]['types']))) : [];
        // The location's name in OneUp's lists (the read-back finds its posts by it): from the directory, never typed.
        $acct = function_exists('ias_oneup_directory_account') ? ias_oneup_directory_account($networks[$ch]['account']) : null;
        $networks[$ch]['name'] = $acct ? (string) $acct['name'] : ($networks[$ch]['account'] === (string) ($old['networks'][$ch]['account'] ?? '') ? (string) ($old['networks'][$ch]['name'] ?? '') : '');
      }
    }
    $out['networks'] = $networks;
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
    $int('chat_turns', 0, 100);
    $int('voice_examples', 0, 6);
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
    // The page's name in OneUp's lists is looked up (Look up accounts, Verify OneUp), never typed: a new id forgets it.
    $acct = function_exists('ias_oneup_directory_account') ? ias_oneup_directory_account($out['oneup_account_id'], $out['oneup_category_id']) : null;
    $out['oneup_account_name'] = $acct ? (string) $acct['name'] : ($out['oneup_account_id'] === (string) ($old['oneup_account_id'] ?? '') ? (string) ($old['oneup_account_name'] ?? '') : '');
    // An account OneUp lists under another category than the one chosen would be refused (a post names both).
    if (function_exists('ias_oneup_directory') && ias_oneup_directory() && $out['oneup_category_id'] !== '') {
      $where = ['Facebook page' => $out['oneup_account_id']];
      foreach (($out['networks'] ?? []) as $ch => $n) if ((string) $n['account'] !== '') $where[(string) ias_channel($ch)['label'] . ' account'] = (string) $n['account'];
      foreach ($where as $label => $id) {
        if ($id !== '' && ias_oneup_directory_account($id) && !ias_oneup_directory_account($id, $out['oneup_category_id'])) $problems[] = 'The ' . $label . ' chosen is not in the chosen OneUp category — choose them again after picking the category.';
      }
    }
    if (isset($in['oneup_calendar_url'])) $out['oneup_calendar_url'] = esc_url_raw((string) $in['oneup_calendar_url']); // no longer on the screen (0.10.0): nobody needs OneUp
    $out['utm_source'] = sanitize_key((string) ($in['utm_source'] ?? 'facebook')) ?: 'facebook';
    $out['utm_medium'] = sanitize_key((string) ($in['utm_medium'] ?? 'social')) ?: 'social';

    $out['brand_font_id'] = max(0, (int) ($in['brand_font_id'] ?? 0));
    $out['fair_housing_extra'] = sanitize_textarea_field((string) ($in['fair_housing_extra'] ?? ''));
    if (isset($in['comment_leads']) && is_array($in['comment_leads'])) {
      $leads = [];
      foreach (array_keys(ias_settings_defaults()['comment_leads']) as $k) $leads[$k] = mb_substr(sanitize_text_field((string) ($in['comment_leads'][$k] ?? '')), 0, 120);
      $out['comment_leads'] = $leads; // blank = the default (ias_comment_lead())
    }
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
    if (!empty($out['master']) && array_filter($out['types']) && function_exists('ias_oneup_verified') && !ias_oneup_verified()) {
      $problems[] = 'A post type is on, but nothing goes to OneUp until Verify OneUp (below) passes for this page.';
    }
    return [$out, $problems];
  }
}

/**
 * The choices for the OneUp fields, from the directory Look up accounts
 * keeps: field id => <option>s. $chosen: 'category', and each network's
 * account id ('facebook', 'instagram', …) — what is selected. An account is
 * offered only to the network of its type, grouped by category (the page
 * hides the other categories'); a saved id OneUp did not list stays, marked;
 * a category with exactly one account of a network's type offers it ready
 * chosen when nothing is saved — still only saved by Save settings.
 */
if (!function_exists('ias_oneup_pick_options')) {
  function ias_oneup_pick_options(array $chosen): array {
    $dir = ias_oneup_directory();
    $cats = (array) ($dir['categories'] ?? []);
    $cat = (string) ($chosen['category'] ?? '');
    $opt = function (string $value, string $label, bool $sel, string $extra = '') {
      return '<option value="' . esc_attr($value) . '"' . ($sel ? ' selected' : '') . $extra . '>' . esc_html($label) . '</option>';
    };
    $out = [];
    $html = $opt('', $cats ? '— choose the category —' : '— look up accounts first —', $cat === '');
    $known = false;
    foreach ($cats as $c) {
      $types = array_values(array_unique(array_filter(array_map(function ($a) { return (string) $a['type']; }, (array) $c['accounts']))));
      $html .= $opt((string) $c['id'], ($c['name'] !== '' ? $c['name'] : 'Category ' . $c['id']) . ' — ' . count((array) $c['accounts']) . ' account' . (count((array) $c['accounts']) === 1 ? '' : 's') . ($types ? ': ' . implode(', ', $types) : ''), (string) $c['id'] === $cat);
      if ((string) $c['id'] === $cat) $known = true;
    }
    if ($cat !== '' && !$known) $html .= $opt($cat, 'Category ' . $cat . ' (saved — not in OneUp\'s list)', true);
    $out['ias-cat'] = $html;

    foreach (array_keys(ias_channels()) as $ch) {
      $id = (string) ($chosen[$ch] ?? '');
      $label = (string) ias_channel($ch)['label'];
      $groups = '';
      $found = false;
      $sole = '';
      foreach ($cats as $c) {
        $mine = array_values(array_filter((array) $c['accounts'], function ($a) use ($ch) { return ias_oneup_type_is($ch, (string) $a['type']); }));
        if (!$mine) continue;
        if ((string) $c['id'] === $cat && count($mine) === 1) $sole = (string) $mine[0]['id'];
        $items = '';
        foreach ($mine as $a) {
          $items .= $opt((string) $a['id'], (string) ($a['name'] !== '' ? $a['name'] : $a['id']), false);
          if ((string) $a['id'] === $id && ((string) $c['id'] === $cat || $cat === '')) $found = true;
        }
        $groups .= '<optgroup label="' . esc_attr($c['name'] !== '' ? $c['name'] : 'Category ' . $c['id']) . '" data-cat="' . esc_attr((string) $c['id']) . '">' . $items . '</optgroup>';
      }
      $pick = $id !== '' ? $id : $sole;
      // Mark the one chosen inside its own category's group (the same account can sit in two categories).
      if ($pick !== '') {
        $groups = preg_replace_callback('~(<optgroup [^>]*data-cat="([^"]*)">)(.*?)</optgroup>~s', function ($m) use ($pick, $cat) {
          if ($cat !== '' && $m[2] !== esc_attr($cat)) return $m[0];
          return $m[1] . str_replace('<option value="' . esc_attr($pick) . '">', '<option value="' . esc_attr($pick) . '" selected>', $m[3]) . '</optgroup>';
        }, $groups);
      }
      $none = $ch === 'facebook' ? '— choose the Facebook page —' : '— none —';
      $html = $opt('', $none, $pick === '');
      if ($id !== '' && !$found) {
        $acct = ias_oneup_directory_account($id);
        $html .= $opt($id, ($acct ? $acct['name'] . ' (in another category)' : $label . ' account ' . $id . ' (saved — not in OneUp\'s list)'), true);
      }
      $out[$ch === 'facebook' ? 'ias-acct' : 'ias-net-' . $ch] = $html . $groups;
    }
    return $out;
  }
}

/** A line about the last lookup, for under the OneUp fields. */
if (!function_exists('ias_oneup_directory_line')) {
  function ias_oneup_directory_line(): string {
    $dir = ias_oneup_directory();
    if (!$dir) return 'Not looked up yet: press <strong>Look up accounts</strong> and OneUp\'s categories and accounts appear in the lists to choose from.';
    $n = 0;
    foreach ((array) $dir['categories'] as $c) $n += count((array) $c['accounts']);
    $line = 'From OneUp ' . esc_html(date_i18n('D M j, g:i a', strtotime((string) $dir['at']))) . ': ' . count((array) $dir['categories']) . ' categor' . (count((array) $dir['categories']) === 1 ? 'y' : 'ies') . ', ' . $n . ' account' . ($n === 1 ? '' : 's') . '.';
    if (!empty($dir['problems'])) $line .= ' <strong>' . esc_html(ucfirst(implode('; ', (array) $dir['problems']))) . '.</strong>';
    return $line;
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
    // The OneUp key: its own option (not autoloaded), blank keeps it; never logged, only that it changed.
    if (ias_oneup_key_source() !== 'constant') {
      $key = trim(sanitize_text_field((string) wp_unslash($_POST['ias_oneup_key_new'] ?? '')));
      if ($key !== '' && $key !== (string) get_option('ias_oneup_api_key', '')) { update_option('ias_oneup_api_key', $key, false); $changed[] = 'OneUp key'; }
      elseif ($key === '' && !empty($_POST['ias_oneup_key_clear']) && get_option('ias_oneup_api_key', '') !== '') { delete_option('ias_oneup_api_key'); $changed[] = 'OneUp key removed'; }
    }
    if ($changed) ias_log('settings', 0, ['changed' => $changed], ias_actor());
    if (function_exists('ias_dry_run_switched_off') && ias_dry_run_switched_off()) update_option('ias_dry_run_retired', ['off' => []] + (array) get_option('ias_dry_run_retired', []), false); // the notice has been read

    $html = '<div class="notice notice-success"><p>Settings saved' . ($changed ? ' (' . esc_html(implode(', ', $changed)) . ')' : ' — nothing changed') . '.</p></div>';
    // A switch is on or off, and the change goes to OneUp now: what was switched off comes back off, what was switched on goes.
    $switches = function (array $x) { return wp_json_encode([!empty($x['master']), !empty($x['paused']), (array) ($x['types'] ?? []), !empty($x['networks']['gbp']['on']), (array) ($x['networks']['gbp']['types'] ?? [])]); };
    if ($switches($old) !== $switches(ias_settings()) && function_exists('ias_push_switched')) {
      $words = ias_push_switched_words(ias_push_switched());
      if ($words !== '') $html .= '<div class="notice notice-info"><p><strong>OneUp</strong> — ' . esc_html($words) . '</p></div>';
    }
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
    ias_header_end();
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
    echo '<tr><th scope="row">Master switch</th><td>' . $check('ias[master]', !empty($s['master']), '<strong>On</strong> — post: each post type switched on below goes to OneUp', 'Every switch is on or off — there is no rehearsal mode. On, the posts of each type that is on are scheduled on OneUp for real (once Verify OneUp has passed), and the plugin reads back what OneUp holds every hour. Off, nothing goes: posts already on OneUp and not yet out are taken back off, and then the plugin makes no call to OneUp at all.') . '</td></tr>';
    echo '<tr><th scope="row">Paused</th><td>' . $check('ias[paused]', !empty($s['paused']), 'Pause all posting (the same as the Pause button on the calendar)', 'Everything off for now: posts on OneUp and not yet out are taken back off, and go again when you resume. Facebook stays with this plugin while paused.') . '</td></tr>';
    echo '<tr><th scope="row">Post types</th><td>';
    foreach (ias_post_types() as $type => $def) {
      if ($type === 'human') continue;
      echo $check('ias[types][' . $type . ']', ias_type_on($type), '<strong>' . esc_html($def['short']) . '</strong> (' . esc_html($def['label']) . ') <span class="description">— ' . esc_html($def['lane'] === 'A' ? 'approve first' : 'scheduled, ' . (int) ias_setting('lead_hours', 48) . ' hours to veto') . ', ' . esc_html($def['window']) . '</span>') . '<br>';
    }
    echo '<p class="description">On, that type\'s posts go to OneUp. Off, none of them goes — any already on OneUp come back off — but they are still planned and drafted, so you can read them on the calendar.</p></td></tr>';
    echo '</table>';
    submit_button('Save settings', 'primary', 'submit-top'); // the switches above, saved without scrolling a long page

    $key = ias_oneup_key();
    $src = ias_oneup_key_source();
    echo '<h2>OneUp</h2><table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="ias-oneup-key">API key</label></th><td>';
    if ($src === 'constant') {
      echo ia_ui_badge('Set in wp-config', 'ok') . ' <code>…' . esc_html(substr($key, -4)) . '</code><br><span class="description"><code>INTEL_ONEUP_API_KEY</code> in wp-config.php wins over a key saved here.</span>';
    } else {
      // Never echo the saved key back into the page.
      echo ($src === 'option' ? ia_ui_badge('Saved', 'ok') . ' <span class="description">ends <code>…' . esc_html(substr($key, -4)) . '</code></span><br>' : ia_ui_badge('Not set', 'warn') . '<br>')
        . '<input type="password" id="ias-oneup-key" name="ias_oneup_key_new" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr($src === 'option' ? 'paste a new key to replace — blank keeps it' : 'from oneupapp.io → API access') . '">'
        . ($src === 'option' ? ' <label><input type="checkbox" name="ias_oneup_key_clear" value="1"> remove key</label>' : '')
        . '<br><span class="description">From oneupapp.io → API access. Saved for this site only, never shown again or written to the log. A new key means pressing <strong>Verify OneUp</strong> again before anything is sent. (Or set <code>INTEL_ONEUP_API_KEY</code> in wp-config.php, which wins.)</span>';
    }
    echo '</td></tr>';
    $chosen = ['category' => (string) $s['oneup_category_id'], 'facebook' => (string) $s['oneup_account_id']];
    foreach (array_keys(ias_channels()) as $ch) if ($ch !== 'facebook') $chosen[$ch] = ias_channel_settings($ch)['account'];
    $pick = ias_oneup_pick_options($chosen);
    $can = (bool) ias_setting('master', false) && ias_oneup_key() !== '';
    echo '<tr><th scope="row">Accounts</th><td id="ias-oneup-pick"><button type="submit" form="ias-oneup-lookup-form" class="button" id="ias-lookup"' . ($can ? '' : ' disabled title="' . esc_attr(!ias_setting('master', false) ? 'Turn the master switch on and save first — while it is off, the plugin never asks OneUp anything' : 'Add the OneUp key and save first') . '"') . ' data-ia-busy="Asking OneUp…">Look up accounts</button>'
      . ' <span class="description" id="ias-oneup-found">' . ias_oneup_directory_line() . '</span>'
      . '<br><span class="description">Asks OneUp for its categories and the accounts in each — read only, nothing changes there. Then choose below and press <strong>Save settings</strong>.' . ($can ? '' : ' <strong>' . esc_html(!ias_setting('master', false) ? 'The master switch must be on (nothing posts while every post type is off).' : 'The OneUp key is needed.') . '</strong>') . '</span></td></tr>';
    echo '<tr><th scope="row"><label for="ias-cat">Category</label></th><td><select id="ias-cat" name="ias[oneup_category_id]" class="ias-pick">' . $pick['ias-cat'] . '</select><br><span class="description">The OneUp category that holds the Intel Facebook page — and the other accounts posts go to.</span></td></tr>';
    echo '<tr><th scope="row"><label for="ias-acct">Facebook page</label></th><td><select id="ias-acct" name="ias[oneup_account_id]" class="ias-pick ias-acct-pick">' . $pick['ias-acct'] . '</select>'
      . ((string) $s['oneup_account_name'] !== '' && !ias_oneup_directory_account((string) $s['oneup_account_id']) ? ' ' . ia_ui_badge((string) $s['oneup_account_name'], 'ok') : '') // the list names it already
      . '<br><span class="description">A different page (or category) means pressing <strong>Verify OneUp</strong> again before anything is sent.</span></td></tr>';
    echo '<tr><th scope="row">Listing link</th><td>' . $check('ias[link_in_comment]', !empty($s['link_in_comment']), 'Put the link in the first comment', 'Facebook shows posts with a link in them to fewer people. Untick only if OneUp cannot post first comments: the link then goes at the end of the post.') . '</td></tr>';
    echo '</table>';

    // Google Business Profile: one switch, sent once its own Verify has passed.
    $g = ias_channel_settings('gbp');
    $gstate = function_exists('ias_gbp_blockers') ? ias_gbp_blockers() : [];
    echo '<h2 id="ias-gbp">Google Business Profile</h2><p class="description" style="max-width:760px">Each post can also go to the Intel profile on Google, through OneUp: the Facebook words without hashtags or phone numbers (Google refuses a post with a phone number in it — the profile has its own Call button), at most 1,500 characters, one picture and a <strong>Learn more</strong> button to the listing. It goes out a minute after the Facebook post. Off, nothing goes to Google and any Google posts already on OneUp come back off.</p><table class="form-table" role="presentation">';
    echo '<tr><th scope="row">Google posts</th><td>' . $check('ias[networks][gbp][on]', $g['on'], '<strong>On</strong> — post to Google Business Profile',
      'Needs the master switch on and <a href="#ias-verify-gbp">Verify for Google</a> passed for this location. ' . ($g['on'] && !$gstate ? ia_ui_badge('Posting', 'ok') : ($g['on'] ? ia_ui_badge('Not yet', 'warn') . ' ' . esc_html(ucfirst(implode('; ', $gstate))) . '.' : ''))) . '</td></tr>';
    echo '<tr><th scope="row"><label for="ias-net-gbp">Location</label></th><td><select id="ias-net-gbp" name="ias[networks][gbp][account]" class="ias-pick ias-acct-pick">' . $pick['ias-net-gbp'] . '</select>'
      . ((string) ($g['name'] ?? '') !== '' && !ias_oneup_directory_account($g['account']) ? ' ' . ia_ui_badge((string) $g['name'], 'ok') : '') . '<br><span class="description">The Google Business Profile location in the category above.</span></td></tr>';
    echo '<tr><th scope="row">Which posts</th><td>';
    foreach (ias_post_types() as $type => $def) {
      if ($type === 'human') continue;
      echo '<label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="ias[networks][gbp][types][]" value="' . esc_attr($type) . '" ' . checked(in_array($type, $g['types'], true), true, false) . '> ' . esc_html($def['short']) . '</label>';
    }
    echo '<br><span class="description">Each type still needs its own switch on (Safety switches, above) before it goes anywhere.</span></td></tr>';
    echo '</table>';

    echo '<h2>More networks</h2><p class="description" style="max-width:760px">Instagram, X and LinkedIn are set up but cannot post yet — that needs a later release giving each one its own Verify. Tick <strong>Show a preview</strong> to see, on each post\'s page, the words and pictures it would get. A preview is not a switch: nothing goes to these networks. Choose each one\'s account from the OneUp category above.</p><table class="form-table" role="presentation">';
    foreach (ias_channels() as $ch => $rules) {
      if (in_array($ch, ['facebook', 'gbp'], true)) continue;
      $n = (array) (($s['networks'] ?? [])[$ch] ?? []);
      $what = [
        'instagram' => 'The Facebook words with “link in our bio” (Instagram captions carry no links), square pictures, up to 10.',
        'x'         => 'One short line made from the listing\'s facts, the link and up to 4 pictures, within 280 characters.',
        'linkedin'  => 'The Facebook words with the link at the end, up to 9 pictures.',
      ][$ch] ?? '';
      echo '<tr><th scope="row">' . esc_html((string) $rules['label']) . '</th><td>'
        . $check('ias[networks][' . $ch . '][on]', !empty($n['on']), 'Show a preview for ' . esc_html((string) $rules['label']) . ' <span class="description">(not posted)</span>')
        . '<br><label>Account <select id="ias-net-' . esc_attr($ch) . '" name="ias[networks][' . esc_attr($ch) . '][account]" class="ias-pick ias-acct-pick">' . $pick['ias-net-' . $ch] . '</select></label>'
        . '<br><span class="description">' . esc_html($what) . '</span></td></tr>';
    }
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
    echo '<tr><th scope="row">Veto window</th><td>' . $num('ias[lead_hours]', $s['lead_hours'], 0, 168, 'hours') . '<br><span class="description">Nothing new is scheduled closer than this, so either of you can skip, move or change it on the calendar first. Approved roundup and Results posts are not held to it — the approval was the yes — and neither is a post a person moved there themselves.</span></td></tr>';
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
    $lead_labels = ['listing' => 'Listing and Auction Week', 'open_house' => 'Open House', 'results' => 'Results', 'several' => 'A post about several properties', 'roundup' => 'New This Week'];
    $leads = (array) $s['comment_leads'];
    echo '<tr><th scope="row">First comment</th><td><p class="description" style="margin-top:0">The words before the listing link in each post\'s first comment. Blank uses the default. New drafts use them; a post already written keeps its comment (edit it there, or Rewrite).</p><table class="ias-kv">';
    foreach ($lead_labels as $k => $label) {
      echo '<tr><th><label for="ias-lead-' . esc_attr($k) . '">' . esc_html($label) . '</label></th><td><input id="ias-lead-' . esc_attr($k) . '" type="text" class="regular-text" name="ias[comment_leads][' . esc_attr($k) . ']" value="' . esc_attr((string) ($leads[$k] ?? '')) . '" placeholder="' . esc_attr((string) ias_settings_defaults()['comment_leads'][$k]) . '" maxlength="120"> <span class="description">… then the link</span></td></tr>';
    }
    echo '</table></td></tr>';
    echo '<tr><th scope="row"><label for="ias-fh">More fair-housing phrases</label></th><td><textarea id="ias-fh" name="ias[fair_housing_extra]" rows="4" class="large-text">' . esc_textarea((string) $s['fair_housing_extra']) . '</textarea>'
      . '<br><span class="description">One per line. A draft containing one stops for a decision, like "perfect for families" or "safe neighborhood" already do.</span></td></tr>';
    echo '<tr><th scope="row">Photo grading</th><td><select name="ias[grade_size]">';
    foreach (['thumbnail' => 'Thumbnail (cheapest, rough)', 'medium' => 'Medium — 300px (default)', 'medium_large' => 'Medium large — 768px (about 6× the tokens)'] as $k => $label) {
      echo '<option value="' . esc_attr($k) . '" ' . selected((string) $s['grade_size'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select><br><span class="description">Claude looks at each photo once, at this size, to score it and tag its kind.</span></td></tr>';
    $why = ias_ai_blocker();
    $month = function_exists('ia_ai_tokens_month') ? (int) (ia_ai_tokens_month()['total'] ?? 0) : null;
    $cap = function_exists('ia_ai_token_cap') ? (int) ia_ai_token_cap() : 0;
    echo '<tr><th scope="row">Claude</th><td>' . ($why === '' ? ia_ui_badge('Ready', 'ok') : ia_ui_badge('Stopped', 'warn') . ' ' . esc_html($why))
      . (function_exists('ia_ai_key_settings_url') ? ' <a href="' . esc_url(ia_ai_key_settings_url()) . '">' . ($why === '' ? 'Change the key or the cap' : 'Set the key and the cap') . '</a>' : '')
      . ($month !== null ? '<br><span class="description">Drafts, photo grading, Talk it over and Brand voice use the toolkit\'s Anthropic key and its monthly cap (Intel SEO → Settings & Data → Services & Budgets): ' . esc_html(number_format($month)) . ' tokens used this month' . ($cap > 0 ? ' of ' . esc_html(number_format($cap)) : ', no cap') . ', across every feature.</span>' : '') . '</td></tr>';
    echo '<tr><th scope="row">Drafts per run</th><td>' . $num('ias[generate_per_run]', $s['generate_per_run'], 0, 30, 'drafts an hour at most') . '<br><span class="description">Each is one Claude call against the toolkit\'s monthly cap. 0 stops new drafts.</span></td></tr>';
    echo '<tr><th scope="row">Talk it over</th><td>' . $num('ias[chat_turns]', $s['chat_turns'], 0, 100, 'answers from Claude per post at most') . '<br><span class="description">The chat beside each post: Claude changes its text, first comment, pictures and alt text as you talk. Every message is one Claude call against the monthly cap; nothing changes until you press Save changes. 0 takes the chat away.</span></td></tr>';
    echo '<tr><th scope="row">Examples in each draft</th><td>' . $num('ias[voice_examples]', $s['voice_examples'], 0, 6, 'posts') . '<br><span class="description">The newest posts a person wrote, approved or starred on <a href="' . esc_url(ias_admin_url(IAS_PAGE_VOICE)) . '">Brand voice</a>, shown to Claude as how the team sounds — each adds a little to every draft\'s Claude bill (roughly a short paragraph of reading). 0 shows none.</span></td></tr>';
    echo '<tr><th scope="row">Learn on its own</th><td><label><input type="checkbox" name="ias[voice_auto]" value="1" ' . checked(!empty($s['voice_auto']), true, false) . '> Claude reads the brand-voice log by itself and suggests house rules</label><br><span class="description">At most once a week, once ' . (int) (function_exists('ias_voice_auto_after') ? ias_voice_auto_after() : 8) . ' new posts a person wrote, approved or starred have come in: one Claude call. The suggestion waits on Brand voice (and in the weekly email) until someone saves or discards it.</span></td></tr>';
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
