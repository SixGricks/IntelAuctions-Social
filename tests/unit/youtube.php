<?php
/**
 * Property videos to YouTube (youtube.php, 0.22.0): the title and the
 * description built from the facts alone, a video id read out of whatever
 * OneUp or YouTube hands back, and when a video sent now goes out.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/oneup.php';
require_once IAS_PATH . 'includes/channels.php';
require_once IAS_PATH . 'includes/fairhousing.php';
require_once IAS_PATH . 'includes/youtube.php';

$tz = new DateTimeZone('America/New_York');
$biz = ['name' => 'Intel Auctions LLC', 'street' => '5940 Plank Road', 'city' => 'Narvon', 'region' => 'PA', 'postal' => '17555', 'phone' => '717-917-5844'];
$f = [
  'title' => '12 Alpha Road', 'street' => '12 Alpha Road', 'town' => 'Narvon', 'state' => 'PA', 'address' => '12 Alpha Road, Narvon, PA 17555, USA',
  'date' => new DateTimeImmutable('2026-11-28 10:00', $tz), 'has_time' => true, 'bidding' => 'in_person', 'size_raw' => '85 +/- acre farm',
  'open_houses' => [['label' => 'Saturday, November 14, 1:00 pm – 3:00 pm']], 'excerpt' => 'A productive farm with a bank barn and a stone farmhouse.',
  'lead' => ['name' => 'Jacob Stoltzfus', 'phone' => '717-555-0101'], 'phone' => '717-555-0101', 'kind' => 'real_estate', 'division' => 'farm_home',
  'starting_bid_raw' => '$300,000',
];
$o = ['link' => 'https://intelauctions.com/auction/12-alpha-road/?utm_source=youtube', 'subscribe' => 'https://www.youtube.com/channel/UC2tfTuFO9diTgaaeozmDLKg?sub_confirmation=1', 'business' => $biz, 'extra' => ''];
$w = ias_youtube_words($f, $o);

t_eq('12 Alpha Road, Narvon, PA | Auction Nov 28, 2026 | Intel Auctions', $w['title'], 'the title: the address, the sale date, the business');
t_ok(strpos($w['text'], 'A walkthrough of 12 Alpha Road, Narvon, PA 17555, USA.') === 0, 'the description opens with what it is');
t_ok(strpos($w['text'], 'Auction: Saturday, November 28, 2026 at 10:00 am.') !== false, '…the sale day and time');
t_ok(strpos($w['text'], 'Open house: Saturday, November 14, 1:00 pm – 3:00 pm.') !== false, '…the open house');
t_ok(strpos($w['text'], 'Photos, terms and the full listing: ' . $o['link']) !== false, '…the listing link');
t_ok(strpos($w['text'], 'A productive farm with a bank barn') !== false, '…the listing\'s own description');
t_ok(strpos($w['text'], 'Questions? Call Jacob Stoltzfus at 717-555-0101.') !== false, '…who to call');
t_ok(strpos($w['text'], 'Subscribe for every property we bring to auction: ' . $o['subscribe']) !== false, '…the Subscribe link');
t_ok(strpos($w['text'], 'Intel Auctions LLC | 5940 Plank Road, Narvon, PA 17555 | 717-917-5844') !== false, '…and the name, address and phone, exactly as the brand brief has them');
t_ok(strpos($w['text'], '300,000') === false && stripos($w['text'], 'starting bid') === false, 'never the starting bid (it changes, and a YouTube description is not corrected from here)');
t_ok(stripos($w['text'], 'phone bidding') === false && stripos($w['text'], 'in-person') === false, 'an in-person sale need not say how bidding works');
t_ok(strpos($w['text'], '#IntelAuctions #Narvon #RealEstateAuction') !== false, 'three tags at the end');

$phone = ias_youtube_words(['bidding' => 'phone'] + $f, $o);
t_ok(strpos($phone['text'], 'Bidding is by Intel Phone Bidding') !== false, 'a phone-bid sale says so');

$nodate = ias_youtube_words(['date' => null, 'open_houses' => []] + $f, $o);
t_eq('12 Alpha Road, Narvon, PA | Property Walkthrough | Intel Auctions', $nodate['title'], 'no sale date yet: a walkthrough');
t_ok(strpos($nodate['text'], 'Auction:') === false && strpos($nodate['text'], 'Open house') === false, '…and no date or open house is made up');

$long = ias_youtube_words(['street' => str_repeat('Long Farm Lane ', 8), 'town' => 'Narvon'] + $f, $o);
t_ok(mb_strlen($long['title']) <= 100 && substr($long['title'], -strlen('| Auction Nov 28, 2026')) === '| Auction Nov 28, 2026', 'a long address is cut, and the title keeps its date within YouTube\'s 100 characters (' . mb_strlen($long['title']) . ')');

$angle = ias_youtube_words(['excerpt' => 'Barn <b>and</b> house > 100 years old', 'street' => '12 <Alpha> Road'] + $f, $o);
t_ok(strpos($angle['title'] . $angle['text'], '<') === false && strpos($angle['title'] . $angle['text'], '>') === false, 'no angle brackets anywhere (YouTube refuses them)');

$flag = ias_youtube_words(['excerpt' => 'Perfect for a young couple with no children.'] + $f, $o);
t_ok(strpos($flag['text'], 'young couple') === false && strpos($flag['text'], 'Photos, terms') !== false, 'a description with a fair-housing phrase is left out — the rest still goes, nothing waits on a person');

$big = ias_youtube_words(['excerpt' => str_repeat('A long description of the farm. ', 400)] + $f, $o);
t_ok(mb_strlen($big['text']) <= 5000 && strpos($big['text'], 'Intel Auctions LLC |') !== false, 'a long description is cut so the whole fits YouTube\'s 5,000 characters, the name and address kept');

$nosub = ias_youtube_words($f, ['subscribe' => ''] + $o);
t_ok(strpos($nosub['text'], 'Subscribe for every property we bring to auction.') !== false, 'no channel address known: Subscribe without a link');

// The video's id.
foreach ([
  'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
  'https://youtu.be/dQw4w9WgXcQ?si=abc' => 'dQw4w9WgXcQ',
  'https://www.youtube.com/shorts/Q_BC8ZhiZoU' => 'Q_BC8ZhiZoU',
  'https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
  'YT_dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
  'dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
  '90000112345' => '',
  '900001' => '',
  'FB_1336677789520495_900053' => '',
  '' => '',
  'https://vimeo.com/123456789' => '',
] as $in => $want) {
  t_eq($want, ias_youtube_video_id((string) $in), 'video id from "' . $in . '"');
}

// When it goes out.
$slots = [['06:00', '07:30'], ['11:30', '13:00']];
t_eq('2026-11-02 14:05', ias_youtube_when(new DateTimeImmutable('2026-11-02 14:00', $tz), $slots), 'five minutes ahead');
t_eq('2026-11-09 06:00', ias_youtube_when(new DateTimeImmutable('2026-11-08 15:00', $tz), $slots), 'never on a Sunday: Monday\'s first posting window');
t_eq('2026-11-09 06:00', ias_youtube_when(new DateTimeImmutable('2026-11-07 23:57', $tz), $slots), 'five minutes ahead would be Sunday: Monday too');

// The browser's preflight (0.22.1): origin, PUT and Content-Type all allowed.
$o = 'https://intelauctions.com';
t_ok(ias_youtube_cors_ok(['Access-Control-Allow-Origin' => '*', 'Access-Control-Allow-Methods' => 'GET, POST, PUT', 'Access-Control-Allow-Headers' => 'content-type'], $o), 'OneUp\'s bucket as it answered: allowed');
t_ok(ias_youtube_cors_ok(['access-control-allow-origin' => $o, 'access-control-allow-methods' => 'PUT', 'access-control-allow-headers' => 'Content-Type, x-amz-acl'], $o), 'this origin by name, any case: allowed');
t_ok(ias_youtube_cors_ok(['access-control-allow-origin' => '*', 'access-control-allow-methods' => '*', 'access-control-allow-headers' => '*'], $o), 'wildcards: allowed');
t_ok(!ias_youtube_cors_ok(['access-control-allow-origin' => 'https://elsewhere.example', 'access-control-allow-methods' => 'PUT', 'access-control-allow-headers' => 'content-type'], $o), 'another origin: refused');
t_ok(!ias_youtube_cors_ok(['access-control-allow-origin' => '*', 'access-control-allow-methods' => 'GET, POST', 'access-control-allow-headers' => 'content-type'], $o), 'no PUT: refused');
t_ok(!ias_youtube_cors_ok(['access-control-allow-origin' => '*', 'access-control-allow-methods' => 'PUT'], $o), 'no Content-Type header allowed: refused');
t_ok(!ias_youtube_cors_ok([], $o), 'no CORS headers at all: refused');
// WordPress hands headers over as a case-insensitive dictionary object (a cast to array would empty it).
$dict = new class(['access-control-allow-origin' => '*', 'access-control-allow-methods' => 'GET, POST, PUT', 'access-control-allow-headers' => 'content-type']) implements IteratorAggregate {
  private $data; public function __construct(array $d) { $this->data = $d; }
  public function getAll() { return $this->data; } public function getIterator(): Iterator { return new ArrayIterator($this->data); }
};
t_ok(ias_youtube_cors_ok($dict, $o), 'the headers as WordPress hands them over (a dictionary object): read, allowed');
t_ok(ias_youtube_cors_ok(new ArrayIterator(['Access-Control-Allow-Origin' => '*', 'Access-Control-Allow-Methods' => ['PUT'], 'Access-Control-Allow-Headers' => ['Content-Type']]), $o), 'any iterable, values as lists: allowed');
t_ok(!ias_youtube_cors_ok(null, $o) && !ias_youtube_cors_ok('nonsense', $o), 'nothing usable: refused');
