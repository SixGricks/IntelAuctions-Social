<?php
/**
 * A person chooses a post's pictures (DJ, Oct 13: "a way to add, reorder, and
 * remove images"): any picture from the Media Library, in their order, up to
 * Facebook's 10. Their choice holds through a rewrite, goes to OneUp in their
 * order, needs a fresh yes on an approve-first post, and can be handed back.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123']);
$gal = [];
for ($i = 1; $i <= 8; $i++) $gal[] = ias_t_photo('Lima ' . $i);
$extra = ias_t_photo('Drone shot from the Media Library');
$l = ias_t_listing(['title' => '6 Lima Lane', 'address' => '6 Lima Lane, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'gallery' => $gal]);
ias_sync();
ias_drafts_run(30);
$p = ias_post_by_slot('listing:' . $l);
$id = (int) $p['id'];
$ids = function () use ($id) { return array_map(function ($i) { return (int) $i['id']; }, (array) (ias_post_get($id)['content']['media']['images'] ?? [])); };
$act = function (string $action, array $post) {
  $_POST = ['ias_cal_action' => $action] + array_map('strval', $post);
  do_action('ias_calendar_action', $action);
  $_POST = [];
};
$picked = $ids();
t_ok(count($picked) >= 3, 'the plugin chose the Listing post\'s pictures (' . count($picked) . ')');

// ---- Reorder, remove one, add one from the Media Library ----
$mine = array_reverse(array_slice($picked, 1)); // the first one removed, the rest reversed
array_splice($mine, 1, 0, [$extra]);            // a picture from outside the gallery, second
$act('media', ['ias_post' => $id, 'ias_media' => implode(',', array_map(function ($a) { return 'a' . $a; }, $mine))]);
$p = ias_post_get($id);
t_eq($mine, $ids(), 'the pictures are saved in the person\'s order, with one removed and one added from the Media Library');
t_eq('admin', (string) ($p['flags']['media_by'] ?? ''), '…and it is noted who chose them');
t_eq($mine, array_map('intval', (array) $p['images_used']), '…which the next posts for this property avoid');
t_eq('Pictures chosen by hand: ' . count($mine) . ' pictures', (string) (end($p['flags']['trail'])['what'] ?? ''), '…and the post\'s history says so');
$req = ias_render_facebook($p);
t_eq(wp_get_attachment_image_url($mine[0], '2048x2048') ?: '', explode('~~', (string) $req['params']['image_url'])[0], 'what would go to OneUp leads with the person\'s first picture');
t_eq(count($mine), count(explode('~~', (string) $req['params']['image_url'])), '…and carries all of them');

// ---- A rewrite keeps them ----
$flags = $p['flags'];
unset($flags['regen_at']);
ias_post_update($id, ['flags' => $flags]);
ias_generate_post($id, true, 'admin');
t_eq($mine, $ids(), 'a rewrite keeps the person\'s pictures in their order');

// ---- What is refused ----
$before = $ids();
$act('media', ['ias_post' => $id, 'ias_media' => implode(',', array_map(function ($a) { return 'a' . $a; }, array_merge($gal, [$extra, ias_t_photo('one too many'), ias_t_photo('and another')])))]);
t_eq($before, $ids(), 'more than 10 pictures is refused, and nothing changes');
$act('media', ['ias_post' => $id, 'ias_media' => 'a' . $mine[0] . ',x9,a99999999,g7,a' . $mine[0] . ',a' . $id]);
t_eq([$mine[0]], $ids(), 'keys that are not pictures (a typo, a missing attachment, a graphic the post does not have, a repeat) are dropped');

// ---- An approve-first post needs a fresh yes ----
ias_post_update($id, ['status' => 'approved', 'approved_by' => 'dj', 'approved_at' => ias_now_sql()]);
$act('media', ['ias_post' => $id, 'ias_media' => 'a' . $mine[0] . ',a' . $extra]);
$p = ias_post_get($id);
t_ok($p['status'] === 'planned' && (string) $p['approved_by'] === '', 'new pictures on an approved post take the approval away: it needs a fresh yes');

// ---- The screen ----
$errors = [];
set_error_handler(function ($no, $str, $file, $line) use (&$errors) { $errors[] = "{$str} at " . basename($file) . ":{$line}"; return true; });
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $id];
ob_start(); ias_calendar_render(); $html = ob_get_clean();
t_ok(strpos($html, 'id="ias-pics"') !== false && strpos($html, 'Add pictures') !== false && strpos($html, 'Save pictures') !== false, 'the post view has the picture editor');
t_ok(strpos($html, 'data-key="a' . $extra . '"') !== false && strpos($html, 'Let the plugin choose again') !== false, '…with the chosen pictures, and the way to hand them back');
ias_post_update($id, ['locked' => 1]);
ob_start(); ias_calendar_render(); $html = ob_get_clean();
t_ok(strpos($html, 'id="ias-pics"') === false, 'a post a person edited in OneUp has no picture editor here');
ias_post_update($id, ['locked' => 0]);
$_GET = [];
restore_error_handler();
t_eq([], $errors, 'no PHP notices on the screen');

// ---- Handed back ----
$act('media_reset', ['ias_post' => $id]);
$p = ias_post_get($id);
t_ok(empty($p['flags']['media_by']) && count($ids()) >= 3, 'handed back: the plugin chooses the pictures again');
t_ok(!in_array($extra, $ids(), true), '…from the property\'s own photos');

ias_t_claude_off();
