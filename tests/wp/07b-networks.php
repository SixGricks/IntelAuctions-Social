<?php
/**
 * Instagram, X and LinkedIn, provisioned (DJ, Oct 13, 2026): switched on, a
 * post is planned for each — its own words (adapted from Facebook's, X's from
 * the facts), pictures (Instagram's square crops; X's first 4) and tagged
 * link — and its request goes to the dry-run log once per change, with a
 * take-back when it stops being ready. Facebook's request is untouched, and
 * nothing is ever sent to the new networks, even with Facebook live.
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
$pid = 0; // the Listing post's id, once there is one: the counts are about that one post
$logs = function (string $ch) use (&$pid) {
  return array_values(array_filter(ias_log_recent('dryrun', 500), function ($r) use ($ch, &$pid) { return ($r['meta']['channel'] ?? '') === $ch && (int) ($r['meta']['post'] ?? 0) === $pid; }));
};
$plan();
$p = ias_post_by_slot('listing:' . $m);
$pid = (int) $p['id'];
$fb_hash = (string) $p['flags']['dry_hash'];
t_ok($fb_hash !== '', 'Facebook\'s dry run logged the Listing post');
t_eq([], $logs('instagram'), 'with the networks off, nothing is planned for them');

// ---- Switched on ----
ias_settings_update(['networks' => ['instagram' => ['on' => true, 'account' => 'ig_1'], 'x' => ['on' => true, 'account' => 'x_1'], 'linkedin' => ['on' => true, 'account' => 'li_1']]]);
t_eq(['instagram', 'x', 'linkedin'], ias_channels_extra_on(), 'Instagram, X and LinkedIn switched on in Settings');
$plan();
$p = ias_post_by_slot('listing:' . $m);
t_eq($fb_hash, (string) $p['flags']['dry_hash'], 'Facebook\'s request is unchanged by the other networks');
foreach (['instagram' => 'ig_1', 'x' => 'x_1', 'linkedin' => 'li_1'] as $ch => $acct) {
  $l = $logs($ch);
  t_eq(1, count($l), ias_channel($ch)['label'] . ': its request is in the dry-run log, once');
  t_eq($acct, (string) ($l[0]['meta']['payload']['params']['social_network_id'] ?? ''), '…to its own OneUp account');
  t_ok(strpos((string) $l[0]['meta']['summary'], 'not sent') !== false, '…and says it was not sent');
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
$plan();
t_eq(1, count($logs('instagram')), 'unchanged: not logged again');

// ---- Facts with no time; a long line ----
t_ok(strpos(ias_x_line('listing', [['street' => '1 A St', 'town' => 'Gap', 'date' => new DateTimeImmutable('2026-11-28 00:00', ia_wp_tz()), 'has_time' => false, 'starting_bid_raw' => '']]), '12:00') === false, 'X never says 12:00 am for a sale with no time');
$long = $p;
$long['content']['hashtags'] = ['#IntelAuctions', '#Narvon'];
$long['content']['link'] = 'https://intelauctions.com/auction/' . str_repeat('a-very-long-slug-', 20) . '/';
t_ok(ias_x_length(ias_channel_text($long, 'x')) <= 280, 'a long link still fits: X counts every address as 23');

// ---- Take-backs ----
ias_settings_update(['networks' => ['instagram' => ['on' => true, 'account' => 'ig_1'], 'x' => ['on' => false, 'account' => 'x_1'], 'linkedin' => ['on' => true, 'account' => 'li_1']]]);
ias_push_run();
$xl = $logs('x');
t_ok(count($xl) === 2 && strpos((string) $xl[0]['meta']['summary'], 'Would delete from X (X switched off)') === 0, 'X switched off: the dry run logs taking it back');
t_ok(!isset(ias_post_get((int) $p['id'])['flags']['dry_ch']['x']), '…and forgets it');
$_POST = ['ias_cal_action' => 'skip', 'ias_post' => (string) $p['id']];
do_action('ias_calendar_action', 'skip');
$_POST = [];
ias_push_run();
t_ok(strpos((string) $logs('instagram')[0]['meta']['summary'], 'Would delete from Instagram') === 0 && strpos((string) $logs('linkedin')[0]['meta']['summary'], 'Would delete from LinkedIn') === 0, 'a skipped post comes back off every network in the dry run');

// ---- Facebook live: still nothing to the others ----
$_POST = ['ias_cal_action' => 'restore', 'ias_post' => (string) $p['id']];
do_action('ias_calendar_action', 'restore');
$_POST = [];
ias_t_oneup();
$S =& $GLOBALS['ias_t_oneup'];
ias_settings_update(['master' => true, 'types' => ['listing' => true]]);
ias_oneup_verify('admin');
ias_settings_update(['dry_run' => false]);
$plan();
$sent = array_map(function ($c) { return (string) ($c['params']['social_network_id'] ?? ''); }, array_filter(ias_t_oneup_calls(0), function ($c) { return strpos($c['path'], 'schedule') === 0 && ($c['params']['isDraftPost'] ?? '') !== 'true'; }));
t_ok($sent && array_values(array_unique($sent)) === ['fb_123'], 'Facebook live: only the Facebook page is sent to — never Instagram, X or LinkedIn (' . implode(', ', array_unique($sent)) . ')');
t_ok(count($logs('instagram')) >= 3, '…the others stay in the dry run');

// ---- The screens ----
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$_GET = ['page' => IAS_PAGE_SETTINGS];
ob_start(); ias_settings_render(); $html = ob_get_clean();
t_ok(strpos($html, 'More networks') !== false && strpos($html, 'name="ias[networks][instagram][account]"') !== false, 'Settings has the networks, with their OneUp account ids');
t_ok(strpos($html, 'Dry run only') !== false, 'the switches strip says the networks on are in the dry run only');
$_GET = [];
restore_error_handler();
t_eq([], $errors, 'no PHP notices');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'dry_run' => true, 'types' => ['listing' => false], 'networks' => ['instagram' => ['on' => false, 'account' => ''], 'x' => ['on' => false, 'account' => ''], 'linkedin' => ['on' => false, 'account' => '']]]);
delete_option('ias_oneup_verify');
