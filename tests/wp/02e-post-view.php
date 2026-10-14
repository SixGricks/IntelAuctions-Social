<?php
/**
 * The post view after the UI review (Oct 13): one bar across the top says
 * what the post waits for, with the buttons beside the reason; Edit and
 * Pictures on the left, the post as each network shows it in the middle, the
 * details on the right — in plain words, with people's names, not logins.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
add_filter('pre_wp_mail', '__return_true');
ias_settings_update(['generate_per_run' => 30, 'approvers' => 'dj@intelauctions.com, jacob@intelauctions.com']);
$gal = [];
for ($i = 1; $i <= 5; $i++) $gal[] = ias_t_photo('Oscar ' . $i);
$o = ias_t_listing(['title' => '3 Oscar Lane', 'address' => '3 Oscar Lane, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'gallery' => $gal]);
ias_sync(); ias_drafts_run(30); ias_plan_run();
$p = ias_post_by_slot('listing:' . $o);
$id = (int) $p['id'];
$view = function (int $id) {
  $_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $id];
  ob_start(); ias_calendar_render(); $html = (string) ob_get_clean();
  $_GET = [];
  return $html;
};
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });

// ---- A planned post: no bar; Edit, Pictures, Preview, details ----
$html = $view($id);
t_ok(strpos($html, 'Listing post · 3 Oscar Lane') !== false, 'the title names the post type and the listing');
t_ok(strpos($html, 'inline ias-bar') === false, 'a planned post that waits for nothing has no bar');
foreach (['class="ias-post"', '<h2>Edit</h2>', 'id="ias-pictures"', 'class="ias-fb ias-fb-facebook"', 'About this post', 'History'] as $needle) t_ok(strpos($html, $needle) !== false, '…it has ' . strip_tags($needle));
t_ok(strpos($html, 'Not needed — 48 hours to skip, move or change it here first') !== false && strpos($html, 'Can go') !== false && strpos($html, 'guaranteed a place') !== false, 'the details are in plain words (approval, when it can go, its place)');
t_ok(strpos($html, '<th>Lane</th>') === false && strpos($html, '<th>Window</th>') === false, '…not the plugin\'s own words (lane, window)');
t_ok(strpos($html, 'Turn this post off…') !== false && strpos($html, '>Rewrite<') !== false, 'Rewrite and Turn off (Skip) end the Edit card');

// ---- Held for a [VERIFY] marker: the bar, the marker picked out, no Keep as written ----
$c = (array) $p['content'];
$c['text'] = 'Auction is Saturday at [VERIFY: auction time]. Call the office.';
$f = $p['flags'];
$f['holds'] = ['Claude marked something to check: [VERIFY: auction time]'];
ias_post_update($id, ['status' => 'held', 'content' => $c, 'flags' => $f]);
$html = $view($id);
t_ok(strpos($html, 'notice-error inline ias-bar') !== false && strpos($html, 'Needs a decision — this post goes nowhere until someone decides.') !== false, 'held: the bar says it needs a decision');
t_ok(substr_count($html, '<mark class="ias-verify">[VERIFY: auction time]</mark>') >= 2, '…and the marker is picked out, in the reasons and the preview');
t_ok(strpos($html, 'Keep as written') === false && strpos($html, 'Put the right fact in place of the marked words') !== false, '…with no Keep as written, and the way out in words');

// ---- Waiting for approval: Approve in the bar, the one primary button ----
$f['holds'] = [];
$c['text'] = 'Sold. Thank you to everyone who bid.';
ias_post_update($id, ['status' => 'waiting', 'lane' => 'A', 'content' => $c, 'flags' => $f, 'approvals' => ['sent' => [['at' => '2026-11-02 05:00:00', 'kind' => 'request', 'to' => ['dj@intelauctions.com']]]]]);
$html = $view($id);
t_ok(strpos($html, 'notice-warning inline ias-bar') !== false && strpos($html, 'Waiting for approval.') !== false, 'waiting: the bar says so');
t_ok(strpos($html, 'Nothing goes to OneUp until DJ or Jacob says yes') !== false, '…naming the approvers as people ("dj@" is DJ)');
t_ok(preg_match('/ias-bar.*?button button-primary[^>]*>Approve</s', $html) === 1, '…with Approve in the bar');
t_eq(1, substr_count($html, 'button-primary'), '…the only primary button on the page');

// ---- The networks switched on: a tab each ----
ias_post_update($id, ['status' => 'planned', 'lane' => 'B', 'approvals' => []]);
ias_settings_update(['networks' => ['instagram' => ['on' => true, 'account' => 'ig_1'], 'x' => ['on' => true, 'account' => ''], 'linkedin' => ['on' => false, 'account' => '']]]);
$html = $view($id);
t_ok(strpos($html, 'data-net="instagram"') !== false && strpos($html, 'data-net="x"') !== false && strpos($html, 'data-net="linkedin"') === false, 'a preview tab for each network switched on');
t_ok(strpos($html, 'of 280 characters') !== false && strpos($html, 'No X account id in Settings') !== false, 'X shows its count, and says what is missing');
ias_settings_update(['networks' => ['instagram' => ['on' => false, 'account' => ''], 'x' => ['on' => false, 'account' => '']]]);

// ---- People, not logins ----
$u = get_user_by('id', 1);
wp_update_user(['ID' => 1, 'display_name' => 'DJ Ranck']);
ias_post_trail($id, 'Text edited', (string) $u->user_login);
$html = $view($id);
t_ok(strpos($html, '· DJ Ranck</span>') !== false && strpos($html, '· the plugin</span>') !== false, 'the history shows names (and "the plugin"), not logins');
wp_update_user(['ID' => 1, 'display_name' => (string) $u->display_name]);

restore_error_handler();
t_eq([], $errors, 'no PHP notices on the post view');
remove_all_filters('pre_wp_mail');
ias_t_claude_off();
