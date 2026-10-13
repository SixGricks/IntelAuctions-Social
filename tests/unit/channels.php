<?php
/**
 * Google's words (channels.php): Google Business Profile refuses a post with a
 * phone number in it, so the sentence carrying one goes — with a short
 * question that led to it — and nothing else does.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/oneup.php';
require_once IAS_PATH . 'includes/channels.php';

t_eq("Just listed: 12 Main St.\n\nOpen house Sat 1–3. Bid online.", ias_strip_phones("Just listed: 12 Main St.\n\nOpen house Sat 1–3. Questions? Call Jacob Stoltzfus at 717-555-0101. Bid online."), 'the sentence with the number goes, and "Questions?" before it');
t_eq("Sale day is Saturday.", ias_strip_phones("Sale day is Saturday.\n\nCall (717) 555-0102"), 'a line that was only the number goes');
t_eq("Sale day.", ias_strip_phones("Sale day. Call 717.555.0103 or 1-717-555-0104 today."), 'dotted and long-distance numbers too');
t_eq("Starting bid \$650,000. Auction Nov 21 at 10:00 am. 5940 Plank Road, Narvon, PA 17555.", ias_strip_phones("Starting bid \$650,000. Auction Nov 21 at 10:00 am. 5940 Plank Road, Narvon, PA 17555."), 'prices, times, addresses and ZIP codes are not phone numbers');
t_eq("What a view? Call us.", ias_strip_phones("What a view? Call us."), 'nothing to take out: the words as they were');
