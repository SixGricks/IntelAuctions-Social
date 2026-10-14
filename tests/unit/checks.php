<?php
/** The draft checks and the fair-housing screen. */
require_once IAS_PATH . 'includes/fairhousing.php';
require_once IAS_PATH . 'includes/checks.php';

$fh = function (string $t) { return array_column(ias_fair_housing_check($t), 'phrase'); };
t_ok((bool) $fh('A farmhouse perfect for families who love space.'), '"perfect for families" is caught');
t_ok((bool) $fh('Quiet road in a safe neighborhood.'), '"safe neighborhood" is caught');
t_ok((bool) $fh('Great for retirees.'), '"great for retirees" is caught');
t_ok((bool) $fh('Adults only.'), '"adults only" is caught');
t_ok((bool) $fh('Close to the church and school.'), 'pointing to a church is caught');
t_ok((bool) $fh('A bachelor pad over the garage.'), '"bachelor pad" is caught');
t_eq([], $fh('A white bank barn, Amish-built in 1890, with a wheelchair ramp to the porch and a family room.'), 'describing the property is never flagged');
t_eq([], $fh('Walk the 85-acre farm in Narvon on Saturday.'), 'a plain post is clean');
t_eq(['Hunting camp'], array_column(ias_fair_housing_check('Hunting camp with a creek.', "hunting camp\n"), 'phrase'), 'an extra phrase from Settings is caught');

$ctx = ['type' => 'listing', 'bidding' => ['phone'], 'money' => [250000.0], 'phones' => ['717-917-5844', '(717) 555-0101'], 'fair_extra' => ''];
$ok = "Sale day is Nov 18 at 10:00 am, and this one is Intel Phone Bidding.\nStarting bid $250,000. Call Jacob at 717-555-0101.";
$r = ias_check_draft($ok, $ctx);
t_eq([], $r['holds'], 'a correct phone-bid post passes');

$r = ias_check_draft("This is an in-person auction with Intel Phone Bidding too.", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'choose') !== false; }), 'both bidding types: held (buyers would think they can choose)');
$r = ias_check_draft("Come to the auction Nov 18.", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'does not say') !== false; }), 'bidding not stated: held');
$r = ias_check_draft("Intel Phone Bidding, or bid online.", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'online') !== false; }), 'online bidding: held');
$r = ias_check_draft("In-person auction.", array_merge($ctx, ['bidding' => ['']]));
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'listing does not say') !== false; }), 'the listing itself not saying: held');
$r = ias_check_draft("Thanks to everyone who came out.", array_merge($ctx, ['type' => 'results']));
t_eq([], $r['holds'], 'a Results post need not state bidding');

$r = ias_check_draft("Intel Phone Bidding. Starting bid $275,000.", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, '$275,000') !== false; }), 'a dollar figure not on the listing: held');
$r = ias_check_draft("Intel Phone Bidding. Starting at $250K.", $ctx);
t_eq([], $r['holds'], '"$250K" matches the $250,000 starting bid');
$r = ias_check_draft("Intel Phone Bidding. Call 717-555-9999.", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'phone number') !== false; }), 'a phone number that is not the lead\'s or the office\'s: held');
$r = ias_check_draft("Intel Phone Bidding. [VERIFY: open house time]", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'VERIFY') !== false; }), '[VERIFY]: held');
$r = ias_check_draft("Intel Phone Bidding!!! See https://intelauctions.com/x for more.", $ctx);
t_ok(strpos($r['text'], 'https://') === false && strpos($r['text'], '!!') === false, 'links and stacked exclamation points are fixed');
t_eq(2, count($r['fixes']), '…and both fixes are noted');
$r = ias_check_draft("Intel Phone Bidding 🚜🏡🌾🐄", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'emoji') !== false; }), 'an emoji wall: held');
$r = ias_check_draft("Intel Phone Bidding. Perfect for families.", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'Fair housing') === 0; }), 'a fair-housing phrase: held');

// Several same-day sales of different kinds: each must be named.
$r = ias_check_draft("Two sales Nov 18: the farm is an in-person auction; the lot is Intel Phone Bidding.", array_merge($ctx, ['type' => 'auction_week', 'bidding' => ['in_person', 'phone']]));
t_eq([], $r['holds'], 'a shared post naming both kinds passes');

// A denied mention is no mention (DJ, Oct 13, 2026: a phone-bid post saying "no onsite auction" was held — too aggressive).
$r = ias_check_draft("Intel Phone Bidding — no onsite auction. Starting bid $250,000.", $ctx);
t_eq([], $r['holds'], '"no onsite auction" on a phone-bid post: not held');
t_eq([], ias_check_draft("This is Intel Phone Bidding, not an in-person sale.", $ctx)['holds'], '"not an in-person sale": not held');
t_eq([], ias_check_draft("Bid by phone through Intel Phone Bidding. There won't be live bidding on site.", $ctx)['holds'], '"won\'t be live bidding": not held');
t_eq([], ias_check_draft("Intel Phone Bidding. No in-person or online bidding.", $ctx)['holds'], '"no in-person or online bidding" denies both');
$r = ias_check_draft("Intel Phone Bidding, and an onsite auction too.", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'choose') !== false; }), 'an onsite auction stated as fact on a phone-bid post: still held');
$r = ias_check_draft("No phone bids here. This is an in-person auction.", array_merge($ctx, ['bidding' => ['in_person']]));
t_eq([], $r['holds'], 'an in-person post denying phone bids: not held');
$r = ias_check_draft("Phone bidding is not available.", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'does not say') !== false; }), 'a sentence that only denies says nothing: a phone-bid post still has to say it is phone bidding');
t_ok(ias_negated_at('There is no onsite auction', strpos('There is no onsite auction', 'onsite')), 'ias_negated_at: "no" before it');
t_ok(!ias_negated_at('No crowds. Onsite auction at 10.', strpos('No crowds. Onsite auction at 10.', 'Onsite')), '…but not across a sentence');

// The emoji-led shape may carry more emoji; every other one stays at three.
$emo = "📍 Narvon\n📅 Nov 18\n⏰ 10:00 am\n💲 Starting bid $250,000\n📞 717-555-0101\n🏡 Intel Phone Bidding";
t_eq([], ias_check_draft($emo, array_merge($ctx, ['emoji_max' => 8]))['holds'], 'an emoji-led post with one emoji a line passes');
t_ok((bool) array_filter(ias_check_draft($emo, $ctx)['holds'], function ($h) { return strpos($h, 'emoji') !== false; }), '…the same in any other shape is held');

// Only a phone-bid sale has to say how bidding works (DJ, Oct 14, 2026: "don't require 'in person auction' as part
// of the description. We only need to say when it's a phone bid auction.").
$inp = array_merge($ctx, ['bidding' => ['in_person']]);
t_eq([], ias_check_draft("5940 Plank Road sells Saturday, Nov 21 at 10:00 am. Starting bid $250,000.", $inp)['holds'], 'an in-person sale need not say it is in person');
t_eq([], ias_check_draft("Sells Nov 21 — an in-person auction on site.", $inp)['holds'], '…and may');
$r = ias_check_draft("Sells Nov 21. Bid by phone through Intel Phone Bidding.", $inp);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'but this is an in-person auction') !== false; }), 'an in-person sale\'s post that says phone bidding: held, saying why');
$r = ias_check_draft("Sells Nov 21. Starting bid $250,000.", $ctx);
t_ok((bool) array_filter($r['holds'], function ($h) { return $h === 'It does not say this is Intel Phone Bidding.'; }), 'a phone-bid sale must still say so');
$two = array_merge($ctx, ['type' => 'auction_week', 'bidding' => ['in_person', 'phone']]);
t_eq([], ias_check_draft("Two sales Nov 18. The lot is Intel Phone Bidding.", $two)['holds'], 'two sales, one by phone: only the phone one need be named');
$r = ias_check_draft("Two sales Nov 18 in Narvon.", $two);
t_ok((bool) array_filter($r['holds'], function ($h) { return strpos($h, 'One of these sales is Intel Phone Bidding') === 0; }), '…and when the phone one is not: held');
t_eq([], ias_check_draft("Two farms sell Nov 18 in Narvon.", array_merge($two, ['bidding' => ['in_person', 'in_person']]))['holds'], 'two in-person sales: nothing to say');
