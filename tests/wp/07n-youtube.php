<?php
/**
 * 0.22.0, DJ, Oct 14, 2026: "The goal is to cut down on human workload. I
 * want one time upload, title, description and not to think about it again.
 * I would love to just give you the file and then have you upload to Youtube
 * with description as part of a single property workflow."
 *
 * One upload on the toolkit's Property Videos row (the browser sends the file
 * straight to OneUp's storage), then by itself: to YouTube through OneUp with
 * a title and a description built from the listing's facts, the YouTube link
 * onto the listing once it is up (never over a person's link), and the file
 * as the video Facebook posts carry. Its own switch and Verify; never on a
 * Sunday; a lost answer is looked for and never sent twice; off means off.
 */
require_once dirname(__DIR__) . '/fixtures/wp-fixtures.php';

ias_t_reset();
foreach (['ias_youtube_verify', 'ias_youtube_playlists', 'ias_oneup_directory'] as $opt) delete_option($opt); // nothing left from an earlier run
ias_t_clock('2026-11-02 05:30'); // Monday
$T = ias_t_terms();
ias_t_claude();
ias_t_oneup();
ias_t_storage();
$S =& $GLOBALS['ias_t_oneup'];
$S['gbp'] = false;
$S['docs'] = true; // as OneUp's docs answer: no post id when scheduling, "FB_…" ids on the published list
$S['youtube'] = true;
$S['yt'] = $S['fb']; // the channel named exactly like the page: its videos must never read as a person's Facebook posts
$UC = 'UC2tfTuFO9diTgaaeozmDLKg';
$lead = ias_t_team('Jacob Stoltzfus', '717-555-0101');
$n = 0;
$mk = function (string $title, array $over = []) use ($T, $lead, &$n) {
  $n++;
  $gal = [];
  for ($i = 1; $i <= 4; $i++) $gal[] = ias_t_photo($title . ' ' . $i);
  return ias_t_listing($over + ['title' => $title, 'address' => $title . ', Narvon, PA 17555, USA', 'town' => 'Narvon', 'county' => 'Lancaster',
    'stage' => 'upcoming', 'sale' => date('Y-m-d', strtotime('2026-11-20 +' . $n . ' days')) . ' 10:00', 'terms' => [$T['farm'], $T['in_person']], 'auction_type' => 'Live',
    'bid' => '$300,000', 'lead' => $lead, 'gallery' => $gal, 'oh' => [['2026-11-14 13:00', 2]]]);
};
$row = function (int $lid) { ob_start(); do_action('ia_missing_video_row', ['ID' => $lid, 'title' => get_the_title($lid)]); return (string) ob_get_clean(); };
$rec = function (int $lid) { return ias_youtube_get($lid); };
$upload = function (int $lid, string $name = 'walkthrough.mp4', int $size = 268435456) use (&$S) { // what the row's script does: an address, the file PUT there, then "done"
  $r = ias_youtube_upload_start($lid, $name, $size, 'video/mp4', 'admin');
  if (!$r['ok']) return $r;
  $S['files'][$r['file_path']] = true;
  return ias_youtube_upload_done($lid, $r['file_path'], 'admin') + ['file_path' => $r['file_path']];
};
$sched = function (int $from) { return ias_t_oneup_calls($from, 'schedulevideopost'); };
$yt_on = function (bool $on) { $nets = (array) ias_setting('networks', []); $nets['youtube']['on'] = $on; ias_settings_update(['networks' => $nets]); };
$video = function (int $lid) { return (string) get_post_meta($lid, ia_acf_field_video(), true); };

ias_settings_update(['generate_per_run' => 30, 'oneup_category_id' => '42', 'oneup_account_id' => 'fb_123', 'master' => true]);
ias_oneup_verify('admin');
ias_settings_update(['types' => ['listing' => true]]);
$a = $mk('12 Alpha Road');

// ---- Nothing until a channel is chosen ----
t_ok(strpos($row($a), 'no YouTube channel chosen') !== false && strpos($row($a), 'page=ia-social-settings#ias-youtube') !== false && strpos($row($a), 'type="file"') === false, 'no YouTube channel chosen: the row says so and links to the setup — no button yet (0.22.2: never invisible)');
ias_oneup_directory_fetch('admin');
$acct = ias_oneup_directory_account($UC);
t_ok($acct && ias_oneup_type_is('youtube', (string) $acct['type']), 'Look up accounts finds the channel as a YouTube account');
$opts = ias_oneup_pick_options(['category' => '42', 'youtube' => ''])['ias-net-youtube'];
t_ok(strpos($opts, 'value="' . $UC . '"') !== false && strpos($opts, 'value="fb_123"') === false, 'Settings offers the channel to choose — and only YouTube accounts');
[$new] = ias_settings_sanitize(['networks' => ['youtube' => ['account' => $UC]]] + ias_settings(), ias_settings());
ias_settings_update(['networks' => $new['networks']]);
t_eq([$UC, $S['fb']], [ias_youtube_settings()['account'], ias_youtube_settings()['name']], 'the channel chosen, its name taken from OneUp\'s list (never typed)');
t_ok(strpos($row($a), 'Verify for YouTube has not passed') !== false && strpos($row($a), 'type="file"') === false, 'chosen but not verified: no upload, and the row says why');
t_ok(!ias_youtube_upload_start($a, 'walkthrough.mp4', 1000, 'video/mp4', 'admin')['ok'], '…nor can one be started');

// ---- Verify for YouTube ----
$c0 = count($S['calls']);
$v = ias_oneup_verify('admin', 'youtube');
$labels = array_column($v['steps'], 'label');
t_ok($v['ok'] && ias_youtube_verified(), 'Verify for YouTube passes (' . implode(' | ', array_map(function ($s) { return $s['label'] . ': ' . ($s['ok'] ? 'ok' : $s['detail']); }, $v['steps'])) . ')');
t_ok(in_array('The YouTube channel', $labels, true) && in_array('Save a draft (schedulevideopost, as a draft)', $labels, true) && in_array('Get an upload address (uploadmedia)', $labels, true) && in_array('Upload a test file there', $labels, true) && in_array('Read the channel\'s playlists (fetchytplaylist)', $labels, true), '…the channel, a video draft, an upload address with a test file sent there, and the playlists');
t_eq(['OPTIONS', 'PUT'], array_column(ias_t_oneup_calls($c0, 's3-put'), 'method'), '…OneUp\'s storage asked whether this site may upload there, then (it may) the test file PUT to the address as the browser will');
t_eq('direct', ias_youtube_route(), '…so each video goes straight from the browser');
$draft = $sched($c0)[0]['params'] ?? [];
t_ok(($draft['isDraftPost'] ?? '') === 'true' && !isset($draft['video_url']) && ($draft['title'] ?? '') !== '' && ($draft['social_network_id'] ?? '') === $UC, '…the draft: a video post to the channel, with a title and no file');
t_eq([], $S['drafts'], '…deleted again');
// A test draft an earlier Verify could not note (its answer carried no id, and OneUp listed it as "title~~~words",
// which 0.22.1 did not read): the next Verify finds it on the draft list by its own words and deletes it. A person's draft stays.
$S['drafts'][800001] = ['post_id' => 800001, 'content' => 'Intel Auctions Social connection test v8cE2f — a draft: it never publishes, and the plugin deletes it straight away.', 'title' => 'Intel Auctions Social connection test', 'date_time' => '2026-11-13 09:17:00', 'social_network_username' => (string) $S['yt'], 'category_name' => 'Intel', 'video_url' => 'NA', 'content_image' => 'NA', 'source_url' => 'NA'];
$S['drafts'][800002] = ['post_id' => 800002, 'content' => 'Through the rain, progress is being made — Intel Auctions Social connection test notes', 'date_time' => '2026-10-12 16:00:00', 'social_network_username' => 'George E. Ley Company (Glenmoore)', 'category_name' => 'GELCO', 'video_url' => 'NA', 'content_image' => 'NA', 'source_url' => 'NA'];
$c0 = count($S['calls']);
$v2 = ias_oneup_verify('admin', 'youtube');
$dels = array_map(function ($c) { return (string) $c['params']['post_id']; }, ias_t_oneup_calls($c0, 'deletescheduledpost'));
t_ok($v2['ok'] && in_array('800001', $dels, true) && !isset($S['drafts'][800001]), 'an old test draft nobody noted: found on the draft list by Verify\'s own words, deleted by its id');
t_ok(!in_array('800002', $dels, true) && isset($S['drafts'][800002]), '…a person\'s draft is never touched');
unset($S['drafts'][800002]);
$S['put_refused'] = true;
$bad = ias_oneup_verify('admin', 'youtube');
t_ok(!$bad['ok'] && !ias_youtube_verified() && strpos(implode(' ', array_column($bad['steps'], 'detail')), 'refused the test file') !== false, 'storage that refuses the upload: Verify for YouTube fails, and says so — before anyone tries a real video');
$S['put_refused'] = false;
// A storage no browser may upload to is not a dead end (0.22.2): the video goes through this website instead — the
// route Verify settles, tested by sending the test file that way. The whole preflight is checked (0.22.1).
$route_after = function (array $setup) use (&$S) {
  foreach ($setup as $k => $v) $S[$k] = $v;
  $c0 = count($S['calls']);
  $v = ias_oneup_verify('admin', 'youtube');
  return [$v['ok'], ias_youtube_route(), $v, ias_t_oneup_calls($c0, 'put-file')];
};
[$ok, $route, $v, $sent] = $route_after(['cors' => 'https://elsewhere.example']);
t_ok($ok && $route === 'site' && count($sent) === 1 && strpos(implode(' ', array_column($v['steps'], 'detail')), 'goes through this website in') !== false, 'storage a page on this site may not upload to (CORS): the website route — its test file went that way');
[$ok, $route] = $route_after(['cors' => '*', 'cors_methods' => 'GET, POST']);
t_ok($ok && $route === 'site', 'storage that allows this site but not a PUT: the website route');
[$ok, $route] = $route_after(['cors_methods' => 'GET, POST, PUT', 'cors_headers' => 'x-amz-acl']);
t_ok($ok && $route === 'site', '…nor a Content-Type header: the website route');
[$ok, $route] = $route_after(['cors_headers' => 'content-type', 'options_error' => true]);
t_ok($ok && $route === 'site', 'no answer to the preflight: the website route, which needs none');
[$ok, $route, $v] = $route_after(['options_error' => false, 'put_file_fail' => 1, 'cors' => 'https://elsewhere.example']);
t_ok(!$ok && strpos(implode(' ', array_column($v['steps'], 'detail')), 'refused the test file') !== false, 'the website route whose test send fails: Verify fails');
[$ok, $route] = $route_after(['cors' => '*']);
t_ok($ok && $route === 'direct', 'a storage that lets the browser upload: straight there again');
// …and the draft goes the way the videos will: a refused video post is never covered by a text draft.
$S['video_refused'] = true;
$c0 = count($S['calls']);
$bad = ias_oneup_verify('admin', 'youtube');
t_ok(!$bad['ok'] && ias_t_oneup_calls($c0, 'scheduletextpost') === [] && strpos(implode(' ', array_column($bad['steps'], 'detail')), 'Video posts are not enabled') !== false, 'OneUp refuses video posts: Verify for YouTube fails with its words — no text draft passes in its place');
$S['video_refused'] = false;
ias_oneup_verify('admin', 'youtube');
t_ok(ias_oneup_verified() && (string) get_option('ias_oneup_verify')['account'] === 'fb_123' && (string) get_option('ias_youtube_verify')['account'] === $UC, 'its own record: Facebook\'s Verify is untouched');
t_eq('Properties for auction', (string) (get_option('ias_youtube_playlists')['list'][0]['name'] ?? ''), 'the channel\'s playlists are kept for Settings');
[$new] = ias_settings_sanitize(['networks' => ['youtube' => ['account' => $UC, 'playlist' => 'PLtest00000000000000000000000001']]] + ias_settings(), ias_settings());
ias_settings_update(['networks' => $new['networks']]);
t_eq(['PLtest00000000000000000000000001', 'Properties for auction'], [ias_youtube_settings()['playlist'], ias_youtube_settings()['playlist_name']], 'a playlist chosen from that list, with its name');
[$new] = ias_settings_sanitize(['networks' => ['youtube' => ['account' => $UC, 'playlist' => 'PLtyped']]] + ias_settings(), ias_settings());
t_eq('', $new['networks']['youtube']['playlist'], 'a playlist id OneUp did not list is not kept (chosen, never typed)');
$_GET = ['page' => IAS_PAGE_SETTINGS]; ob_start(); ias_settings_render(); $set = (string) ob_get_clean(); $_GET = [];
t_ok(strpos($set, 'id="ias-youtube"') !== false && strpos($set, 'name="ias[networks][youtube][on]"') !== false && strpos($set, '>Properties for auction</option>') !== false, 'Settings has the YouTube switch, channel and playlist');
t_ok(!ias_youtube_settings()['on'] && in_array('YouTube is off', ias_youtube_blockers(), true), 'YouTube ships off');

// ---- The row: one button ----
$h = $row($a);
t_ok(strpos($h, 'Upload video file') !== false && strpos($h, 'type="file"') !== false, 'verified: the row has Upload video file');

// ---- Refused before anything reaches OneUp ----
$c0 = count($S['calls']);
t_ok(strpos(ias_youtube_upload_start($a, 'walkthrough.avi', 1000, 'video/x-msvideo', 'admin')['error'], '.mp4') !== false, 'not a video YouTube and Facebook both take: refused, asking for an .mp4');
t_ok(strpos(ias_youtube_upload_start($a, 'huge.mp4', 5 * 1024 * 1024 * 1024, 'video/mp4', 'admin')['error'], 'at most') !== false, 'over 4 GB: refused (Facebook\'s limit)');
t_eq([], ias_t_oneup_calls($c0, 'uploadmedia'), '…neither asked OneUp for an address');

// ---- Upload with YouTube off: the file is kept, nothing is sent ----
$c0 = count($S['calls']);
$r = $upload($a);
t_ok($r['ok'] && $rec($a)['state'] === 'ready', 'uploaded: the listing\'s video is ready (' . ($r['error'] ?: $r['message']) . ')');
t_ok(strpos($r['message'], 'switched off') !== false, '…and the person is told it goes when YouTube is switched on');
t_eq(1, count(ias_t_oneup_calls($c0, 'uploadmedia')), 'one upload address asked of OneUp');
t_eq(1, count(ias_t_oneup_calls($c0, 's3')), '…the file checked on OneUp\'s storage once the browser said it was up');
t_eq([], $sched($c0), 'YouTube off: nothing sent');
t_ok(strpos(ia_video_pending($a), 'Uploaded') === 0, 'the toolkit\'s row says the video is on its way (ia_video_pending)');
t_eq([], ia_rule_property_video() ? array_values(array_filter(ia_rule_property_video(), function ($x) use ($a) { return $x['slug'] === 'video.' . $a; })) : [], '…and no Next Step card asks for a video meanwhile');
t_ok(strpos($row($a), 'Upload a different file') !== false, 'the row offers a different file instead, until it is sent');
$f = ias_listing_facts($a);
t_ok($f['video_file'] && $f['video_file_url'] === $r['file_path'], 'Facebook\'s facts: the uploaded file is the property\'s video file');

// The file must really be on OneUp's storage.
$b = $mk('7 Bravo Lane');
$st = ias_youtube_upload_start($b, 'b.mp4', 1000, 'video/mp4', 'admin');
t_ok(!ias_youtube_upload_done($b, $st['file_path'] . 'x', 'admin')['ok'], 'a "done" for an address this listing was not given: refused');
$d = ias_youtube_upload_done($b, $st['file_path'], 'admin'); // never PUT
t_ok(!$d['ok'] && strpos($d['error'], 'did not arrive') !== false && empty($rec($b)['state']), 'the file never arrived on OneUp\'s storage: refused, nothing kept');

// ---- Switched on: it goes ----
$yt_on(true);
$c0 = count($S['calls']);
$cl = ias_t_claude_calls();
$changes = ias_youtube_check(true);
$call = $sched($c0)[0]['params'] ?? [];
$words = ias_youtube_words_for($a);
t_eq(1, count($sched($c0)), 'YouTube on: the ready video goes, once (' . implode(', ', $changes) . ')');
t_eq([$UC, '42', $r['file_path'], $words['title'], $words['text']], [$call['social_network_id'] ?? '', $call['category_id'] ?? '', $call['video_url'] ?? '', $call['title'] ?? '', $call['content'] ?? ''], '…to the channel, with the uploaded file, its title and description');
t_eq('2026-11-02 05:35', $call['scheduled_date_time'] ?? '', '…five minutes ahead');
t_eq(['playlist_name' => 'Properties for auction', 'playlist_id' => 'PLtest00000000000000000000000001'], json_decode((string) ($call['youtube'] ?? ''), true), '…into the playlist');
t_ok(strpos($call['title'] ?? '', '12 Alpha Road, Narvon, PA | Auction Nov ') === 0, 'the title: the address and the sale date (' . ($call['title'] ?? '') . ')');
t_ok(strpos($call['content'] ?? '', 'utm_source=youtube') !== false && strpos($call['content'] ?? '', '300,000') === false && strpos($call['content'] ?? '', 'Open house: ') !== false, 'the description: the listing link tagged for YouTube, the open house — never the starting bid');
t_ok(strpos($call['content'] ?? '', 'Intel Auctions LLC | 5940 Plank Road, Narvon, PA 17555 | 717-917-5844') !== false && strpos($call['content'] ?? '', 'youtube.com/channel/' . $UC . '?sub_confirmation=1') !== false, '…the name, address and phone, and a Subscribe link');
t_eq($cl, ias_t_claude_calls(), 'no Claude call for the words');
$y = $rec($a);
t_ok($y['state'] === 'sent' && $y['oneup']['id'] !== '' && empty($y['sending']), 'sent: OneUp\'s answer carried no id, so it was found on the scheduled list');
$oid = (int) $y['oneup']['id'];

// Facebook's read-back never takes it for a person's post, though the channel has the page's name.
ias_push_run();
t_eq([], ias_posts_query(['status' => 'human', 'limit' => 50]), 'Facebook\'s read-back: the video on the channel named like the page is not a person\'s post');

// ---- Published: the link goes on the listing ----
ias_t_clock('2026-11-02 05:40');
ias_t_oneup_publish($oid);
$changes = ias_youtube_check(true);
$y = $rec($a);
$want = 'https://www.youtube.com/watch?v=' . $S['yt_ids'][$oid];
t_ok($y['state'] === 'live' && $y['url'] === $want && $y['linked'] === 'listing', 'published: the video\'s id read from OneUp\'s published list, the link kept (' . implode(', ', $changes) . ')');
t_eq($want, $video($a), '…and put on the listing\'s video field (the toolkit\'s ia_video_save())');
t_eq('', ia_video_pending($a), '…the row no longer says it is on its way');
$f = ias_listing_facts($a);
t_ok($f['video_link'] === $want && $f['video_file_url'] === $r['file_path'], 'Facebook now has both: the YouTube link and the file');
ias_sync();
[$comment] = ias_first_comment(['type' => 'listing', 'id' => 0, 'listing_ids' => [$a]], [ias_listing_facts($a)]);
t_ok(strpos($comment, 'Video walkthrough: ' . $want) !== false, 'the Listing post\'s first comment carries the YouTube link (to the channel)');
$ohp = ['type' => 'open_house', 'id' => 0, 'slot_key' => 'open_house:' . $a . ':20261114', 'flags' => [], 'listing_ids' => [$a], 'listing_id' => $a];
$media = ias_media_for_post($ohp, [ias_listing_facts($a)]);
t_ok(($media['kind'] ?? '') === 'video' && $media['video_url'] === $r['file_path'], 'an open house\'s post carries the uploaded file as its video, natively');
[$ohc] = ias_first_comment($ohp, [ias_listing_facts($a)]);
t_ok(strpos($ohc, 'Video walkthrough') === false, '…so its comment does not link the video again');

// ---- The id from OneUp's YouTube analytics, else the channel's feed ----
$S['yt_pub_id'] = 'oneup'; // the published row's post_id is OneUp's number, not a video id
$c = $mk('3 Charlie Court');
$upload($c);
$oid = (int) $rec($c)['oneup']['id'];
ias_t_clock('2026-11-02 05:50');
ias_t_oneup_publish($oid);
$S['yt_stats'] = [['id' => 'zzANALYTICS', 'title' => 'Another video', 'permalink' => 'https://www.youtube.com/watch?v=zzANALYTICS']];
ias_youtube_check(true);
t_ok($rec($c)['state'] === 'live' && empty($rec($c)['url']) && $video($c) === '', 'published, but no id anywhere yet: live, the link still being looked up — nothing guessed');
$S['yt_stats'][] = ['id' => 'abcANALYTIC', 'title' => $rec($c)['oneup']['title'], 'permalink' => 'https://www.youtube.com/watch?v=abcANALYTIC'];
ias_youtube_check(true);
t_eq('https://www.youtube.com/watch?v=abcANALYTIC', $video($c), 'found in OneUp\'s YouTube analytics by its title: on the listing');

$dd = $mk('9 Delta Drive');
$upload($dd);
$oid = (int) $rec($dd)['oneup']['id'];
ias_t_clock('2026-11-02 06:00');
ias_t_oneup_publish($oid);
$S['feed'] = ['abcFEED0001' => $rec($dd)['oneup']['title'], 'otherVIDEO1' => 'Something else'];
ias_youtube_check(true);
t_eq('https://www.youtube.com/watch?v=abcFEED0001', $video($dd), 'not in the analytics yet: found in the channel\'s public feed by its title');
$S['yt_pub_id'] = 'video';

// ---- A person's link wins ----
$e = $mk('4 Echo Way');
$upload($e);
$oid = (int) $rec($e)['oneup']['id'];
ia_video_save($e, 'https://vimeo.com/123456789', 'admin');
ias_t_clock('2026-11-02 06:10');
ias_t_oneup_publish($oid);
ias_youtube_check(true);
t_ok($rec($e)['state'] === 'live' && $rec($e)['linked'] === 'kept' && $video($e) === 'https://vimeo.com/123456789', 'a person put a link on the listing meanwhile: it stays; the YouTube link is only recorded');

// ---- The listing must be public ----
$g = $mk('5 Golf Street', ['status' => 'draft']);
$c0 = count($S['calls']);
$r = $upload($g);
t_ok($r['ok'] && $rec($g)['state'] === 'ready' && $sched($c0) === [] && strpos($r['message'], 'once the listing is published') !== false, 'a draft listing: the file is kept, and it waits for the listing to be published (the description links to it)');
wp_update_post(['ID' => $g, 'post_status' => 'publish']);
ias_youtube_check(true);
t_eq('sent', $rec($g)['state'], '…published: it goes on the next check');

// ---- Never on a Sunday ----
$h8 = $mk('8 Hotel Road');
ias_t_clock('2026-11-08 15:00'); // Sunday
$c0 = count($S['calls']);
$r = $upload($h8);
t_ok($rec($h8)['state'] === 'ready' && $sched($c0) === [] && strpos($r['message'], 'on Monday') !== false, 'uploaded on a Sunday: nothing goes to YouTube, and the person is told it goes on Monday');
ias_job_run();
t_eq([], $sched($c0), '…not even with the hourly run');
ias_t_clock('2026-11-09 06:05'); // Monday
ias_job_run();
t_ok($rec($h8)['state'] === 'sent' && substr((string) $rec($h8)['oneup']['when'], 0, 10) === '2026-11-09', 'Monday\'s run sends it');

// ---- A lost answer: looked for, never sent twice ----
$i9 = $mk('6 India Lane');
$S['lose_answer'] = 1;
$c0 = count($S['calls']);
$r = $upload($i9);
t_ok(!empty($rec($i9)['sending']) && $rec($i9)['state'] === 'ready', 'sent, but OneUp\'s answer was lost: the try is kept');
ias_youtube_check(true);
t_ok($rec($i9)['state'] === 'sent' && empty($rec($i9)['sending']) && count($sched($c0)) === 1, '…the next check finds it on OneUp and keeps it: never sent twice');

$j = $mk('2 Juliet Court');
$S['lose_answer'] = 1;
$c0 = count($S['calls']);
$upload($j);
$try = $rec($j)['sending'];
foreach ($S['scheduled'] as $k => $rw) if (($rw['content'] ?? '') === $try['text']) unset($S['scheduled'][$k]); // it never reached OneUp
ias_youtube_check(true);
t_ok($rec($j)['state'] === 'ready' && !empty($rec($j)['sending']) && count($sched($c0)) === 1, 'not found at once: still looked for, not sent again');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 31 * 60));
ias_youtube_check(true);
t_ok($rec($j)['state'] === 'lost' && count($sched($c0)) === 1, 'half an hour on, still not there: lost — the plugin never sends it again by itself');
t_ok(strpos($row($j), 'Send it again') !== false && strpos($row($j), 'Check the channel first') !== false, 'the row asks a person to check the channel, then Send it again');
t_ok(!ias_youtube_upload_start($j, 'again.mp4', 1000, 'video/mp4', 'admin')['ok'], '…and no new file can be uploaded over it meanwhile (it may be on YouTube already)');
$rt = ias_youtube_retry($j, 'admin');
t_ok($rt['ok'] && $rec($j)['state'] === 'sent' && count($sched($c0)) === 2, 'Send it again: sent, by the person who chose to');

// ---- Refused, and failed on OneUp ----
$k = $mk('1 Kilo Road');
$S['fail_next'] = 3;
$r = $upload($k);
t_ok(strpos($r['message'], 'OneUp did not take it last time') !== false && strpos(ia_video_pending($k), 'did not take it') !== false, 'refused: the person is told so, and that it is tried again — never "in a moment"');
$c0 = count($S['calls']);
ias_youtube_check(true);
t_eq([], $sched($c0), '…not again at once (an open calendar checks every minute)');
foreach ([1, 2] as $_) { ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 16 * 60)); ias_youtube_check(true); }
t_ok($rec($k)['state'] === 'failed' && strpos((string) $rec($k)['error'], 'token') !== false, 'refused three times: failed, with OneUp\'s reason');
t_ok(strpos($row($k), 'Try again') !== false, '…Try again on the row');
ias_youtube_retry($k, 'admin');
t_eq('sent', $rec($k)['state'], 'Try again: sent');
$oid = (int) $rec($k)['oneup']['id'];
$S['failed'][$oid] = $S['scheduled'][$oid] + ['fail_reason' => 'The video is too long for this channel.'];
unset($S['scheduled'][$oid]);
ias_youtube_check(true);
t_ok($rec($k)['state'] === 'failed' && $rec($k)['error'] === 'The video is too long for this channel.', 'OneUp could not publish it: failed, with its fail_reason');

// ---- Off means off ----
$l = $mk('11 Lima Lane');
$upload($l);
$oid = (string) $rec($l)['oneup']['id'];
$yt_on(false);
$c0 = count($S['calls']);
ias_youtube_check(true);
$mine = function (int $from, string $oid) { return array_values(array_filter(ias_t_oneup_calls($from, 'deletescheduledpost'), function ($c) use ($oid) { return (string) $c['params']['post_id'] === $oid; })); };
t_ok(count($mine($c0, $oid)) === 1 && !isset($S['scheduled'][(int) $oid]), 'YouTube switched off before it went out: taken back off OneUp, by its id');
t_eq([], array_values(array_filter(ias_youtube_all(), function ($x) { return ($x['state'] ?? '') === 'sent'; })), '…as is every video still on OneUp\'s schedule');
t_ok($rec($l)['state'] === 'ready' && !empty($rec($l)['was_on_oneup']), '…ready again');
$yt_on(true);
ias_youtube_check(true);
t_eq('sent', $rec($l)['state'], 'switched on: it goes again');
$oid = (string) $rec($l)['oneup']['id'];
ias_settings_update(['master' => false]);
$c0 = count($S['calls']);
ias_youtube_check(true);
t_ok(count($mine($c0, $oid)) === 1 && ias_t_oneup_calls($c0, 'getscheduledposts') === [] && $rec($l)['state'] === 'ready', 'the master switch off: taken back by its id, with no list read first');
$c0 = count($S['calls']);
ias_youtube_check(true);
t_eq([], ias_t_oneup_calls($c0), '…and then not one call');
ias_settings_update(['master' => true]);

// ---- An upload never finished ----
$m = $mk('13 Mike Road');
$st = ias_youtube_upload_start($m, 'm.mp4', 1000, 'video/mp4', 'admin');
t_eq('uploading', $rec($m)['state'], 'an upload started');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 3 * 3600));
ias_youtube_check(true);
t_ok(empty($rec($m)['state']) && strpos($row($m), 'Upload video file') !== false, 'never finished: forgotten two hours on, and the row offers the upload again');

// ---- OneUp's Basic plan has no uploads ----
$S['upload_code'] = 403;
$no = ias_youtube_upload_start($m, 'm.mp4', 1000, 'video/mp4', 'admin');
t_ok(!$no['ok'] && strpos($no['error'], 'Basic plan') !== false, 'OneUp refuses an upload address (its Basic plan): said, nothing kept');
$S['upload_code'] = 200;

// ---- The toolkit's row and the listing panel say where it stands ----
ob_start(); ia_missing_video_render_row(['ID' => $l, 'title' => '11 Lima Lane', 'status' => 'publish', 'stage' => 'upcoming', 'auction_date' => null, 'edit_link' => '', 'view_link' => '', 'skipped' => false], new DateTimeImmutable('now', ia_wp_tz())); $tr = (string) ob_get_clean();
t_ok(strpos($tr, 'On its way') !== false && strpos($tr, 'sent to YouTube') !== false, 'the toolkit\'s row: "On its way", in Social\'s words');
ob_start(); ias_listing_panel_render(get_post($a)); $panel = (string) ob_get_clean();
t_ok(strpos($panel, 'Property video:') !== false && strpos($panel, 'On YouTube: ') !== false, 'the listing\'s Social panel says it is on YouTube, with its link');
t_ok(count(ias_log_recent('video', 200)) >= 5, 'each step is in the Social log');

// ---- 0.22.1, from the Codex review of PR #10 ----
$scheduled_ids = function () use (&$S) { return array_map('strval', array_keys($S['scheduled'])); };
// A listing withdrawn after its video was sent: the video comes back off, and goes again when it is public.
$n1 = $mk('21 November Road');
$upload($n1);
$oid = (string) $rec($n1)['oneup']['id'];
wp_update_post(['ID' => $n1, 'post_status' => 'draft']);
$c0 = count($S['calls']);
ias_youtube_check(true);
t_ok(count($mine($c0, $oid)) === 1 && $rec($n1)['state'] === 'ready' && !in_array($oid, $scheduled_ids(), true), 'the listing is unpublished after its video was sent: the video is taken back off OneUp, by its id');
t_ok(strpos(implode(' | ', array_column($rec($n1)['trail'], 'what')), 'the listing is no longer public') !== false, '…and its history says why');
wp_update_post(['ID' => $n1, 'post_status' => 'publish']);
ias_youtube_check(true);
t_eq('sent', $rec($n1)['state'], '…published again: it goes again');

// Switched off after its scheduled minute, OneUp running late: still taken back by its id.
$o1 = $mk('22 Oscar Lane');
$upload($o1);
$oid = (string) $rec($o1)['oneup']['id'];
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 10 * 60)); // past its time; OneUp has not published it yet
ias_settings_update(['master' => false]);
$c0 = count($S['calls']);
ias_youtube_check(true);
t_ok(count($mine($c0, $oid)) === 1 && $rec($o1)['state'] === 'ready', 'the master switch off after its scheduled minute, still on OneUp\'s schedule: taken back all the same');
ias_settings_update(['master' => true]);
ias_youtube_check(true);

// Already out when it is switched off: never taken for taken back (it would go twice), not asked about again while off.
$p1 = $mk('23 Papa Court');
$upload($p1);
$oid = (string) $rec($p1)['oneup']['id'];
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 10 * 60));
ias_t_oneup_publish((int) $oid);
$yt_on(false);
$c0 = count($S['calls']);
ias_youtube_check(true);
t_ok(count($mine($c0, $oid)) === 1 && $rec($p1)['state'] === 'live' && empty($rec($p1)['was_on_oneup']), 'YouTube switched off, but OneUp no longer has it scheduled (it went out): never marked to go again — the same run\'s read-back finds it published');
$sent_for = function (int $from, string $street) { return array_values(array_filter(ias_t_oneup_calls($from, 'schedulevideopost'), function ($c) use ($street) { return strpos((string) $c['params']['title'], $street . ',') === 0; })); };
$yt_on(true);
$c0 = count($S['calls']);
ias_youtube_check(true);
t_eq([], $sent_for($c0, '23 Papa Court'), '…and switched back on, it is not sent a second time');

$p2 = $mk('23A Papa Court');
$upload($p2);
$oid = (string) $rec($p2)['oneup']['id'];
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 10 * 60));
ias_t_oneup_publish((int) $oid);
ias_settings_update(['master' => false]);
$c0 = count($S['calls']);
ias_youtube_check(true);
t_ok(count($mine($c0, $oid)) === 1 && $rec($p2)['state'] === 'sent' && !empty($rec($p2)['tb_missed']), 'the master switch off (no read-back), and OneUp no longer has it scheduled: it stays sent, noted — never marked to go again');
$c0 = count($S['calls']);
ias_youtube_check(true);
t_eq([], $mine($c0, $oid), '…and it is not asked about again while things stay off');
ias_settings_update(['master' => true]);
$c0 = count($S['calls']);
ias_youtube_check(true);
t_ok($rec($p2)['state'] === 'live' && !empty($rec($p2)['url']) && $sent_for($c0, '23A Papa Court') === [], 'back on: the read-back finds it published, its link goes on the listing, and it is not sent again');

// On none of OneUp's lists a day after its time (deleted in OneUp): a person checks the channel.
$q1 = $mk('24 Quebec Street');
$upload($q1);
unset($S['scheduled'][(int) $rec($q1)['oneup']['id']]);
ias_youtube_check(true);
t_eq('sent', $rec($q1)['state'], 'gone from OneUp\'s lists within the day: still waited for');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 25 * 3600));
if (date('N', strtotime(ias_now_sql())) === '7') ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 86400));
ias_youtube_check(true);
t_ok($rec($q1)['state'] === 'lost' && strpos($row($q1), 'Send it again') !== false, 'on none of OneUp\'s lists a day after its time: lost — the row asks a person to check the channel');

// The listing's video field will not take the link: not linked, tried again.
$r1 = $mk('25 Romeo Road');
$upload($r1);
$oid = (int) $rec($r1)['oneup']['id'];
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 10 * 60));
ias_t_oneup_publish($oid);
$refuse = function ($check, $object_id, $meta_key) use ($r1) { return ((int) $object_id === $r1 && $meta_key === ia_acf_field_video()) ? false : $check; };
add_filter('update_post_metadata', $refuse, 10, 3);
add_filter('add_post_metadata', $refuse, 10, 3);
ias_youtube_check(true);
t_ok($rec($r1)['state'] === 'live' && empty($rec($r1)['url']) && (int) ($rec($r1)['link_fails'] ?? 0) === 1 && $video($r1) === '', 'the listing\'s video field refuses the link (ia_video_save() reads it back): not counted as linked — tried again');
remove_filter('update_post_metadata', $refuse, 10);
remove_filter('add_post_metadata', $refuse, 10);
ias_youtube_check(true);
t_ok($rec($r1)['linked'] === 'listing' && strpos($video($r1), 'youtube.com/watch?v=') !== false, '…and the next check puts it on the listing');

// YouTube alone keeps the open calendar's minute-by-minute check going.
ias_settings_update(['oneup_account_id' => '']);
t_ok(ias_oneup_blockers(false) && ias_pulse_pill() !== '', 'no Facebook page set, Google off, YouTube set up: the calendar still checks OneUp every minute');
ias_settings_update(['oneup_account_id' => 'fb_123']);

// ---- The live account's storage (DigitalOcean, Oct 14, 2026): no browser may upload there, so through this website ----
ias_t_clock(date('Y-m-d', strtotime('next monday', strtotime(ias_now_sql()))) . ' 09:00');
ias_settings_update(['master' => true, 'paused' => false]);
$yt_on(true);
$S['storage'] = 'spaces';
$tmpdir = ias_youtube_tmp_dir();
$c0 = count($S['calls']);
$v = ias_oneup_verify('admin', 'youtube');
$up_step = (string) (array_values(array_filter($v['steps'], function ($x) { return $x['label'] === 'Upload a test file there'; }))[0]['detail'] ?? '');
t_ok($v['ok'] && ias_youtube_route() === 'site' && strpos($up_step, 'oneupdata.sfo3.digitaloceanspaces.com') !== false, 'Verify on the live account\'s storage: it passes, through this website (' . $up_step . ')');
$pf = ias_t_oneup_calls($c0, 'put-file');
t_ok(count($pf) === 1 && strlen((string) $pf[0]['params']['body']) === 42 && strpos((string) $pf[0]['params']['url'], 'digitaloceanspaces.com/api_') !== false, '…its 42-byte test file sent from the website to the address OneUp gave');
t_eq([], (array) glob($tmpdir . '/*.part'), '…and nothing left behind in the website\'s folder');

$t1 = $mk('27 Tango Terrace');
$ch = ias_youtube_chunk_size(); // 8 MB, or less where PHP takes less (this test's PHP takes 2 MB)
$bytes = random_bytes(2 * $ch + 1000);
$size = strlen($bytes);
$piece = function (int $off, int $len) use ($bytes) { $f = wp_tempnam('piece'); file_put_contents($f, substr($bytes, $off, $len)); return $f; };
$c0 = count($S['calls']);
$st = ias_youtube_upload_start($t1, 'walkthrough.mp4', $size, 'video/mp4', 'admin');
t_eq($st['key'], sanitize_key($st['key']), 'the upload\'s key comes back through sanitize_key() unchanged, as the AJAX steps read it');
t_ok($st['ok'] && $st['route'] === 'site' && $st['upload_url'] === '' && $st['chunk'] === $ch && $ch <= IAS_YOUTUBE_CHUNK && $ch >= MB_IN_BYTES && ias_t_oneup_calls($c0, 'uploadmedia') === [], 'started through the website: no address asked of OneUp yet (one lasts 20 minutes, and a big file over rural internet takes longer)');
$r = ias_youtube_upload_chunk($t1, 0, $piece(0, $ch), $st['key']);
t_ok($r['ok'] && $r['have'] === $ch, 'the first piece is kept');
$r = ias_youtube_upload_chunk($t1, 0, $piece(0, $ch), $st['key']);
t_ok($r['ok'] && $r['have'] === $ch, 'the same piece again (its answer was lost): taken as already here, never written twice');
$r = ias_youtube_upload_chunk($t1, 2 * $ch, $piece(2 * $ch, 1000), $st['key']);
t_ok(!$r['ok'] && $r['have'] === $ch, 'a piece out of step: the website says how much it has, and the browser carries on from there');
ias_youtube_upload_chunk($t1, $ch, $piece($ch, $ch), $st['key']);
$r = ias_youtube_upload_chunk($t1, 2 * $ch, $piece(2 * $ch, 1000), $st['key']);
t_ok($r['ok'] && $r['have'] === $size, 'the last piece: the whole file is on the website');
t_eq(md5($bytes), md5_file(ias_youtube_tmp_path($rec($t1))), '…byte for byte');
$d = ias_youtube_upload_done($t1, '', 'admin', $st['key']);
t_ok($d['ok'] && $d['moving'] && $rec($t1)['state'] === 'uploading' && !empty($rec($t1)['xfer']), 'done: marked to be sent on to OneUp\'s storage (' . $d['message'] . ')');
$d2 = ias_youtube_upload_done($t1, '', 'admin', $st['key']);
t_ok($d2['ok'] && $d2['moving'], '"done" said again (its answer was lost): answered as the first, never as an error');
t_ok(!ias_youtube_upload_done($t1, '', 'admin', 'wrongkey')['ok'], '…but never for another upload\'s key');
// The send-on is one at a time: while another holds it, nothing is sent.
ias_youtube_xfer_lock($t1);
$c0 = count($S['calls']);
$x = ias_youtube_transfer($t1, 'admin');
t_ok(!$x['ok'] && strpos($x['error'], 'being sent on already') !== false && ias_t_oneup_calls($c0, 'put-file') === [] && (int) $rec($t1)['xfer']['tries'] === 0, 'while another run sends it on (the lock is held): nothing sent, no try used');
ias_youtube_xfer_unlock($t1);
t_ok(strpos(ia_video_pending($t1), 'on its way to OneUp') !== false && strpos($row($t1), 'being sent on now') !== false && strpos($row($t1), 'type="file"') === false, 'the row and the toolkit say it is on its way — no upload button meanwhile');
t_ok(!ias_youtube_upload_start($t1, 'other.mp4', 1000, 'video/mp4', 'admin')['ok'], '…and no other file can start over it');
$c0 = count($S['calls']);
$x = ias_youtube_transfer($t1, 'admin');
$pf = ias_t_oneup_calls($c0, 'put-file');
t_ok($x['ok'] && count(ias_t_oneup_calls($c0, 'uploadmedia')) === 1 && count($pf) === 1 && $pf[0]['params']['size'] === $size && $pf[0]['params']['type'] === 'video/mp4', 'sent on: a fresh address from OneUp, the whole file sent from the website as video/mp4 (' . ($x['error'] ?: $x['message']) . ')');
t_ok(strpos((string) $rec($t1)['file'], 'digitaloceanspaces.com/api_') !== false && $rec($t1)['state'] === 'sent' && empty($rec($t1)['tmp']), 'on OneUp\'s storage, then on to YouTube as any upload');
t_eq([], (array) glob($tmpdir . '/*.part'), '…and the file on the website is deleted');
$s1 = $sched($c0);
t_ok(count($s1) === 1 && $s1[0]['params']['video_url'] === $rec($t1)['file'], 'YouTube gets the file at its public address on OneUp\'s storage');
ias_youtube_check(true);
t_ok((string) ($rec($t1)['oneup']['id'] ?? '') !== '' && $rec($t1)['state'] === 'sent', 'found on OneUp\'s schedule, where it is listed as "title~~~description" with no id in OneUp\'s answer: its id is kept, so it can be taken back');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 10 * 60));
ias_t_oneup_publish((int) $rec($t1)['oneup']['id']);
ias_youtube_check(true);
t_ok($rec($t1)['state'] === 'live' && strpos($video($t1), 'youtube.com/watch?v=') !== false, 'published: found on the published list, and its link goes on the listing');

// Sending on fails: tried again by the hourly run, three tries in all, then let go with the reason on the row.
$u1 = $mk('28 Uniform Way');
$small = random_bytes(1000);
$su = ias_youtube_upload_start($u1, 'walkthrough.mp4', 1000, 'video/mp4', 'admin');
$f = wp_tempnam('piece'); file_put_contents($f, $small);
ias_youtube_upload_chunk($u1, 0, $f, $su['key']);
ias_youtube_upload_done($u1, '', 'admin', $su['key']);
$S['put_file_fail'] = 3;
$x = ias_youtube_transfer($u1, 'admin');
t_ok(!$x['ok'] && $rec($u1)['state'] === 'uploading' && (int) $rec($u1)['xfer']['tries'] === 1 && strpos($row($u1), 'Tried again within the hour') !== false, 'sending on fails: kept, and the row says it is tried again within the hour');
t_eq([], ias_youtube_transfers_due(), '…not again within ten minutes');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 11 * 60));
ias_youtube_transfers_due();
t_eq(2, (int) $rec($u1)['xfer']['tries'], '…the hourly run tries again');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 11 * 60));
ias_youtube_transfers_due();
t_ok(($rec($u1)['state'] ?? '') === '' && strpos((string) $rec($u1)['note'], 'Could not be sent on') !== false && strpos($row($u1), 'Could not be sent on') !== false && strpos($row($u1), 'type="file"') !== false && !ias_youtube_tmp_path($rec($u1)), 'three tries: the upload is let go, its file deleted, the reason and the upload button on the row');
$S['put_file_fail'] = 0;

// Half an upload is never sent on; an upload left halfway is let go two hours on, its file deleted.
$h1 = $mk('29 Victor Road');
$sh = ias_youtube_upload_start($h1, 'walkthrough.mp4', 2000, 'video/mp4', 'admin');
$f = wp_tempnam('piece'); file_put_contents($f, $small);
ias_youtube_upload_chunk($h1, 0, $f, $sh['key']);
$d = ias_youtube_upload_done($h1, '', 'admin', $sh['key']);
t_ok(!$d['ok'] && strpos($d['error'], 'Only part') !== false && ($rec($h1)['state'] ?? '') === '', '"done" with only part of the file here: not sent on — upload it again');
$w1 = $mk('30 Whiskey Lane');
$sw = ias_youtube_upload_start($w1, 'walkthrough.mp4', 2000, 'video/mp4', 'admin');
$f = wp_tempnam('piece'); file_put_contents($f, $small);
ias_youtube_upload_chunk($w1, 0, $f, $sw['key']);
$wtmp = ias_youtube_tmp_path($rec($w1));
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 121 * 60));
ias_youtube_check(false);
t_ok(($rec($w1)['state'] ?? '') === '' && !file_exists($wtmp), 'an upload left halfway: let go two hours on, and its file on the website deleted');

// Not enough room on the website for the file: refused before anything is written.
$room = function () { return 500 * MB_IN_BYTES; };
add_filter('ias_youtube_free_space', $room);
$r = ias_youtube_upload_start($w1, 'walkthrough.mp4', 2 * 1024 * MB_IN_BYTES, 'video/mp4', 'admin');
t_ok(!$r['ok'] && strpos($r['error'], 'not enough to hold') !== false, 'not enough room on the website for the file: refused at once, saying so');
remove_filter('ias_youtube_free_space', $room);

// Two uploads for one listing (two tabs): the second takes over, and the first one's pieces never go into its file.
$x1 = $mk('31 X-ray Street');
$a1 = ias_youtube_upload_start($x1, 'first.mp4', 3000, 'video/mp4', 'admin');
$f = wp_tempnam('piece'); file_put_contents($f, str_repeat('A', 1000));
ias_youtube_upload_chunk($x1, 0, $f, $a1['key']);
$a2 = ias_youtube_upload_start($x1, 'second.mp4', 3000, 'video/mp4', 'admin');
$f = wp_tempnam('piece'); file_put_contents($f, str_repeat('A', 1000));
$late = ias_youtube_upload_chunk($x1, 1000, $f, $a1['key']);
t_ok(!$late['ok'] && $late['have'] === -1 && filesize(ias_youtube_tmp_path($rec($x1))) === 0, 'the first tab\'s next piece is refused outright (no "carry on from"), and the second upload\'s file is untouched');
$f = wp_tempnam('piece'); file_put_contents($f, str_repeat('B', 1000));
t_ok(ias_youtube_upload_chunk($x1, 0, $f, $a2['key'])['ok'], '…the second upload goes on');

// A send-on cut short (a time limit, a deploy): the try was counted before the send, so three of them let the upload go.
$y1 = $mk('32 Yankee Drive');
$sy = ias_youtube_upload_start($y1, 'walkthrough.mp4', 1000, 'video/mp4', 'admin');
$f = wp_tempnam('piece'); file_put_contents($f, $small);
ias_youtube_upload_chunk($y1, 0, $f, $sy['key']);
ias_youtube_upload_done($y1, '', 'admin', $sy['key']);
$S['put_file_die'] = 3;
foreach ([1, 2, 3] as $n) {
  try { ias_youtube_transfer($y1, 'admin'); } catch (\RuntimeException $e) { /* the process died mid-send */ }
  if ($n < 3) t_eq($n, (int) $rec($y1)['xfer']['tries'], 'cut short ' . $n . ': the try is counted all the same');
}
t_ok(!ias_youtube_xfer_locked($y1), '…and the lock is let go each time');
ias_t_clock(date('Y-m-d H:i', strtotime(ias_now_sql()) + 11 * 60));
ias_youtube_transfers_due();
t_ok(($rec($y1)['state'] ?? '') === '' && strpos((string) $rec($y1)['note'], 'Could not be sent on') !== false, 'three tries cut short: let go with the reason on the row — never retried every hour for ever');
$S['put_file_die'] = 0;

// Once a day, files left in the website's folder that no upload owns are deleted; one an upload still owns stays.
$z1 = $mk('33 Zulu Court');
$sz = ias_youtube_upload_start($z1, 'walkthrough.mp4', 5000, 'video/mp4', 'admin');
$owned = ias_youtube_tmp_path($rec($z1));
$stray = $tmpdir . '/' . str_repeat('s', 32) . '.part';
file_put_contents($stray, 'left behind');
touch($stray, time() - 2 * DAY_IN_SECONDS); touch($owned, time() - 2 * DAY_IN_SECONDS);
delete_transient('ias_youtube_tmp_sweep');
ias_youtube_transfers_due();
t_ok(!file_exists($stray) && file_exists($owned), 'the daily sweep: a stray file a day old is deleted, an upload\'s own file stays');
$fresh = $tmpdir . '/' . str_repeat('t', 32) . '.part'; file_put_contents($fresh, 'new');
delete_transient('ias_youtube_tmp_sweep');
ias_youtube_transfers_due();
t_ok(file_exists($fresh), '…a stray file less than a day old stays (an upload may just be starting)');
@unlink($fresh);

// Pieces never bigger than PHP takes.
$lim = function () { return 512 * KB_IN_BYTES; };
add_filter('upload_size_limit', $lim);
t_eq(448 * KB_IN_BYTES, ias_youtube_chunk_size(), 'PHP takes 512 KB: pieces of 448 KB (under it, with room for the form)');
remove_filter('upload_size_limit', $lim);

// A Facebook or Google post whose own words carry "~~~" is read as it is: only YouTube's rows are split.
$S['scheduled'][880001] = ['post_id' => 880001, 'content' => "Big news ~~~ more to come", 'date_time' => '2026-12-01 09:00:00', 'social_network_username' => (string) $S['fb'], 'category_name' => 'Intel', 'video_url' => 'NA', 'content_image' => 'NA', 'source_url' => 'NA'];
$listed = array_values(array_filter(ias_oneup_list('scheduled', 5)['rows'], function ($r) { return (string) ($r['post_id'] ?? '') === '880001'; }));
t_ok(($listed[0]['content'] ?? '') === 'Big news ~~~ more to come' && !isset($listed[0]['title']), 'a Facebook post with "~~~" in its words is listed whole — never cut, never taken for a person\'s edit');
unset($S['scheduled'][880001]);
$S['storage'] = 's3';

// A listing deleted for good while this request still holds the list: its record goes with it, and no write brings it back.
$s1 = $mk('26 Sierra Way');
$upload($s1);
t_ok(isset(ias_youtube_all()[$s1]), 'a listing with a video is on the list');
wp_delete_post($s1, true);
t_ok(!isset(ias_youtube_all()[$s1]), '…deleted for good: off the list at once, though this request had read it');
ias_youtube_put($s1, ['state' => 'sent', 'trail' => []], 'a late write');
global $wpdb;
t_eq(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $s1)), '…and a late write leaves nothing behind on the missing post');

ias_t_oneup_off();
ias_t_claude_off();
ias_settings_update(['master' => false, 'types' => ['listing' => false], 'networks' => array_merge((array) ias_setting('networks', []), ['youtube' => ['on' => false, 'account' => '', 'name' => '', 'playlist' => '', 'playlist_name' => '']])]);
delete_option('ias_oneup_verify');
delete_option('ias_youtube_verify');
delete_option('ias_youtube_playlists');
delete_option('ias_oneup_directory');
