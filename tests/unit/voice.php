<?php
/** The brand voice's pure parts (includes/voice.php): the word diff, example text, house rules, Claude's JSON. */
require_once IAS_PATH . 'includes/fairhousing.php';
require_once IAS_PATH . 'includes/voice.php';

// ---- What a person changed, word by word ----
$claude = "Nestled in the rolling hills of Narvon, this stunning farm boasts 42 acres.\n\nCall Jacob at 717-555-0101.";
$final  = "42 acres in Narvon, with a bank barn and good fences.\n\nCall Jacob at 717-555-0101.";
$d = ias_word_diff($claude, $final);
$ops = array_column($d, 0);
t_ok(in_array('-', $ops, true) && in_array('+', $ops, true) && in_array('=', $ops, true), 'the diff has words taken out, put in and kept');
$out = []; $in = [];
foreach ($d as [$op, $w]) { if ($op === '-') $out = array_merge($out, $w); if ($op === '+') $in = array_merge($in, $w); }
t_ok(in_array('Nestled', $out, true) && in_array('stunning', $out, true) && in_array('boasts', $out, true), '…the gloss is what came out');
t_ok(in_array('barn', $in, true) && in_array('fences.', $in, true), '…the plain facts are what went in');
t_eq(['=', ["\n", "\n", 'Call', 'Jacob', 'at', '717-555-0101.']], end($d), '…and the unchanged call to action stays one kept run, with its line breaks');
$rebuilt = function (array $diff, string $keep) { $w = []; foreach ($diff as [$op, $words]) if ($op === '=' || $op === $keep) $w = array_merge($w, $words); return preg_replace('/ ?\n ?/', "\n", implode(' ', $w)); };
t_eq($final, $rebuilt($d, '+'), 'kept + put in rebuilds the final words exactly');
t_eq($claude, $rebuilt($d, '-'), 'kept + taken out rebuilds Claude\'s words exactly');
t_eq('as Claude wrote it', ias_word_diff_summary(ias_word_diff($final, $final)), 'no change reads "as Claude wrote it"');
t_eq('1 word taken out', ias_word_diff_summary(ias_word_diff('Sale is on Saturday.', 'Sale is Saturday.')), 'the summary counts words taken out…');
t_eq('1 word put in', ias_word_diff_summary(ias_word_diff('The sale is Saturday.', 'The big sale is Saturday.')), '…and put in');
t_eq('1 word taken out, 3 words put in', ias_word_diff_summary(ias_word_diff("Call us.\nNow.", "Call us\ntoday, please.\nNow.")), '…and a line break is not a word');
$html = ias_word_diff_html(ias_word_diff('a <b> c', 'a <i> c'));
t_ok(strpos($html, '<del>&lt;b&gt;</del>') !== false && strpos($html, '<ins>&lt;i&gt;</ins>') !== false, 'the diff as HTML escapes the words');
$long = str_repeat('word ', 700);
t_ok(count(ias_word_diff($long . 'end', 'start ' . $long)) >= 2, 'long texts still diff (no time-out on a 700-word post)');

// ---- A post as an example ----
$ex = ias_voice_clean_text("Just listed in Gap.\n\nDetails: https://intelauctions.com/auction/x/?utm_source=facebook\n\n#IntelAuctions #Gap #FarmAuction");
t_eq("Just listed in Gap.\n\nDetails:", $ex, 'an example loses its hashtag line and web address');
t_ok(mb_strlen(ias_voice_clean_text(str_repeat('a', 2000), 900)) === 900, '…and is cut to length');

// ---- House rules ----
[$kept, $refused] = ias_voice_clean_rules(['- Never say "nestled".', 'never say "nestled".', '', '2) Open with the place.', 'Call it perfect for families.', str_repeat('x', 201)]);
t_eq(['Never say "nestled".', 'Open with the place.'], $kept, 'rules lose their bullets and numbers, and repeats');
t_eq(2, count($refused), '…a fair-housing phrase and an over-long rule are refused');
t_ok(strpos($refused[0][1], 'perfect for families') !== false, '…saying which phrase it would have put in posts');
$many = [];
for ($i = 1; $i <= 35; $i++) $many[] = 'Rule number ' . $i . '.';
t_eq(30, count(ias_voice_clean_rules($many)[0]), 'at most 30 rules');

// ---- Claude's answers ----
t_eq(['reply' => 'Done.', 'text' => 'New words', 'rule' => null], ias_json_object_from_text('{"reply":"Done.","text":"New words","rule":null}'), 'a JSON object');
t_eq(['rules' => ['A.', 'B.'], 'why' => 'x'], ias_json_object_from_text("Here you go:\n{\"rules\": [\"A.\", \"B.\"], \"why\": \"x\"}\nThanks"), 'an object inside prose — the object, not the list inside it');
t_eq(null, ias_json_object_from_text('["A", "B"]'), 'a bare list is not an object');
t_eq(null, ias_json_object_from_text('Sorry, I cannot.'), 'prose is nothing');
