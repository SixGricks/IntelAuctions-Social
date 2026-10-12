<?php
/**
 * The pure tests: no WordPress, no database. `php tests/run.php` (optionally
 * with test file paths). Loads tests/stubs.php — the handful of WordPress
 * functions the pure parts of the plugin touch — then every tests/unit/*.php.
 */
require __DIR__ . '/assert.php';
require __DIR__ . '/stubs.php';
$files = array_slice($argv, 1) ?: glob(__DIR__ . '/unit/*.php');
sort($files);
exit(t_run_files($files));
