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
t_eq('', $row($a), 'no YouTube channel chosen: the Property Videos row stays as the toolkit has it');
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
t_eq(['PUT', 'OPTIONS'], array_column(ias_t_oneup_calls($c0, 's3-put'), 'method'), '…the test file PUT to the address as the browser will, and OneUp\'s storage asked whether this site may upload there');
$draft = $sched($c0)[0]['params'] ?? [];
t_ok(($draft['isDraftPost'] ?? '') === 'true' && !isset($draft['video_url']) && ($draft['title'] ?? '') !== '' && ($draft['social_network_id'] ?? '') === $UC, '…the draft: a video post to the channel, with a title and no file');
t_eq([], $S['drafts'], '…deleted again');
$S['put_refused'] = true;
$bad = ias_oneup_verify('admin', 'youtube');
t_ok(!$bad['ok'] && !ias_youtube_verified() && strpos(implode(' ', array_column($bad['steps'], 'detail')), 'refused the test file') !== false, 'storage that refuses the upload: Verify for YouTube fails, and says so — before anyone tries a real video');
$S['put_refused'] = false;
$S['cors'] = 'https://elsewhere.example';
$bad = ias_oneup_verify('admin', 'youtube');
t_ok(!$bad['ok'] && strpos(implode(' ', array_column($bad['steps'], 'detail')), 'does not let a page on') !== false, 'storage a page on this site may not upload to (CORS): fails too');
$S['cors'] = '*';
// 0.22.1, from the Codex review of PR #10: the whole preflight is checked, and no answer is not a pass.
$S['cors_methods'] = 'GET, POST';
$bad = ias_oneup_verify('admin', 'youtube');
t_ok(!$bad['ok'] && strpos(implode(' ', array_column($bad['steps'], 'detail')), 'a PUT with a Content-Type') !== false, 'storage that allows this site but not a PUT: Verify fails (the browser could not upload)');
$S['cors_methods'] = 'GET, POST, PUT'; $S['cors_headers'] = 'x-amz-acl';
t_ok(!ias_oneup_verify('admin', 'youtube')['ok'], '…nor a Content-Type header: fails');
$S['cors_headers'] = 'content-type'; $S['options_error'] = true;
$bad = ias_oneup_verify('admin', 'youtube');
t_ok(!$bad['ok'] && strpos(implode(' ', array_column($bad['steps'], 'detail')), 'did not answer whether') !== false, 'no answer to the preflight: not a pass — run Verify again');
$S['options_error'] = false;
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
