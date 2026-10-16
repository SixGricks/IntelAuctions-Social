<?php
/**
 * 0.27.0, DJ, Oct 16, 2026 (the audit's open item on Lane B posts): "daily email". Each morning the approvers get
 * the Listing, Open House and Auction Week posts going out by the next posting day that no person has opened —
 * with their words and a link; opening one takes it off the list. Never on Sunday (Saturday's covers Monday).
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_t_oneup();
$GLOBALS['ias_t_oneup']['gbp'] = false;
$GLOBALS['ias_t_unread_mail'] = [];
add_filter('pre_wp_mail', function ($null, $atts) { if (strpos((string) $atts['subject'], 'Read before they go') === 0) $GLOBALS['ias_t_unread_mail'][] = $atts; return true; }, 10, 2);
ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true, 'approvers' => 'dj@intelauctions.com, jacob@intelauctions.com']);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
$gal = []; for ($i = 1; $i <= 3; $i++) $gal[] = ias_t_photo('Unread ' . $i);
$a = ias_t_listing(['title' => '3 Unread Lane', 'address' => '3 Unread Lane, Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
  'stage' => 'upcoming', 'sale' => '2026-11-28 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'gallery' => $gal]);
ias_sync(); ias_drafts_run(30);
$lp = ias_post_by_slot('listing:' . $a);
ias_post_update((int) $lp['id'], ['scheduled_at' => '2026-11-03 12:10:00']);
$lp = ias_post_get((int) $lp['id']);
t_ok(ias_setting('unread_email', false) === true, 'on at install');
t_ok(!ias_post_was_read($lp) && ias_push_ready($lp) === '', 'a Lane B post nobody has opened, ready to go tomorrow');

// Before its hour: nothing. From it: once.
ias_t_clock('2026-11-02 06:30');
t_ok(!ias_unread_due(), 'not before its hour (7:00)');
ias_t_clock('2026-11-02 07:05');
t_ok(ias_unread_due(), 'due at 7');
$ids = array_map(function ($p) { return (int) $p['id']; }, ias_unread_posts());
t_ok(in_array((int) $lp['id'], $ids, true), 'tomorrow\'s unread post is on the list');
ias_unread_send();
$m = $GLOBALS['ias_t_unread_mail'];
t_eq(2, count($m), 'one email to each approver');
t_ok(strpos((string) $m[0]['message'], '3 Unread Lane') !== false && strpos((string) $m[0]['message'], 'post=' . (int) $lp['id']) !== false, '…naming the post, with its words and a link to it');
t_ok(!ias_unread_due(), 'once a day');

// Opened by a person: off the list.
wp_set_current_user(1);
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $lp['id']]; ob_start(); ias_calendar_render(); ob_get_clean(); $_GET = [];
$lp = ias_post_get((int) $lp['id']);
t_ok(ias_post_was_read($lp) && !empty($lp['flags']['seen']['by']), 'opening it on the calendar marks it read (flags.seen)');
t_eq([], array_values(array_filter(ias_unread_posts(), function ($p) use ($lp) { return (int) $p['id'] === (int) $lp['id']; })), '…and it is off the list');

// Nothing unread: no email. Sunday: none. Saturday: through Monday.
$GLOBALS['ias_t_unread_mail'] = [];
ias_t_clock('2026-11-03 07:10');
ias_unread_send();
t_eq(0, count($GLOBALS['ias_t_unread_mail']), 'nothing unread: no email at all');
ias_t_clock('2026-11-08 08:00'); // Sunday
t_ok(!ias_unread_due(), 'never on Sunday');
t_eq('2026-11-09 23:59:59', ias_unread_until(new DateTimeImmutable('2026-11-07 07:00', ia_wp_tz())), 'Saturday\'s email covers Monday too');
t_eq('2026-11-04 23:59:59', ias_unread_until(new DateTimeImmutable('2026-11-03 07:00', ia_wp_tz())), 'any other morning: the next day');
ias_settings_update(['unread_email' => false]);
ias_t_clock('2026-11-09 07:10');
t_ok(!ias_unread_due(), 'off in Settings: never');
wp_set_current_user(0);
