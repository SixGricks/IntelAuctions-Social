<?php
/**
 * Correction comments (corrections.php, 0.18.0): Facebook's id for the post
 * and the page the comment goes as.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/oneup.php';
require_once IAS_PATH . 'includes/stats.php';
require_once IAS_PATH . 'includes/corrections.php';

$GLOBALS['ias_stub_options']['ias_settings'] = ['oneup_account_id' => '1336677789520495'];
t_eq('1336677789520495_42', ias_correction_fb_id(['flags' => ['oneup' => ['fb_id' => '1336677789520495_42']]]), 'from the analytics\' id');
t_eq('1336677789520495_42', ias_correction_fb_id(['flags' => ['oneup' => ['published_id' => 'FB_1336677789520495_42']]]), 'else from the published list\'s "FB_…"');
t_eq('1336677789520495_42', ias_correction_fb_id(['flags' => ['oneup' => ['published_id' => 'FB_42']]]), '…a bare one with the page\'s id in front');
t_eq('', ias_correction_fb_id(['flags' => ['oneup' => ['id' => '900051']]]), 'only OneUp\'s own id: not known (a comment needs Facebook\'s)');
$GLOBALS['ias_stub_options']['ias_settings'] = ['oneup_account_id' => 'fb_page'];
t_eq('', ias_correction_fb_id(['flags' => ['oneup' => ['published_id' => 'FB_42']]]), 'a bare id and no numeric page to put in front: not known');
t_eq('1336677789520495', ias_correction_page_id('1336677789520495_42'), 'the comment goes as the page the post is on');
t_eq('fb_page', ias_correction_page_id('42'), '…else as the page\'s account in OneUp');
t_eq(['POST', 'https://feed.oneupapp.io/api/reply-to-post', 'json'], ias_oneup_endpoints()['comment'] ?? [], 'the comment call is OneUp\'s documented reply-to-post, with a JSON body');
unset($GLOBALS['ias_stub_options']['ias_settings']);
