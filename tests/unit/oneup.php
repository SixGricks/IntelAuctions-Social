<?php
/**
 * Reading OneUp's answers, whatever their exact shape (oneup.php): the rows
 * of a listing, the post id in a scheduling answer, and an error reported
 * inside a 200 answer. Shapes from the real account where known.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/oneup.php';

// Listings: the real account answers {"accounts": […]} and {"categories": […]}; lists {"posts": […], "next_start": …}.
$accts = ['accounts' => [
  ['category_id' => 170109, 'social_network_name' => 'Intel Auctions (5940 Plank Road  Narvon  PA  US )', 'social_network_id' => 'accounts/1/locations/2', 'social_network_type' => 'GBP'],
  ['category_id' => 170109, 'social_network_name' => 'Intel Auctions (Narvon)', 'social_network_id' => '1336677789520495', 'social_network_type' => 'Facebook'],
]];
$pairs = ias_oneup_pairs($accts, ['social_network_id', 'id'], ['social_network_name', 'username']);
t_eq(['1336677789520495', 'Intel Auctions (Narvon)'], [$pairs[1][0] ?? '', $pairs[1][1] ?? ''], 'accounts under "accounts" are read (the Facebook page and its name)');
t_eq(3, count(ias_oneup_pairs(['categories' => [['id' => 1, 'category_name' => 'A'], ['id' => 2, 'category_name' => 'B'], ['id' => 3, 'category_name' => 'C']]], ['id'], ['category_name'])), 'categories under "categories" too');
t_eq(2, count(ias_oneup_rows(['data' => [['id' => 1], ['id' => 2]]])), '…or under "data"');
t_eq(2, count(ias_oneup_rows([['id' => 1], ['id' => 2]])), '…or a bare list');
t_eq([], ias_oneup_rows(['posts' => [], 'start' => 0, 'count' => 0, 'next_start' => null]), 'an empty list is empty, not its counters');

// The post id in a scheduling answer.
t_eq('53690404', ias_oneup_find_id(['error' => false, 'data' => ['post_id' => 53690404]]), 'post_id inside data');
t_eq('77', ias_oneup_find_id(['message' => 'ok', 'data' => [['post_id' => '77']]]), 'post_id in a list');
t_eq('', ias_oneup_find_id(['id' => 12]), 'a bare id is not taken for a post id (it could be anything\'s)');
t_eq('', ias_oneup_find_id(['message' => 'Post scheduled successfully']), 'no id: none (the read-back finds it by its text and time)');
t_eq('88', ias_oneup_find_id(['result' => ['postId' => 88], 'category' => ['id' => 5]]), 'postId at any depth, past a category\'s bare id');

// The key never travels on in an error.
t_eq('cURL error 28 for https://www.oneupapp.io/api/x?apiKey=[key]&start=0', ias_oneup_redact('cURL error 28 for https://www.oneupapp.io/api/x?apiKey=ab%2Bc%2F1&start=0', 'ab+c/1'), 'an error echoing the URL loses the encoded key');
t_eq('bad key [key]', ias_oneup_redact('bad key ab+c/1', 'ab+c/1'), '…and the plain one');

// Did OneUp act? No answer or a server error: unknown; a clear refusal: it did not.
t_ok(ias_oneup_uncertain(['ok' => false, 'code' => 0]), 'a timeout is uncertain');
t_ok(ias_oneup_uncertain(['ok' => false, 'code' => 502]), 'a 502 is uncertain');
t_ok(!ias_oneup_uncertain(['ok' => false, 'code' => 200]), 'an error inside a 200 is a refusal');
t_ok(!ias_oneup_uncertain(['ok' => false, 'code' => 401]), 'a 401 is a refusal');
t_ok(!ias_oneup_uncertain(['ok' => true, 'code' => 200]), 'a success is not uncertain');

// Errors inside a 200 answer.
t_eq('Invalid API key', ias_oneup_error_in(['error' => true, 'message' => 'Invalid API key']), 'error: true with a message');
t_eq('Facebook token expired', ias_oneup_error_in(['status' => 'error', 'msg' => 'Facebook token expired']), 'status: error');
t_eq('Not allowed', ias_oneup_error_in(['success' => false, 'message' => 'Not allowed']), 'success: false');
t_eq('', ias_oneup_error_in(['error' => false, 'message' => 'Post scheduled successfully', 'data' => ['post_id' => 1]]), 'error: false is not an error');
t_eq('', ias_oneup_error_in(['posts' => [], 'next_start' => null]), 'a list is not an error');

// Which network an account is, by OneUp's social_network_type (Look up accounts offers each list only its own kind).
t_ok(ias_oneup_type_is('gbp', 'GBP') && ias_oneup_type_is('gbp', 'Google Business Profile'), 'a GBP account is Google\'s');
t_ok(ias_oneup_type_is('facebook', 'Facebook') && !ias_oneup_type_is('facebook', 'GBP'), 'a Facebook page is Facebook\'s, a GBP location is not');
t_ok(ias_oneup_type_is('x', 'Twitter') && ias_oneup_type_is('x', 'X'), 'X answers to Twitter too');
t_ok(!ias_oneup_type_is('instagram', 'YouTube'), 'a YouTube channel is no network of ours');

// ---- 0.16.0: as docs.oneupapp.io shows (Oct 14, 2026) ----
$eps = ias_oneup_endpoints();
t_eq(['POST', 'POST', 'POST', 'POST', 'GET'], [$eps['schedule_text'][0], $eps['schedule_image'][0], $eps['schedule_video'][0], $eps['delete'][0], $eps['scheduled'][0]], 'scheduling and deleting are POST, the lists GET — as documented');
t_eq('', ias_oneup_error_in(['message' => '1 new Posts Scheduled.', 'error' => false, 'data' => []]), 'the documented answer to a scheduling call is a success');
t_eq('0 new Posts Scheduled.', ias_oneup_error_in(['message' => '0 new Posts Scheduled.', 'error' => false, 'data' => []]), '"0 new Posts Scheduled." is a refusal, though it carries no error flag');
t_eq('', ias_oneup_find_id(['message' => '1 new Posts Scheduled.', 'error' => false, 'data' => []]), 'it carries no post id (the post is found on the list)');
t_eq(2, count(ias_oneup_rows(['message' => 'OK', 'error' => false, 'data' => [['post_id' => 1], ['post_id' => 2]]])), 'a documented list: rows under data');

// Where the next page starts.
t_eq(50, ias_oneup_next_start([], 0, 50), 'no next_start and a full page of 50: ask again from 50');
t_eq(100, ias_oneup_next_start(['data' => []], 50, 50), '…and from 100 after the second');
t_eq(null, ias_oneup_next_start([], 50, 12), 'a short page is the end');
t_eq(null, ias_oneup_next_start([], 0, 0), 'an empty page is the end');
t_eq(50, ias_oneup_next_start(['next_start' => 50], 0, 50), 'a next_start is followed');
t_eq(null, ias_oneup_next_start(['next_start' => null], 0, 50), '…and a null one is the end, however full the page');
t_eq(null, ias_oneup_next_start(['next_start' => 0], 0, 50), '…as is one that does not move on');

// A published row whose id is the network's own: matched by its words and time.
$c = ['900051' => ['text' => "12 Alpha Road sells Nov 28.\n\nCall Jacob.", 'when' => '2026-11-10 19:12'],
      '900052' => ['text' => '7 Bravo Lane sells Nov 27.', 'when' => '2026-11-11 06:20'],
      'p:7'    => ['text' => '7 Bravo Lane sells Nov 27.', 'when' => '2026-11-18 06:20']];
t_eq('900051', ias_published_pick("12 Alpha Road sells Nov 28.\r\n\r\nCall Jacob. ", '2026-11-10 19:13:00', $c), 'the post whose words are the row\'s, published at its time (line endings and a trailing space aside)');
t_eq('900052', ias_published_pick('7 Bravo Lane sells Nov 27.', '2026-11-11 08:00:00', $c), 'two posts with the same words: the one whose time is nearest');
t_eq('p:7', ias_published_pick('7 Bravo Lane sells Nov 27.', '2026-11-18 06:21:00', $c), '…the later one at its own time');
t_eq(null, ias_published_pick('12 Alpha Road sells Nov 28. Call Jacob.', '2026-11-10 19:13:00', $c), 'other words: none');
t_eq(null, ias_published_pick("12 Alpha Road sells Nov 28.\n\nCall Jacob.", '2026-11-12 19:13:00', $c), 'more than a day after its time: none');
t_eq(null, ias_published_pick("12 Alpha Road sells Nov 28.\n\nCall Jacob.", '2026-11-10 18:30:00', $c), 'well before its time: none');
t_eq(null, ias_published_pick("12 Alpha Road sells Nov 28.\n\nCall Jacob.", '', $c), 'no time on the row: none');

// Why a post failed.
t_eq('The image size exceeds the platform limit.', ias_oneup_fail_reason(['post_id' => '987654', 'fail_reason' => 'The image size exceeds the platform limit.', 'message' => 'x']), 'a failed row\'s fail_reason (the documented field)');
t_eq('Token expired', ias_oneup_fail_reason(['failure_reason' => 'Token expired']), '…or another name for it');
t_eq('', ias_oneup_fail_reason(['post_id' => '1']), '…or none');

// ---- 0.19.0: the fingerprint without the time (Try again reschedules only an unchanged post) ----
$req = ['action' => 'create', 'endpoint' => 'schedule_image', 'params' => ['content' => 'Hello', 'scheduled_date_time' => '2026-11-04 19:24', 'image_url' => 'a.jpg']];
$moved = $req; $moved['params']['scheduled_date_time'] = '2026-11-09 06:12';
$edited = $req; $edited['params']['content'] = 'Hello again';
t_eq(ias_request_body_hash($req), ias_request_body_hash($moved), 'a new time leaves the fingerprint as it was');
t_ok(ias_request_body_hash($req) !== ias_request_body_hash($edited), 'new words change it');
t_eq(['POST', 'reschedulefailedpost'], ias_oneup_endpoints()['reschedule_failed'], 'Try again\'s call is OneUp\'s documented reschedulefailedpost, a POST');
