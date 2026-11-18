<?php
/** CLI authorization, strict preferences and error propagation; no native requests. */
namespace ClearNginxForWebuzo {
    final class Cache_Service {
        public static function run($operation) {
            $GLOBALS['operations'][] = $operation;
            return $GLOBALS['response'] ?? ['account' => 'account1', 'scope' => 'account', 'backend' => 'webuzo-native'];
        }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    $authorized = true; $multisite = false; $operations = []; $options = []; $messages = []; $checks = [];
    class WP_Error { public function get_error_message() { return 'Native failure fixture'; } }
    class WP_CLI {
        public static function error($text) { throw new RuntimeException($text); }
        public static function success($text) { $GLOBALS['messages'][] = ['success', $text]; }
        public static function line($text) { $GLOBALS['messages'][] = ['line', $text]; }
    }
    function __($text, $domain = '') { return $text; }
    function current_user_can($cap) { return $GLOBALS['authorized'] && $cap === 'manage_options'; }
    function is_multisite() { return $GLOBALS['multisite']; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function wp_json_encode($value) { return json_encode($value); }
    function sanitize_text_field($value) { return strip_tags($value); }
    function wp_html_excerpt($value, $length, $more = '') { return mb_substr($value, 0, $length); }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
    require __DIR__ . '/../clear-nginx-for-webuzo/includes/class-settings.php';
    require __DIR__ . '/../clear-nginx-for-webuzo/includes/class-cli.php';
    function check($value, $label) { if (!$value) { throw new RuntimeException($label); } $GLOBALS['checks'][] = $label; }
    function rejected($fn, $contains) { try { $fn(); } catch (RuntimeException $error) { return strpos($error->getMessage(), $contains) !== false; } return false; }
    $cli = new \ClearNginxForWebuzo\CLI();
    $cli->status(); check($operations === ['status'], 'CLI status never purges');
    check(strpos($messages[0][1], 'key') === false && strpos($messages[0][1], 'scope') !== false, 'CLI status prints safe account scope without credentials');
    $cli->purge(); check($operations === ['status', 'purge'] && strpos(end($messages)[1], 'all websites') !== false, 'CLI purge reports account-wide scope');
    $response = new WP_Error();
    check(rejected(function() use ($cli) { $cli->purge(); }, 'Native failure'), 'Native purge failure becomes CLI error');
    check(rejected(function() use ($cli) { $cli->status(); }, 'Native failure'), 'Native status failure becomes CLI error');
    $response = null;
    foreach (['status', 'purge', 'settings'] as $command) {
        $authorized = false; $before = $operations;
        check(rejected(function() use ($cli, $command) { $cli->$command([]); }, '--user') && $before === $operations, 'CLI administrator required: ' . $command);
        $authorized = true; $multisite = true;
        check(rejected(function() use ($cli, $command) { $cli->$command([]); }, 'single-site'), 'CLI multisite rejected: ' . $command);
        $multisite = false;
    }
    $cli->settings([]); check(json_decode(end($messages)[1], true) === \ClearNginxForWebuzo\Settings::defaults(), 'CLI lists documented defaults');
    foreach (['true' => true, 'false' => false, '1' => true, '0' => false] as $value => $expected) {
        $cli->settings(['automatic_purge', (string) $value]);
        check(\ClearNginxForWebuzo\Settings::get()['automatic_purge'] === $expected, 'CLI strict boolean accepted: ' . $value);
    }
    check(rejected(function() use ($cli) { $cli->settings(['automatic_purge', 'yes']); }, 'Boolean'), 'CLI rejects ambiguous boolean');
    check(rejected(function() use ($cli) { $cli->settings(['secret_key', 'value']); }, 'Unknown'), 'CLI rejects credential settings');
    check(rejected(function() use ($cli) { $cli->settings(['show_toolbar', 'true', 'extra']); }, 'Unknown'), 'CLI rejects extra positional arguments');
    $cli->settings(['toolbar_label', '<b>Clear</b>']);
    $cli->settings(['toolbar_label']);
    check(json_decode(end($messages)[1]) === 'Clear', 'CLI sanitizes and reads a single text setting');
    echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
}
