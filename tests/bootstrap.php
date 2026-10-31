<?php

define('ABSPATH', __DIR__);
define('CLEVERS_REMOTE_VERSION', '1.0.0');
define('CLEVERS_REMOTE_PLUGIN_FILE', dirname(__DIR__) . '/clevers-remote.php');
define('CLEVERS_REMOTE_PLUGIN_DIR', dirname(__DIR__) . '/');
define('CLEVERS_REMOTE_OPTIONS_KEY', 'clevers_remote_options');
define('CLEVERS_REMOTE_STATE_KEY', 'clevers_remote_state');
define('CLEVERS_REMOTE_COMMAND_AUDIT_KEY', 'clevers_remote_command_audit');

define('CLEVERS_REMOTE_LAST_SENT_AT', 'clevers_remote_last_sent_at');
define('CLEVERS_REMOTE_LAST_RESPONSE_CODE', 'clevers_remote_last_response_code');
define('CLEVERS_REMOTE_LAST_ERROR', 'clevers_remote_last_error');

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

$GLOBALS['mock_state'] = array(
    'options' => array(),
    'scheduled' => array(),
    'transients' => array(),
    'http_responses' => array(),
);

function get_option($key, $default = false) {
    return $GLOBALS['mock_state']['options'][$key] ?? $default;
}

function update_option($key, $value, $autoload = null) {
    unset($autoload);
    $GLOBALS['mock_state']['options'][$key] = $value;
    return true;
}

function delete_option($key) {
    unset($GLOBALS['mock_state']['options'][$key]);
    return true;
}

function wp_next_scheduled($hook) {
    return $GLOBALS['mock_state']['scheduled'][$hook] ?? false;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
    unset($recurrence, $args);
    $GLOBALS['mock_state']['scheduled'][$hook] = $timestamp;
    return true;
}

function wp_clear_scheduled_hook($hook) {
    unset($GLOBALS['mock_state']['scheduled'][$hook]);
    return true;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    unset($hook, $callback, $priority, $accepted_args);
    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    unset($hook, $callback, $priority, $accepted_args);
    return true;
}

function current_user_can($cap) {
    unset($cap);
    return true;
}

function is_multisite() {
    return false;
}

function get_bloginfo($show) {
    if ($show === 'name') return 'Test Site';
    if ($show === 'admin_email') return 'admin@example.com';
    if ($show === 'version') return '6.7';
    return '6.7';
}

function site_url($path = '') {
    return 'https://example.com' . $path;
}

function home_url($path = '') {
    return 'https://example.com' . $path;
}

function sanitize_text_field($str) {
    return trim(strip_tags((string) $str));
}

function sanitize_title($str) {
    return strtolower(preg_replace('/[^a-z0-9-]/', '-', (string) $str));
}

function sanitize_key($key) {
    return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $key));
}

function wp_json_encode($value) {
    return json_encode($value);
}

function rest_ensure_response($data) {
    return $data;
}

function register_rest_route($namespace, $route, $args = array()) {
    unset($namespace, $route, $args);
    return true;
}

function get_plugins() {
    return array(
        'hello.php' => array('Name' => 'Hello Dolly', 'Version' => '1.7.2'),
        'akismet/akismet.php' => array('Name' => 'Akismet', 'Version' => '5.3'),
    );
}

function is_plugin_active($plugin) {
    return $plugin === 'hello.php';
}

function get_site_transient($transient) {
    return $GLOBALS['mock_state']['transients'][$transient] ?? false;
}

function wp_get_themes() {
    return array();
}

function get_stylesheet() {
    return 'twentytwentyfour';
}

function get_core_updates() {
    return array();
}

function wp_remote_post($url, $args = array()) {
    if (!empty($GLOBALS['mock_state']['http_response'])) {
        return $GLOBALS['mock_state']['http_response'];
    }
    return array(
        'response' => array('code' => 200),
        'body' => json_encode(array('ok' => true, 'site_id' => 'site-123', 'agent_secret' => 'sec-xyz')),
    );
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code($response) {
    return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body($response) {
    return $response['body'] ?? '';
}

function current_time($type) {
    unset($type);
    return '2026-10-30 22:00:00';
}

function wp_strip_all_tags($str) {
    return strip_tags((string) $str);
}

function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
    unset($special_chars, $extra_special_chars);
    return substr(md5((string) mt_rand()), 0, $length);
}

function wp_die($message) {
    throw new Exception((string) $message);
}

class WP_Error {
    private $code;
    private $message;

    public function __construct($code, $message) {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_code() {
        return $this->code;
    }
}

function clevers_remote_get_options() {
    $defaults = array(
        'enabled' => true,
        'agent_key' => '',
        'endpoint_url' => '',
        'interval_hours' => 6,
        'remote_allow_read' => true,
        'remote_allow_plugin_toggle' => false,
        'remote_allow_plugin_updates' => false,
        'remote_allow_theme_updates' => false,
        'remote_allow_core_updates' => false,
        'remote_allow_cron' => true,
        'remote_allow_report_actions' => true,
    );

    $options = get_option(CLEVERS_REMOTE_OPTIONS_KEY, array());
    if (!is_array($options)) {
        $options = array();
    }

    return array_merge($defaults, $options);
}

function clevers_remote_get_state() {
    $defaults = array(
        'site_id' => '',
        'site_secret' => '',
        'registered_at' => '',
        'last_register_code' => '',
        'last_register_error' => '',
        'last_heartbeat_at' => '',
        'last_heartbeat_code' => '',
        'last_heartbeat_error' => '',
        'last_poll_at' => '',
        'last_poll_code' => '',
        'last_poll_error' => '',
        'last_command_at' => '',
        'last_command_id' => '',
        'last_command_type' => '',
        'last_command_status' => '',
        'last_command_message' => '',
    );

    $state = get_option(CLEVERS_REMOTE_STATE_KEY, array());
    if (!is_array($state)) {
        $state = array();
    }

    return array_merge($defaults, $state);
}

function clevers_remote_update_state($patch) {
    if (!is_array($patch)) {
        return;
    }

    $state = clevers_remote_get_state();
    update_option(CLEVERS_REMOTE_STATE_KEY, array_merge($state, $patch));
}

function clevers_remote_get_command_audit() {
    $rows = get_option(CLEVERS_REMOTE_COMMAND_AUDIT_KEY, array());
    return is_array($rows) ? $rows : array();
}

function clevers_remote_append_command_audit($row) {
    if (!is_array($row)) {
        return;
    }

    $rows = clevers_remote_get_command_audit();
    array_unshift($rows, $row);
    $rows = array_slice($rows, 0, 30);
    update_option(CLEVERS_REMOTE_COMMAND_AUDIT_KEY, $rows);
}

require_once dirname(__DIR__) . '/includes/class-remote-reporter.php';
require_once dirname(__DIR__) . '/includes/class-remote-client.php';
require_once dirname(__DIR__) . '/includes/class-remote-scheduler.php';
