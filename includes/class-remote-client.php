<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clevers_Remote_Client {
    const REGISTER_PATH = '/api/remote/agent/register';
    const HEARTBEAT_PATH = '/api/remote/agent/heartbeat';
    const COMMAND_PULL_PATH = '/api/remote/agent/commands/pull';
    const COMMAND_RESULT_PATH = '/api/remote/agent/commands/result';

    private $reporter;

    public function __construct($reporter) {
        $this->reporter = $reporter;
    }

    public function register_site($force = false) {
        $state = clevers_remote_get_state();
        if (!$force && !empty($state['site_id'])) {
            return array(
                'ok' => true,
                'message' => 'Site already registered.',
                'site_id' => $state['site_id'],
            );
        }

        $payload = array(
            'site_url' => site_url(),
            'home_url' => home_url(),
            'name' => get_bloginfo('name'),
            'admin_email' => get_bloginfo('admin_email'),
            'agent_version' => CLEVERS_REMOTE_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'capabilities' => $this->get_supported_commands(),
        );

        $response = $this->post_json(self::REGISTER_PATH, $payload, false);
        $this->store_http_state('register', $response);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = $this->decode_json_body($response);
        if (is_wp_error($data)) {
            return $data;
        }

        $patch = array();
        if (!empty($data['site_id'])) {
            $patch['site_id'] = sanitize_text_field((string) $data['site_id']);
            $patch['registered_at'] = current_time('mysql');
        }
        if (!empty($data['agent_secret'])) {
            $patch['site_secret'] = sanitize_text_field((string) $data['agent_secret']);
        }

        if (!empty($patch)) {
            clevers_remote_update_state($patch);
        }

        return array(
            'ok' => true,
            'message' => 'Registration completed.',
            'site_id' => isset($patch['site_id']) ? $patch['site_id'] : $state['site_id'],
            'raw' => $data,
        );
    }

    public function send_heartbeat($manual = false) {
        $options = clevers_remote_get_options();
        if (!$manual && empty($options['enabled'])) {
            return new WP_Error('clevers_remote_disabled', 'Clevers Remote is disabled.');
        }

        $registration = $this->ensure_registered();
        if (is_wp_error($registration)) {
            return $registration;
        }

        $snapshot = $this->reporter->build_payload();
        $payload = array(
            'site_url' => site_url(),
            'timestamp' => current_time('mysql'),
            'wordpress' => $snapshot['wordpress'],
            'php' => $snapshot['php'],
            'health' => $snapshot['health'],
            'stats' => array(
                'plugins_count' => is_array($snapshot['plugins']) ? count($snapshot['plugins']) : 0,
                'themes_count' => is_array($snapshot['themes']) ? count($snapshot['themes']) : 0,
            ),
        );

        $response = $this->post_json(self::HEARTBEAT_PATH, $payload, true);
        $this->store_http_state('heartbeat', $response);

        return $response;
    }

    public function poll_commands($manual = false) {
        $options = clevers_remote_get_options();
        if (!$manual && empty($options['enabled'])) {
            return new WP_Error('clevers_remote_disabled', 'Clevers Remote is disabled.');
        }

        $registration = $this->ensure_registered();
        if (is_wp_error($registration)) {
            return $registration;
        }

        $state = clevers_remote_get_state();
        $payload = array(
            'site_url' => site_url(),
            'last_command_at' => $state['last_command_at'],
            'limit' => 5,
        );

        $response = $this->post_json(self::COMMAND_PULL_PATH, $payload, true);
        $this->store_http_state('poll', $response);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = $this->decode_json_body($response);
        if (is_wp_error($data)) {
            return $data;
        }

        $commands = array();
        if (isset($data['commands']) && is_array($data['commands'])) {
            $commands = $data['commands'];
        }

        $results = array();
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $result = $this->execute_command($command);
            $results[] = $result;
            $this->send_command_result($result);
        }

        return array(
            'ok' => true,
            'commands_received' => count($commands),
            'commands_executed' => count($results),
            'results' => $results,
        );
    }

    public function get_status_summary() {
        $state = clevers_remote_get_state();
        return array(
            'site_id' => $state['site_id'],
            'registered_at' => $state['registered_at'],
            'last_register_code' => $state['last_register_code'],
            'last_register_error' => $state['last_register_error'],
            'last_heartbeat_at' => $state['last_heartbeat_at'],
            'last_heartbeat_code' => $state['last_heartbeat_code'],
            'last_heartbeat_error' => $state['last_heartbeat_error'],
            'last_poll_at' => $state['last_poll_at'],
            'last_poll_code' => $state['last_poll_code'],
            'last_poll_error' => $state['last_poll_error'],
            'last_command_at' => $state['last_command_at'],
            'last_command_id' => $state['last_command_id'],
            'last_command_type' => $state['last_command_type'],
            'last_command_status' => $state['last_command_status'],
            'last_command_message' => $state['last_command_message'],
        );
    }

    public function get_supported_commands() {
        return array(
            'ping',
            'get_snapshot',
            'list_plugins',
            'list_themes',
            'activate_plugin',
            'deactivate_plugin',
            'update_plugin',
            'update_theme',
            'update_core',
            'update_all',
            'run_due_cron',
            'send_report',
            'send_heartbeat',
        );
    }

    private function ensure_registered() {
        $state = clevers_remote_get_state();
        if (!empty($state['site_id'])) {
            return true;
        }

        return $this->register_site(false);
    }

    private function execute_command($command) {
        $command_id = isset($command['id']) ? sanitize_text_field((string) $command['id']) : '';
        $type = isset($command['type']) ? sanitize_key((string) $command['type']) : '';
        $payload = isset($command['payload']) && is_array($command['payload']) ? $command['payload'] : array();
        $started = microtime(true);

        $status = 'failed';
        $message = '';
        $result_data = array();

        switch ($type) {
            case 'ping':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $status = 'success';
                $result_data = array(
                    'site_url' => site_url(),
                    'time' => current_time('mysql'),
                );
                break;

            case 'get_snapshot':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $status = 'success';
                $result_data = $this->reporter->build_payload();
                break;

            case 'list_plugins':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $snapshot = $this->reporter->build_payload();
                $status = 'success';
                $result_data = isset($snapshot['plugins']) ? $snapshot['plugins'] : array();
                break;

            case 'list_themes':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $snapshot = $this->reporter->build_payload();
                $status = 'success';
                $result_data = isset($snapshot['themes']) ? $snapshot['themes'] : array();
                break;

            case 'activate_plugin':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->activate_plugin_command($payload);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = $exec;
                }
                break;

            case 'deactivate_plugin':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->deactivate_plugin_command($payload);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = $exec;
                }
                break;

            case 'update_plugin':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->update_plugin_command($payload);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = $exec;
                }
                break;

            case 'update_theme':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->update_theme_command($payload);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = $exec;
                }
                break;

            case 'update_core':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->update_core_command($payload);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = $exec;
                }
                break;

            case 'update_all':
                if (!$this->is_command_allowed($type, $payload)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->update_all_command($payload);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = $exec;
                }
                break;

            case 'run_due_cron':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $status = 'success';
                $result_data = $this->run_due_cron_command();
                break;

            case 'send_report':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->reporter->send_report(true);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = array(
                        'http_code' => wp_remote_retrieve_response_code($exec),
                    );
                }
                break;

            case 'send_heartbeat':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->send_heartbeat(true);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = array(
                        'http_code' => wp_remote_retrieve_response_code($exec),
                    );
                }
                break;

            default:
                $message = 'Unsupported command type: ' . $type;
                break;
        }

        $duration_ms = (int) round((microtime(true) - $started) * 1000);
        if ($message === '') {
            $message = $status === 'success' ? 'OK' : 'Unknown error';
        }

        $result = array(
            'id' => $command_id,
            'type' => $type,
            'status' => $status,
            'message' => $this->truncate_text($message, 500),
            'result' => $this->normalize_result($result_data),
            'executed_at' => current_time('mysql'),
            'duration_ms' => $duration_ms,
        );

        clevers_remote_update_state(array(
            'last_command_at' => $result['executed_at'],
            'last_command_id' => $command_id,
            'last_command_type' => $type,
            'last_command_status' => $status,
            'last_command_message' => $result['message'],
        ));
        clevers_remote_append_command_audit($result);

        return $result;
    }

    private function is_command_allowed($type, $payload = array()) {
        $options = clevers_remote_get_options();

        $read_commands = array('ping', 'get_snapshot', 'list_plugins', 'list_themes');
        if (in_array($type, $read_commands, true)) {
            return !empty($options['remote_allow_read']);
        }

        $toggle_commands = array('activate_plugin', 'deactivate_plugin');
        if (in_array($type, $toggle_commands, true)) {
            return !empty($options['remote_allow_plugin_toggle']);
        }

        if ($type === 'update_plugin') {
            return !empty($options['remote_allow_plugin_updates']);
        }

        if ($type === 'update_theme') {
            return !empty($options['remote_allow_theme_updates']);
        }

        if ($type === 'update_core') {
            return !empty($options['remote_allow_core_updates']);
        }

        if ($type === 'update_all') {
            $run_plugins = !isset($payload['plugins']) || !empty($payload['plugins']);
            $run_themes = !isset($payload['themes']) || !empty($payload['themes']);
            $run_core = !isset($payload['core']) || !empty($payload['core']);

            if (!$run_plugins && !$run_themes && !$run_core) {
                return false;
            }

            if ($run_plugins && empty($options['remote_allow_plugin_updates'])) {
                return false;
            }

            if ($run_themes && empty($options['remote_allow_theme_updates'])) {
                return false;
            }

            if ($run_core && empty($options['remote_allow_core_updates'])) {
                return false;
            }

            return true;
        }

        if ($type === 'run_due_cron') {
            return !empty($options['remote_allow_cron']);
        }

        $report_commands = array('send_report', 'send_heartbeat');
        if (in_array($type, $report_commands, true)) {
            return !empty($options['remote_allow_report_actions']);
        }

        return false;
    }

    private function activate_plugin_command($payload) {
        $plugin_file = isset($payload['plugin_file']) ? sanitize_text_field((string) $payload['plugin_file']) : '';
        if ($plugin_file === '') {
            return new WP_Error('clevers_remote_missing_plugin', 'plugin_file is required.');
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $result = activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'plugin_file' => $plugin_file,
            'active' => is_plugin_active($plugin_file),
        );
    }

    private function deactivate_plugin_command($payload) {
        $plugin_file = isset($payload['plugin_file']) ? sanitize_text_field((string) $payload['plugin_file']) : '';
        if ($plugin_file === '') {
            return new WP_Error('clevers_remote_missing_plugin', 'plugin_file is required.');
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins($plugin_file, false, false);

        return array(
            'plugin_file' => $plugin_file,
            'active' => is_plugin_active($plugin_file),
        );
    }

    private function update_plugin_command($payload) {
        $plugin_file = isset($payload['plugin_file']) ? sanitize_text_field((string) $payload['plugin_file']) : '';
        if ($plugin_file === '') {
            return new WP_Error('clevers_remote_missing_plugin', 'plugin_file is required.');
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        wp_update_plugins();

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $upgrade_result = $upgrader->upgrade($plugin_file);

        if (is_wp_error($upgrade_result)) {
            return $upgrade_result;
        }
        if ($upgrade_result === false) {
            $messages = method_exists($skin, 'get_error_messages') ? $skin->get_error_messages() : '';
            return new WP_Error('clevers_remote_update_failed', $messages ? wp_strip_all_tags($messages) : 'Plugin update failed.');
        }

        return array(
            'plugin_file' => $plugin_file,
            'updated' => true,
        );
    }

    private function update_theme_command($payload) {
        $theme_slug = isset($payload['theme_slug']) ? sanitize_key((string) $payload['theme_slug']) : '';
        if ($theme_slug === '') {
            return new WP_Error('clevers_remote_missing_theme', 'theme_slug is required.');
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        wp_update_themes();

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        $upgrade_result = $upgrader->upgrade($theme_slug);

        if (is_wp_error($upgrade_result)) {
            return $upgrade_result;
        }
        if ($upgrade_result === false) {
            $messages = method_exists($skin, 'get_error_messages') ? $skin->get_error_messages() : '';
            return new WP_Error('clevers_remote_theme_update_failed', $messages ? wp_strip_all_tags($messages) : 'Theme update failed.');
        }

        return array(
            'theme_slug' => $theme_slug,
            'updated' => true,
        );
    }

    private function update_core_command($payload) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/admin.php';

        wp_version_check();
        $updates = get_core_updates(array('dismissed' => false));

        if (!is_array($updates)) {
            return new WP_Error('clevers_remote_core_update_check_failed', 'Could not fetch core updates.');
        }

        $offer = null;
        foreach ($updates as $update) {
            if (isset($update->response) && $update->response !== 'latest') {
                $offer = $update;
                break;
            }
        }

        if (!$offer) {
            return array(
                'updated' => false,
                'message' => 'WordPress core is already up to date.',
            );
        }

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);
        $upgrade_result = $upgrader->upgrade($offer);

        if (is_wp_error($upgrade_result)) {
            return $upgrade_result;
        }
        if ($upgrade_result === false) {
            $messages = method_exists($skin, 'get_error_messages') ? $skin->get_error_messages() : '';
            return new WP_Error('clevers_remote_core_update_failed', $messages ? wp_strip_all_tags($messages) : 'WordPress core update failed.');
        }

        return array(
            'updated' => true,
            'target_version' => isset($offer->current) ? (string) $offer->current : '',
        );
    }

    private function update_all_command($payload) {
        $run_plugins = !isset($payload['plugins']) || !empty($payload['plugins']);
        $run_themes = !isset($payload['themes']) || !empty($payload['themes']);
        $run_core = !isset($payload['core']) || !empty($payload['core']);

        $results = array();
        $summary = array(
            'plugins' => array('requested' => $run_plugins, 'attempted' => 0, 'updated' => 0, 'failed' => 0),
            'themes' => array('requested' => $run_themes, 'attempted' => 0, 'updated' => 0, 'failed' => 0),
            'core' => array('requested' => $run_core, 'attempted' => 0, 'updated' => 0, 'failed' => 0),
        );

        if ($run_plugins) {
            $plugin_files = $this->get_pending_plugin_updates();
            foreach ($plugin_files as $plugin_file) {
                $summary['plugins']['attempted']++;
                $exec = $this->update_plugin_command(array('plugin_file' => $plugin_file));
                if (is_wp_error($exec)) {
                    $summary['plugins']['failed']++;
                    $results['plugins'][] = array(
                        'plugin_file' => $plugin_file,
                        'status' => 'failed',
                        'message' => $exec->get_error_message(),
                    );
                    continue;
                }

                $summary['plugins']['updated'] += !empty($exec['updated']) ? 1 : 0;
                $results['plugins'][] = array_merge(array('status' => 'success'), $exec);
            }
        }

        if ($run_themes) {
            $theme_slugs = $this->get_pending_theme_updates();
            foreach ($theme_slugs as $theme_slug) {
                $summary['themes']['attempted']++;
                $exec = $this->update_theme_command(array('theme_slug' => $theme_slug));
                if (is_wp_error($exec)) {
                    $summary['themes']['failed']++;
                    $results['themes'][] = array(
                        'theme_slug' => $theme_slug,
                        'status' => 'failed',
                        'message' => $exec->get_error_message(),
                    );
                    continue;
                }

                $summary['themes']['updated'] += !empty($exec['updated']) ? 1 : 0;
                $results['themes'][] = array_merge(array('status' => 'success'), $exec);
            }
        }

        if ($run_core) {
            $summary['core']['attempted'] = 1;
            $exec = $this->update_core_command(array());
            if (is_wp_error($exec)) {
                $summary['core']['failed'] = 1;
                $results['core'] = array(
                    'status' => 'failed',
                    'message' => $exec->get_error_message(),
                );
            } else {
                $summary['core']['updated'] = !empty($exec['updated']) ? 1 : 0;
                $results['core'] = array_merge(array('status' => 'success'), $exec);
            }
        }

        return array(
            'summary' => $summary,
            'results' => $results,
            'completed_at' => current_time('mysql'),
        );
    }

    private function get_pending_plugin_updates() {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_update_plugins();

        $updates = get_site_transient('update_plugins');
        if (!isset($updates->response) || !is_array($updates->response)) {
            return array();
        }

        return array_keys($updates->response);
    }

    private function get_pending_theme_updates() {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_update_themes();

        $updates = get_site_transient('update_themes');
        if (!isset($updates->response) || !is_array($updates->response)) {
            return array();
        }

        return array_keys($updates->response);
    }

    private function run_due_cron_command() {
        $ran = false;

        if (function_exists('wp_cron')) {
            wp_cron();
            $ran = true;
        } elseif (function_exists('_wp_cron')) {
            _wp_cron();
            $ran = true;
        } elseif (function_exists('spawn_cron')) {
            spawn_cron(time());
            $ran = true;
        }

        return array(
            'triggered' => $ran,
            'time' => current_time('mysql'),
        );
    }

    private function send_command_result($result) {
        $payload = array(
            'command' => $result,
            'site_url' => site_url(),
        );

        $response = $this->post_json(self::COMMAND_RESULT_PATH, $payload, true);
        return $response;
    }

    private function post_json($path, $payload, $requires_registration) {
        $options = clevers_remote_get_options();
        $endpoint_base = isset($options['endpoint_url']) ? $options['endpoint_url'] : '';
        $agent_key = isset($options['agent_key']) ? trim((string) $options['agent_key']) : '';

        if ($agent_key === '') {
            return new WP_Error('clevers_remote_missing_key', 'Agent key missing.');
        }
        if (empty($endpoint_base)) {
            return new WP_Error('clevers_remote_missing_endpoint', 'Endpoint URL missing.');
        }

        $url = rtrim((string) $endpoint_base, '/') . $path;
        $body = wp_json_encode($payload);
        if ($body === false) {
            return new WP_Error('clevers_remote_json_encode_failed', 'Could not encode JSON payload.');
        }

        $headers = $this->build_auth_headers('POST', $path, $body, $agent_key, $requires_registration);
        if (is_wp_error($headers)) {
            return $headers;
        }

        return wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => $headers,
            'body' => $body,
        ));
    }

    private function build_auth_headers($method, $path, $body, $agent_key, $requires_registration) {
        $state = clevers_remote_get_state();

        if ($requires_registration && empty($state['site_id'])) {
            return new WP_Error('clevers_remote_not_registered', 'Site is not registered yet.');
        }

        $timestamp = (string) time();
        $nonce = wp_generate_password(12, false, false);

        $headers = array(
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Website-Agent-Key' => $agent_key,
            'X-Website-Agent-Timestamp' => $timestamp,
            'X-Website-Agent-Nonce' => $nonce,
        );

        if (!empty($state['site_id'])) {
            $headers['X-Website-Agent-Site-Id'] = $state['site_id'];
        }

        if (!empty($state['site_secret'])) {
            $canonical = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body;
            $headers['X-Website-Agent-Signature'] = hash_hmac('sha256', $canonical, $state['site_secret']);
        }

        return $headers;
    }

    private function decode_json_body($response) {
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if (($body === '' || $body === null) && $code >= 200 && $code < 300) {
            return array();
        }
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return new WP_Error('clevers_remote_invalid_json', 'Invalid JSON response.');
        }

        if ($code < 200 || $code >= 300) {
            $message = isset($decoded['message']) ? (string) $decoded['message'] : 'Remote request failed.';
            return new WP_Error('clevers_remote_remote_error', $this->truncate_text(wp_strip_all_tags($message), 300));
        }

        return $decoded;
    }

    private function store_http_state($type, $response) {
        $patch = array();
        $now = current_time('mysql');

        if ($type === 'register') {
            $patch['last_register_code'] = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $patch['last_register_error'] = is_wp_error($response) ? $response->get_error_message() : '';
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 400) {
                $patch['last_register_error'] = $this->truncate_text(wp_strip_all_tags(wp_remote_retrieve_body($response)), 300);
            }
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 200 && wp_remote_retrieve_response_code($response) < 300) {
                $patch['registered_at'] = $now;
            }
        } elseif ($type === 'heartbeat') {
            $patch['last_heartbeat_at'] = $now;
            $patch['last_heartbeat_code'] = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $patch['last_heartbeat_error'] = is_wp_error($response) ? $response->get_error_message() : '';
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 400) {
                $patch['last_heartbeat_error'] = $this->truncate_text(wp_strip_all_tags(wp_remote_retrieve_body($response)), 300);
            }
        } elseif ($type === 'poll') {
            $patch['last_poll_at'] = $now;
            $patch['last_poll_code'] = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $patch['last_poll_error'] = is_wp_error($response) ? $response->get_error_message() : '';
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 400) {
                $patch['last_poll_error'] = $this->truncate_text(wp_strip_all_tags(wp_remote_retrieve_body($response)), 300);
            }
        }

        if (!empty($patch)) {
            clevers_remote_update_state($patch);
        }
    }

    private function normalize_result($value) {
        $encoded = wp_json_encode($value);
        if ($encoded === false) {
            return array('summary' => 'Non-serializable result');
        }

        if (strlen($encoded) > 8000) {
            return array(
                'summary' => 'Result truncated',
                'size' => strlen($encoded),
            );
        }

        return $value;
    }

    private function truncate_text($text, $max_length) {
        $text = (string) $text;
        if (strlen($text) <= $max_length) {
            return $text;
        }

        return substr($text, 0, $max_length - 3) . '...';
    }
}
