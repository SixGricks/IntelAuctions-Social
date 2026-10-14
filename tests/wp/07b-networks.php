<?php
/**
 * Instagram, X and LinkedIn, provisioned (DJ, Oct 13, 2026) — and preview
 * only (0.12.0, DJ, Oct 14: "Either things are active or inactive"): switched
 * on, each post shows what that network would get — its own words (adapted
 * from Facebook's, X's from the facts), pictures (Instagram's square crops;
 * X's first 4) and tagged link. A preview is not a posting switch: nothing is
 * logged as if sent, nothing is ever sent to them, even with Facebook on, and
 * they are not among the switches. Facebook's request is untouched.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123']);
$gal = [];
for ($i = 1; $i <= 8; $i++) $gal[] = ias_t_photo('Mike ' . $i);
$m = ias_t_listing(['title' => '8 Mike Mill Road', 'address' => '8 Mike Mill Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'gallery' => $gal]);
$plan = function () { ias_sync(); ias_drafts_run(30); ias_plan_run(); return ias_push_run(); };
$view = function (int $id) { $_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $id]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = []; return $h; };
$plan();
$p = ias_post_by_slot('listing:' . $m);
$fb_hash = md5((string) wp_json_encode(ias_render_facebook($p)));
t_ok(strpos($view((int) $p['id']), 'data-ch="instagram"') === false && strpos($view((int) $p['id']), 'A preview only') === false, 'with the previews off, a post shows Facebook only');

// ---- Previews on ----
ias_settings_update(['networks' => ['instagram' => ['on' => true, 'account' => 'ig_1'], 'x' => ['on' => true, 'account' => 'x_1'], 'linkedin' => ['on' => true, 'account' => 'li_1']]]);
t_eq(['instagram', 'x', 'linkedin'], ias_channels_extra_on(), 'Instagram, X and LinkedIn previews switched on in Settings');
$plan();
$p = ias_post_by_slot('listing:' . $m);
t_eq($fb_hash, md5((string) wp_json_encode(ias_render_facebook($p))), 'Facebook\'s request is unchanged by the other networks');
t_eq(0, count(ias_log_recent('dryrun', 500)), 'nothing is logged as if it were sent: there is no dry run');
t_ok(empty($p['flags']['dry_ch']), '…and nothing is recorded as sent to them');
$html = $view((int) $p['id']);
t_ok(substr_count($html, 'A preview only') === 3, 'the post shows a preview for each, saying it cannot be posted to yet');
foreach (['instagram' => 'ig_1', 'x' => 'x_1', 'linkedin' => 'li_1'] as $ch => $acct) {
  t_eq($acct, (string) (ias_render_channel($p, $ch)['params']['social_network_id'] ?? ''), ias_channel($ch)['label'] . ': its request names its own OneUp account');
}
$ig = ias_render_channel($p, 'instagram');
$x  = ias_render_channel($p, 'x');
$li = ias_render_channel($p, 'linkedin');
t_ok(strpos($ig['params']['content'], 'link in our bio') !== false && strpos($ig['params']['content'], 'http') === false, 'Instagram: the words with "link in our bio" and no web address (captions carry no links)');
t_ok(strpos($ig['params']['image_url'], 'square') !== false || strpos($ig['params']['image_url'], '1080x1080') !== false, '…and the square crops (' . substr($ig['params']['image_url'], -60) . ')');
t_eq([], ias_channel_problems($ig, 'instagram'), '…with nothing wrong for Instagram');
t_ok(strpos($x['params']['content'], 'Just listed: 8 Mike Mill Road, Narvon.') === 0, 'X: one line from the facts, not Claude\'s words (' . $x['params']['content'] . ')');
t_ok(ias_x_length($x['params']['content']) <= 280, '…within 280 characters as X counts them');
t_ok(strpos($x['params']['content'], 'utm_source=x') !== false, '…with the link tagged for X');
t_ok(count(explode('~~', (string) $x['params']['image_url'])) <= 4, '…and at most 4 pictures');
t_ok(strpos($li['params']['content'], 'Photos, terms and the full listing: http') !== false && strpos($li['params']['content'], 'utm_source=linkedin') !== false, 'LinkedIn: the words with the link at the end, tagged for LinkedIn');
t_ok(count(explode('~~', (string) $li['params']['image_url'])) <= 9, '…at most 9 pictures');
// ---- Facts with no time; a long line ----
t_ok(strpos(ias_x_line('listing', [['street' => '1 A St', 'town' => 'Gap', 'date' => new DateTimeImmutable('2026-11-28 00:00', ia_wp_tz()), 'has_time' => false, 'starting_bid_raw' => '']]), '12:00') === false, 'X never says 12:00 am for a sale with no time');
$long = $p;
$long['content']['hashtags'] = ['#IntelAuctions', '#Narvon'];
$long['content']['link'] = 'https://intelauctions.com/auction/' . str_repeat('a-very-long-slug-', 20) . '/';
t_ok(ias_x_length(ias_channel_text($long, 'x')) <= 280, 'a long link still fits: X counts every address as 23');

// ---- A preview switched off: its tab goes ----
ias_settings_update(['networks' => ['instagram' => ['on' => true, 'account' => 'ig_1'], 'x' => ['on' => false, 'account' => 'x_1'], 'linkedin' => ['on' => true, 'account' => 'li_1']]]);
t_eq(2, substr_count($view((int) $p['id']), 'A preview only'), 'X switched off: its preview goes, the others stay');

// ---- Facebook on: still nothing to the others ----
ias_t_oneup();
$S =& $GLOBALS['ias_t_oneup'];
ias_settings_update(['master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
$plan();
$sent = array_map(function ($c) { return (string) ($c['params']['social_network_id'] ?? ''); }, array_filter(ias_t_oneup_calls(0), function ($c) { return strpos($c['path'], 'schedule') === 0 && ($c['params']['isDraftPost'] ?? '') !== 'true'; }));
t_ok($sent && array_values(array_unique($sent)) === ['fb_123'], 'Facebook on: only the Facebook page is sent to — never Instagram, X or LinkedIn (' . implode(', ', array_unique($sent)) . ')');
t_eq(0, count(ias_log_recent('dryrun', 500)), '…and nothing is logged for them');

// ---- The screens ----
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$_GET = ['page' => IAS_PAGE_SETTINGS];
ob_start(); ias_settings_render(); $html = ob_get_clean();
t_ok(strpos($html, 'More networks') !== false && strpos($html, 'name="ias[networks][instagram][account]"') !== false && strpos($html, 'Show a preview for Instagram') !== false, 'Settings has the networks, with their OneUp account ids and a preview switch');
t_ok(stripos($html, 'dry run') === false, '…and never speaks of a dry run');
$strip = ias_switches_strip(true);
t_ok(strpos($strip, 'Instagram') === false && strpos($strip, 'LinkedIn') === false, 'a preview is not a switch: the switches strip does not list them');
$_GET = [];
restore_error_handler();
t_eq([], $errors, 'no PHP notices');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'types' => ['listing' => false], 'networks' => ['instagram' => ['on' => false, 'account' => ''], 'x' => ['on' => false, 'account' => ''], 'linkedin' => ['on' => false, 'account' => '']]]);
delete_option('ias_oneup_verify');
