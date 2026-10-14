<?php
/**
 * 0.17.0, DJ, Oct 14, 2026 (on OneUp's Intermediate plan): how each post did
 * on Facebook — the spec's "Every post records when it went out and how it
 * did". Once a day the plugin reads OneUp's analytics for the page (GET
 * analyze.oneupapp.io/api/facebook/posts) and keeps each post's reactions,
 * comments, shares, clicks and views, matched by Facebook's id or by its
 * words at its time; shown on the post, in its hover preview and in the
 * monthly summary. A read only: never with the master switch off or the
 * setting off; a plan without analytics is said once and asked again in a week.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_t_oneup();
$S =& $GLOBALS['ias_t_oneup'];
$S['gbp'] = false;
$S['docs'] = true;
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$mk = function (string $title, string $sale, string $county) use ($T, $lead) {
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing(['title' => $title, 'address' => $title . ', Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => $county,
    'stage' => 'upcoming', 'sale' => $sale, 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live', 'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal]);
};
$lp = function (int $listing) { return ias_post_by_slot('listing:' . $listing); };
$stat_calls = function (int $from) { return array_values(array_filter(ias_t_oneup_calls($from, 'posts'), function ($c) { return true; })); };

ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
$a = $mk('12 Alpha Road', '2026-11-28 10:00', 'Lancaster');
$b = $mk('7 Bravo Lane', '2026-11-27 10:00', 'Berks');
ias_sync(); ias_drafts_run(30); ias_plan_run(); ias_push_run();
$ids = [$a => (int) ias_post_sent($lp($a))['id'], $b => (int) ias_post_sent($lp($b))['id']];
t_ok($ids[$a] > 0 && $ids[$b] > 0, 'setting out: both Listing posts are on OneUp');

// ---- Nothing out yet: nothing asked ----
$n = count($S['calls']);
$r = ias_stats_run('admin');
t_ok($r['skipped'] !== '' && !$stat_calls($n), 'before anything went out, nothing is asked (' . $r['skipped'] . ')');

// ---- Both go out ----
$latest = max(strtotime((string) $lp($a)['scheduled_at']), strtotime((string) $lp($b)['scheduled_at']));
ias_t_clock(date('Y-m-d H:i', $latest + 26 * HOUR_IN_SECONDS));
ias_t_oneup_publish($ids[$a]);
ias_t_oneup_publish($ids[$b]);
ias_push_run();
t_ok($lp($a)['status'] === 'published' && $lp($b)['status'] === 'published' && empty($lp($a)['flags']['unconfirmed']), 'both went out, confirmed');

// The analytics: a by Facebook's id (its words since changed on Facebook), b by its words and time, and a person's own post.
ias_t_fb_stat($ids[$a], ['reactions' => 180, 'comments' => 32, 'shares' => 45, 'clicks' => 210, 'media_views' => 5200], ['message' => 'Edited on Facebook afterwards']);
ias_t_fb_stat($ids[$b], ['reactions' => 12, 'comments' => 1, 'shares' => 0, 'clicks' => 9, 'media_views' => 640], ['id' => '7777777']);
$S['fb_stats']['x'] = ['id' => '1336677789520495_31337', 'page_id' => '1336677789520495', 'message' => 'Happy Thanksgiving from all of us', 'permalink_url' => 'https://facebook.com/x',
  'stats' => ['shares' => 3, 'comments' => 4, 'clicks' => 0, 'reactions' => 90, 'media_views' => 2000], 'created_time' => '2026-11-10T14:00:00+00:00'];

$n = count($S['calls']);
$r = ias_stats_run('admin');
$calls = $stat_calls($n);
t_ok($r['ok'] && $r['rows'] === 3 && $r['matched'] === 2 && $r['updated'] === 2, 'read: 3 posts on the page, 2 of them ours, both updated (' . wp_json_encode($r) . ')');
t_eq(1, count($calls), 'one call');
$c = $calls[0] ?? ['method' => '', 'params' => []];
t_eq('GET', $c['method'], '…a GET to OneUp\'s analytics');
t_eq('fb_123', $c['params']['social_network_id'] ?? '', '…for the page, by its plain id (not the scheduling calls\' list)');
t_eq([ias_now()->modify('-31 days')->format('Y-m-d'), ias_now()->modify('-1 day')->format('Y-m-d')], [$c['params']['start_date'] ?? '', $c['params']['end_date'] ?? ''], '…the last 31 days to yesterday (the analytics end no later than yesterday)');
t_eq(ia_wp_tz()->getName(), $c['params']['timezone'] ?? '', '…in the site\'s time zone');

$ap = $lp($a);
$st = (array) ($ap['flags']['stats'] ?? []);
t_eq([180, 32, 45, 210, 5200], [$st['reactions'] ?? -1, $st['comments'] ?? -1, $st['shares'] ?? -1, $st['clicks'] ?? -1, $st['views'] ?? -1], 'matched by Facebook\'s id, though its words changed: its numbers are kept on the post');
t_eq(9.0, (float) ($st['rate'] ?? -1), '…with the engagement rate: (180 + 32 + 45 + 210) / 5,200 = 9.0%');
t_eq('https://facebook.com/1336677789520495/posts/' . $ids[$a], (string) ($st['url'] ?? ''), '…and its address on Facebook');
t_eq('1336677789520495_' . $ids[$a], (string) ($ap['flags']['oneup']['fb_id'] ?? ''), '…and Facebook\'s id for it, page first (what the comment calls need)');
$bp = $lp($b);
t_eq(640, (int) ($bp['flags']['stats']['views'] ?? -1), 'matched by its words and time, with an id the plugin did not know');
t_eq('1336677789520495_7777777', (string) ($bp['flags']['oneup']['fb_id'] ?? ''), '…whose id is kept from then on, with the page in front');
t_eq([], array_filter(ias_posts_query(['limit' => 200]), function ($p) { return !empty($p['flags']['stats']) && $p['type'] === 'human'; }), 'a person\'s own post is not taken for one of ours');

// Read again: nothing changed, nothing written; a change is written.
$r = ias_stats_run('admin');
t_eq(0, $r['updated'], 'read again with the same numbers: nothing is rewritten');
$S['fb_stats'][$ids[$a]]['stats']['reactions'] = 200;
$r = ias_stats_run('admin');
t_ok($r['updated'] === 1 && (int) $lp($a)['flags']['stats']['reactions'] === 200, 'new numbers are kept');

// ---- Where they show ----
$_GET = ['page' => IAS_PAGE_CALENDAR, 'post' => (string) $ap['id']]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = [];
t_ok(strpos($h, 'How it did on Facebook') !== false && strpos($h, '200 reactions · 32 comments · 45 shares · 210 clicks · 5,200 views') !== false && strpos($h, 'See it on Facebook') !== false, 'the post\'s page says how it did, with a link to it on Facebook');
$_GET = ['page' => IAS_PAGE_CALENDAR]; ob_start(); ias_calendar_render(); $h = (string) ob_get_clean(); $_GET = [];
t_ok(preg_match('~data-pv="([^"]*)"~', $h) && strpos(html_entity_decode($h, ENT_QUOTES, 'UTF-8'), 'On Facebook: 200 reactions') !== false, 'its hover preview on the calendar too');
$month = substr((string) $ap['published_at'], 0, 7);
$sum = ias_summary_month($month);
t_ok($sum['stats'] && $sum['types']['listing']['reactions'] === 212 && $sum['types']['listing']['views'] === 5840 && $sum['types']['listing']['counted'] === 2, 'the monthly summary adds them up by post type (212 reactions, 5,840 views)');
$html = ias_summary_html($month);
t_ok(strpos($html, '>Reactions<') !== false && strpos($html, '>Engagement<') !== false && strpos($html, '5,840') !== false, '…with columns for them, and the engagement over them (' . (string) round((212 + 33 + 45 + 219) / 5840 * 100, 1) . '%)');
t_ok(strpos($html, number_format(round((212 + 33 + 45 + 219) / 5840 * 100, 1), 1) . '%') !== false, '…shown as a percentage');
ob_start(); do_action('ias_settings_after_form'); $set = (string) ob_get_clean();
t_ok(strpos($set, 'How posts did on Facebook') !== false && strpos($set, '2 of ours among 3 posts on the page') !== false && strpos($set, '>Read now</button>') !== false, 'Settings says when it was last read and what it found, with Read now');

// ---- Once a day, on the hourly run ----
t_ok(!ias_stats_due(), 'just read: not due');
$n = count($S['calls']);
ias_job_run();
t_eq(0, count($stat_calls($n)), '…so the hourly run asks nothing');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 21 * HOUR_IN_SECONDS));
$n = count($S['calls']);
ias_job_run();
t_eq(1, count($stat_calls($n)), 'a day later, the hourly run reads them');

// A plan without analytics: said once, asked again in a week.
$S['stats_code'] = 403;
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 21 * HOUR_IN_SECONDS));
ias_job_run();
$last = get_option('ias_stats_last');
t_ok(!$last['ok'] && !empty($last['no_plan']) && strpos((string) $last['error'], 'Intermediate plan') !== false, 'OneUp answers 403: Settings says the plan has no analytics');
t_eq(200, (int) $lp($a)['flags']['stats']['reactions'], '…and the numbers already read stay');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 2 * DAY_IN_SECONDS));
t_ok(!ias_stats_due(), '…not asked again the next days');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 6 * DAY_IN_SECONDS));
t_ok(ias_stats_due(), '…but a week later');
$S['stats_code'] = 200;

// Off: never asked.
ias_settings_update(['stats_on' => false]);
$n = count($S['calls']);
ias_job_run();
$r = ias_stats_run('admin');
t_ok(!$stat_calls($n) && $r['skipped'] !== '', 'switched off in Settings: nothing is asked, not even by Read now');
ias_settings_update(['stats_on' => true, 'master' => false]);
$n = count($S['calls']);
ias_job_run();
t_eq([], $stat_calls($n), 'the master switch off: nothing is asked');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'types' => ['listing' => false]]);
delete_option('ias_oneup_verify');
delete_option('ias_stats_last');
