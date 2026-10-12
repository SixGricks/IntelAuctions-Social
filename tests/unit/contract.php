<?php
/**
 * The toolkit contract: every toolkit function this plugin calls is either on
 * the list ias_boot() checks before doing anything, or an optional helper whose
 * every call is guarded by function_exists(). A new call to a toolkit helper
 * fails this test until it is one or the other — so a half-loaded toolkit
 * leaves the plugin off with its notice, never a fatal on some screen.
 */
require_once IAS_PATH . 'includes/dependency.php';

// Optional helpers: each call site checks function_exists() first.
$optional = ['ia_acf_field_team_email', 'ia_announced_thumb_id', 'ia_business_phone', 'ia_docs_md_to_html',
  'ia_get_auction_type_value', 'ia_get_excerpt_plain', 'ia_social_full_pushed'];

$listed = ias_required_toolkit_functions();
$calls = [];
foreach (array_merge(glob(IAS_PATH . 'includes/*.php'), [IAS_PATH . 'intel-auctions-social.php']) as $file) {
  if (preg_match_all('/(?<![\w>$:])(ia_[a-z0-9_]+)\s*\(/', (string) file_get_contents($file), $m)) {
    foreach ($m[1] as $fn) $calls[$fn][basename($file)] = true;
  }
}
$unchecked = [];
foreach ($calls as $fn => $files) {
  if (!in_array($fn, $listed, true) && !in_array($fn, $optional, true)) $unchecked[] = $fn . ' (' . implode(', ', array_keys($files)) . ')';
}
t_eq([], $unchecked, 'every toolkit function the plugin calls is checked before it boots, or optional and guarded');
t_ok(in_array('ia_ui_empty', $listed, true), 'ia_ui_empty (the empty Calendar and Log) is checked');
foreach ($optional as $fn) {
  $ok = true;
  foreach (glob(IAS_PATH . 'includes/*.php') as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match('/(?<![\w>$:])' . $fn . '\s*\(/', $src) && strpos($src, "function_exists('{$fn}')") === false) $ok = false;
  }
  t_ok($ok, "{$fn} is only called behind function_exists()");
}
