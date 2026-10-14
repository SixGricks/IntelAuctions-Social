<?php
/**
 * How each post did on Facebook (stats.php, 0.17.0): Facebook's id for a
 * post, matching two of them, and the numbers as kept and said.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/oneup.php';
require_once IAS_PATH . 'includes/stats.php';

t_eq('104040148726689_946637334956407', ias_fb_post_id('104040148726689_946637334956407'), 'an id with its page in front stays as it is (the form the comment calls take)');
t_eq('1336677789520495_123456789', ias_fb_post_id('123456789', '1336677789520495'), 'a bare post id gets its page in front');
t_eq('1336677789520495_900053', ias_fb_post_id('FB_1336677789520495_900053'), 'the published list\'s "FB_…" loses its prefix');
t_eq('123456789', ias_fb_post_id('FB_123456789'), '…and with no page known, stays bare');
t_eq('', ias_fb_post_id('accounts/1/locations/2'), 'something that is not a Facebook id: none');
t_eq('', ias_fb_post_id(''), 'nothing: none');

t_ok(ias_fb_ids_match('1336677789520495_900053', 'FB_1336677789520495_900053'), 'the analytics\' id and the published list\'s name the same post');
t_ok(ias_fb_ids_match('104040148726689_123456789', 'FB_123456789'), 'with and without the page in front');
t_ok(!ias_fb_ids_match('104040148726689_123456789', '999_123456789'), 'two different pages: not the same post');
t_ok(!ias_fb_ids_match('104040148726689_1', '104040148726689_2'), 'two different posts: no');
t_ok(!ias_fb_ids_match('', 'FB_1'), 'an empty id matches nothing');

$n = ias_stats_numbers(['stats' => ['shares' => 45, 'comments' => 32, 'clicks' => 210, 'reactions' => 180, 'media_views' => 5200]]);
t_eq(['reactions' => 180, 'comments' => 32, 'shares' => 45, 'clicks' => 210, 'views' => 5200, 'rate' => 9.0], $n, 'OneUp\'s numbers as kept: views from media_views, and the engagement rate');
t_eq(null, ias_stats_rate(['reactions' => 5, 'views' => 0]), 'no views: no rate (never a division by nothing)');
t_eq(0, ias_stats_numbers(['stats' => ['reactions' => -3]])['reactions'], 'a negative number is read as none');
t_eq('180 reactions · 32 comments · 45 shares · 210 clicks · 5,200 views · 9.0% engagement', ias_stats_line($n), 'said in one line');
t_eq('1 reaction · 0 comments · 0 shares · 0 clicks · 0 views', ias_stats_line(ias_stats_numbers(['stats' => ['reactions' => 1]])), '…one of a thing in the singular, and no rate with no views');
