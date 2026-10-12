<?php
/**
 * Run tests inside a real WordPress: `php tests/wp-run.php /path/to/wordpress
 * tests/wp/*.php`. The site needs the Intel Auctions Toolkit and this plugin
 * active and tests/fixtures/site-stub.php in mu-plugins (it stands in for what
 * intelauctions.com registers itself — the auction and team CPTs and ACF's
 * get_field()). See tests/README.md. Never point it at a live site: the tests
 * create and delete auctions, posts and options.
 *
 * IAS_TEST_TOOLKIT_OFF=1 loads WordPress with the toolkit filtered out of the
 * active plugins for this one process (the "toolkit is missing" test).
 */
if (PHP_SAPI !== 'cli') exit;
$ias_wp_path = rtrim((string) ($argv[1] ?? ''), '/');
if ($ias_wp_path === '' || !is_file($ias_wp_path . '/wp-load.php')) { fwrite(STDERR, "usage: php tests/wp-run.php /path/to/wordpress tests/wp/*.php\n"); exit(2); }
$_SERVER['HTTP_HOST']   = getenv('IAS_TEST_HOST') ?: 'localhost';
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
$_SERVER['REQUEST_URI'] = '/wp-admin/';
$_SERVER['REQUEST_METHOD'] = 'GET';
define('WP_ADMIN', true);
define('IAS_TESTING', true);
if (getenv('IAS_TEST_TOOLKIT_OFF') === '1') {
  // Before WordPress reads active_plugins: drop the toolkit for this process only.
  $GLOBALS['wp_filter']['option_active_plugins'][10][] = ['function' => function ($plugins) {
    return array_values(array_filter((array) $plugins, function ($p) { return strpos((string) $p, 'intel-auctions-toolkit/') !== 0; }));
  }, 'accepted_args' => 1];
}
require $ias_wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user(1);
require __DIR__ . '/assert.php';
$files = array_slice($argv, 2);
if (!$files) { $files = glob(__DIR__ . '/wp/*.php'); sort($files); }
exit(t_run_files($files));
