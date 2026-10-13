<?php
/**
 * Just enough WordPress for the plugin's pure parts to load outside it. Hooks
 * are recorded and ignored; options live in an array; the site time zone is
 * America/New_York, like intelauctions.com.
 */
define('ABSPATH', __DIR__ . '/');
define('IAS_PATH', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['ias_stub_options'] = [];
$GLOBALS['ias_stub_filters'] = [];
function add_action($h, $cb, $p = 10, $a = 1) { return true; }
function add_filter($h, $cb, $p = 10, $a = 1) { $GLOBALS['ias_stub_filters'][$h][] = $cb; return true; }
function apply_filters($h, $v, ...$args) {
  foreach ($GLOBALS['ias_stub_filters'][$h] ?? [] as $cb) $v = $cb($v, ...$args);
  return $v;
}
function do_action($h, ...$args) {}
function get_option($k, $d = false) { return $GLOBALS['ias_stub_options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['ias_stub_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['ias_stub_options'][$k]); return true; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function sanitize_email($e) { return filter_var(trim((string) $e), FILTER_SANITIZE_EMAIL) ?: ''; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function esc_url_raw($u) { return filter_var((string) $u, FILTER_VALIDATE_URL) ? (string) $u : ''; }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function ia_wp_tz() { return new DateTimeZone('America/New_York'); }
function wp_timezone() { return ia_wp_tz(); }
function current_time($t) { return (new DateTime('now', ia_wp_tz()))->format($t === 'mysql' ? 'Y-m-d H:i:s' : 'U'); }
function ia_ui_badge($t, $v = 'muted') { return '[' . $t . ']'; }
function ia_open_house_hours_label($h, $short = false) { return $h . ' Hours'; }
