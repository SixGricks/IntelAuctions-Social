<?php
/**
 * The few assertions the tests use, shared by tests/run.php (no WordPress)
 * and tests/wp-run.php (inside WordPress). Each test file is plain PHP that
 * calls t_ok()/t_eq(); the runner prints ALL PASSED or the failures.
 */
$GLOBALS['ias_t'] = ['pass' => 0, 'fail' => [], 'file' => ''];

function t_ok($cond, string $msg): void {
  if ($cond) { $GLOBALS['ias_t']['pass']++; return; }
  $GLOBALS['ias_t']['fail'][] = basename($GLOBALS['ias_t']['file']) . ': ' . $msg;
  echo "  ✘ {$msg}\n";
}

function t_eq($want, $got, string $msg): void {
  $same = $want === $got;
  if (!$same) $msg .= ' — want ' . json_encode($want) . ', got ' . json_encode($got);
  t_ok($same, $msg);
}

function t_run_files(array $files): int {
  foreach ($files as $file) {
    $GLOBALS['ias_t']['file'] = $file;
    echo '• ' . basename($file) . "\n";
    try {
      (static function () use ($file) { require $file; })();
    } catch (\Throwable $e) {
      t_ok(false, 'threw ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
  }
  $fail = $GLOBALS['ias_t']['fail'];
  echo "\n" . $GLOBALS['ias_t']['pass'] . ' passed, ' . count($fail) . " failed\n";
  echo $fail ? "FAILED\n" : "ALL PASSED\n";
  return $fail ? 1 : 0;
}
