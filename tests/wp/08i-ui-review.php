<?php
/**
 * 0.27.0, the Oct 16 UI review: one name per post type, files chosen from the
 * Media Library rather than typed as ids, and a held post with no reason on
 * record still gets a bar with something to press.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();

// One name per type: short and label agree, and a noun never doubles "post".
foreach (ias_post_types() as $k => $def) t_eq($def['label'], $def['short'], 'one name for ' . $k);
t_eq('Listing post', ias_post_noun('listing'), 'a type as a noun');
t_eq('Your post', ias_post_noun('manual'), '…never "Your post post"');
t_eq('Several divisions', ias_division_label('mixed'), 'several divisions in plain words');
t_eq('Intel general', ias_division_label('intel'), '…and the facts\' division names are unchanged (the fingerprint reads them)');

// A file is chosen: a hidden id, a button, the file's name.
$img = ias_t_photo('Cover pick');
$h = ias_media_field('ias_brochure_cover', $img, 'ias-bc', 'Brochure cover', 'Not chosen', 'image,application/pdf');
t_ok(strpos($h, 'type="hidden"') !== false && strpos($h, 'name="ias_brochure_cover"') !== false && strpos($h, 'value="' . $img . '"') !== false, 'the id rides in a hidden field');
t_ok(strpos($h, 'Choose file…') !== false && strpos($h, basename((string) get_attached_file($img))) !== false, 'a Choose button and the file\'s name');
t_ok(strpos($h, 'type="number"') === false, 'no id to type');
$e = ias_media_field('ias[brand_font_id]', 0, 'ias-font', 'Brand font', 'Inter (bundled)');
t_ok(strpos($e, 'Inter (bundled)') !== false && strpos($e, 'value=""') !== false, 'nothing chosen says what stands in');

// A held post with no recorded reason: the bar still says what to do and offers Keep as written.
$gal = []; for ($i = 1; $i <= 3; $i++) $gal[] = ias_t_photo('Held ' . $i);
$a = ias_t_listing(['title' => '9 Quiet Road', 'address' => '9 Quiet Road, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'gallery' => $gal]);
ias_settings_update(['generate_per_run' => 30]);
ias_sync(); ias_drafts_run(30);
$p = ias_post_by_slot('listing:' . $a);
t_ok($p && (string) ($p['content']['text'] ?? '') !== '', 'a drafted post');
$flags = (array) $p['flags']; unset($flags['holds']);
ias_post_update((int) $p['id'], ['status' => 'held', 'flags' => $flags, 'note' => '']);
wp_set_current_user(1);
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (int) $p['id']];
ob_start(); ias_calendar_render(); $view = (string) ob_get_clean(); $_GET = [];
t_ok(strpos($view, 'Needs a decision') !== false && strpos($view, 'No reason was recorded.') !== false, 'the bar says it needs a decision');
t_ok(strpos($view, 'Keep as written') !== false && strpos($view, 'Rewrite') !== false, '…and offers Rewrite and Keep as written');
t_ok(strpos($view, 'Listing post ·') !== false, 'the page names the type once');
