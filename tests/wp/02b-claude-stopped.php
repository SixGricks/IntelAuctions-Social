<?php
/**
 * Without Claude — no Anthropic key in the toolkit, or the month's budget
 * spent — the plugin tries nothing: no call, no post stamped with an error,
 * no log line per listing. Every Social screen says why once, Settings shows
 * Claude's state, and planning goes on. With the key back, drafts are written.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();                          // the fake Claude counts any call that slips through
delete_option('ia_anthropic_api_key');   // …but the toolkit has no key
$gal = [ias_t_photo('Kilo 1'), ias_t_photo('Kilo 2'), ias_t_photo('Kilo 3'), ias_t_photo('Kilo 4')];
$k = ias_t_listing(['title' => '4 Kilo Lane', 'address' => '4 Kilo Lane, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'gallery' => $gal]);
$failed_logs = function () { return count(ias_log_recent('gen_failed', 200)); };
$errors_on_posts = function () {
  return count(array_filter(ias_posts_query(['limit' => 200]), function ($p) { return !empty($p['flags']['gen_error_at']); }));
};

// ---- No key ----
$why = ias_ai_blocker();
t_ok(strpos($why, 'no Anthropic API key') !== false, 'no key in the toolkit: Claude is stopped, and it says how to fix it');
ias_sync();
$r = ias_drafts_run(30);
t_eq(0, (int) $GLOBALS['ias_t_claude_n'], 'the hourly drafts call nothing');
t_ok($r['left'] > 0 && $r['errors'] === [$why], 'the run says why, and how many drafts wait (' . $r['left'] . ')');
t_eq(0, $errors_on_posts(), 'no post is stamped with the error (nothing waits six hours once the key is back)');
$g = ias_photos_grade(ias_listing_facts($k));
t_ok($g['error'] === $why && $g['graded'] === 0, 'photo grading does not try either');
t_eq('', (string) get_post_meta($k, '_ias_grade_error_at', true), '…and starts no six-hour wait');
t_eq(0, $failed_logs(), 'no "failed" log line for every listing');
ias_plan_run();
t_ok(count(ias_posts_query(['status' => ['planned', 'held'], 'limit' => 50])) > 0, 'planning goes on without words');

$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$screen = function (string $page) {
  $_GET = ['page' => $page];
  ob_start();
  if ($page === IAS_PAGE_SETTINGS) ias_settings_render(); else ias_calendar_render();
  $_GET = [];
  return (string) ob_get_clean();
};
$html = $screen(IAS_PAGE_CALENDAR);
t_ok(strpos($html, 'Drafts are stopped.') !== false && strpos($html, 'no Anthropic API key') !== false, 'the calendar says drafts are stopped, and why');
$html = $screen(IAS_PAGE_SETTINGS);
t_ok(preg_match('/<th scope="row">Claude<\/th><td>.*Stopped/', $html) === 1, 'Settings shows Claude as stopped');

// ---- The month's budget spent ----
update_option('ia_anthropic_api_key', 'test-key');
$ym = current_time('Y-m');
$was = [get_option('ia_ai_tok_in_' . $ym, 0), get_option('ia_ai_tok_out_' . $ym, 0)];
add_filter('ia_ai_token_cap', function () { return 1000; });
update_option('ia_ai_tok_in_' . $ym, 1200, false);
update_option('ia_ai_tok_out_' . $ym, 300, false);
t_ok(strpos(ias_ai_blocker(), 'budget in the toolkit is spent (1,500 of 1,000 tokens)') !== false, 'the month\'s budget spent: stopped too, with the numbers');
ias_drafts_run(30);
t_eq(0, (int) $GLOBALS['ias_t_claude_n'], '…and nothing is called');
update_option('ia_ai_tok_in_' . $ym, $was[0], false);
update_option('ia_ai_tok_out_' . $ym, $was[1], false);
remove_all_filters('ia_ai_token_cap');

// ---- The key back: drafts are written ----
t_eq('', ias_ai_blocker(), 'with the key and budget left, Claude is ready');
$r = ias_drafts_run(30);
t_ok($r['written'] > 0 && (int) $GLOBALS['ias_t_claude_n'] > 0, 'the next run writes the drafts (' . $r['written'] . ')');
$html = $screen(IAS_PAGE_CALENDAR);
t_ok(strpos($html, 'Drafts are stopped.') === false, '…and the notice is gone');
t_ok(preg_match('/<th scope="row">Claude<\/th><td>.*Ready/', $screen(IAS_PAGE_SETTINGS)) === 1, 'Settings shows Claude as ready');
restore_error_handler();
t_eq([], $errors, 'no PHP notices on the screens');

ias_t_claude_off();
