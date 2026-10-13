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
