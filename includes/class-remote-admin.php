<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clevers_Remote_Admin {
    private $reporter;
    private $scheduler;
    private $remote;

    public function __construct($reporter, $scheduler, $remote) {
        $this->reporter = $reporter;
        $this->scheduler = $scheduler;
        $this->remote = $remote;

        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_clevers_remote_send_now', array($this, 'handle_send_now'));
        add_action('admin_post_clevers_remote_register_now', array($this, 'handle_register_now'));
        add_action('admin_post_clevers_remote_heartbeat_now', array($this, 'handle_heartbeat_now'));
        add_action('admin_post_clevers_remote_poll_now', array($this, 'handle_poll_now'));
    }

    public function register_menu() {
        add_options_page(
            'Clevers Remote',
            'Clevers Remote',
            'manage_options',
            'clevers-remote',
            array($this, 'render_page')
        );
    }

    public function register_settings() {
        register_setting('clevers_remote_settings', CLEVERS_REMOTE_OPTIONS_KEY, array(
            'sanitize_callback' => array($this, 'sanitize_options'),
        ));

        add_settings_section('clevers_remote_main', 'Configuracion', '__return_false', 'clevers-remote');

        add_settings_field('enabled', 'Enviar reportes', array($this, 'render_enabled'), 'clevers-remote', 'clevers_remote_main');
        add_settings_field('agent_key', 'Agent Key', array($this, 'render_agent_key'), 'clevers-remote', 'clevers_remote_main');
        add_settings_field('endpoint_url', 'Endpoint URL', array($this, 'render_endpoint_url'), 'clevers-remote', 'clevers_remote_main');
        add_settings_field('interval_hours', 'Intervalo (horas)', array($this, 'render_interval'), 'clevers-remote', 'clevers_remote_main');
        add_settings_field('remote_policy', 'Politicas remotas', array($this, 'render_remote_policy'), 'clevers-remote', 'clevers_remote_main');
    }

    public function sanitize_options($input) {
        $output = clevers_remote_get_options();

        $output['enabled'] = !empty($input['enabled']);
        $output['agent_key'] = sanitize_text_field($input['agent_key'] ?? '');
        $output['endpoint_url'] = esc_url_raw(trim($input['endpoint_url'] ?? ''));
        $output['interval_hours'] = max(1, intval($input['interval_hours'] ?? 6));
        $output['remote_allow_read'] = !empty($input['remote_allow_read']);
        $output['remote_allow_plugin_toggle'] = !empty($input['remote_allow_plugin_toggle']);
        $output['remote_allow_plugin_updates'] = !empty($input['remote_allow_plugin_updates']);
        $output['remote_allow_theme_updates'] = !empty($input['remote_allow_theme_updates']);
        $output['remote_allow_core_updates'] = !empty($input['remote_allow_core_updates']);
        $output['remote_allow_cron'] = !empty($input['remote_allow_cron']);
        $output['remote_allow_report_actions'] = !empty($input['remote_allow_report_actions']);

        return $output;
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->render_notice();

        $remote_state = $this->remote->get_status_summary();
        $audit_rows = clevers_remote_get_command_audit();

        $last_sent = get_option(CLEVERS_REMOTE_LAST_SENT_AT, '');
        $last_code = get_option(CLEVERS_REMOTE_LAST_RESPONSE_CODE, '');
        $last_error = get_option(CLEVERS_REMOTE_LAST_ERROR, '');

        $next_report = wp_next_scheduled(Clevers_Remote_Scheduler::CRON_HOOK);
        $next_poll = wp_next_scheduled(Clevers_Remote_Scheduler::POLL_CRON_HOOK);

        ?>
        <div class="wrap">
            <h1>Clevers Remote Agent</h1>

            <form action="options.php" method="post">
                <?php
                settings_fields('clevers_remote_settings');
                do_settings_sections('clevers-remote');
                submit_button('Guardar');
                ?>
            </form>

            <h2>Acciones manuales</h2>
            <p>Usa estas acciones para probar conectividad, registro, heartbeat y ejecucion remota desde esta instalacion.</p>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin:12px 0 20px;">
                <?php $this->render_post_button('clevers_remote_send_now', 'Send report', 'secondary'); ?>
                <?php $this->render_post_button('clevers_remote_register_now', 'Register site', 'secondary'); ?>
                <?php $this->render_post_button('clevers_remote_heartbeat_now', 'Send heartbeat', 'secondary'); ?>
                <?php $this->render_post_button('clevers_remote_poll_now', 'Poll commands', 'secondary'); ?>
            </div>

            <h2>Estado de reportes</h2>
            <table class="widefat striped" style="max-width: 900px; margin-bottom: 20px;">
                <tbody>
                    <tr>
                        <th>Ultimo envio</th>
                        <td><?php echo esc_html($last_sent ?: 'Sin datos'); ?></td>
                    </tr>
                    <tr>
                        <th>Ultimo codigo</th>
                        <td><?php echo esc_html($last_code === '' ? 'Sin datos' : $last_code); ?></td>
                    </tr>
                    <tr>
                        <th>Ultimo error</th>
                        <td><?php echo esc_html($last_error ?: 'Sin datos'); ?></td>
                    </tr>
                    <tr>
                        <th>Proximo reporte (cron)</th>
                        <td><?php echo esc_html($this->format_scheduled_time($next_report)); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2>Estado de control remoto</h2>
            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                    <tr>
                        <th>Site ID remoto</th>
                        <td><?php echo esc_html($remote_state['site_id'] ?: 'No registrado'); ?></td>
                    </tr>
                    <tr>
                        <th>Registrado en</th>
                        <td><?php echo esc_html($remote_state['registered_at'] ?: 'Sin datos'); ?></td>
                    </tr>
                    <tr>
                        <th>Ultimo registro (codigo/error)</th>
                        <td><?php echo esc_html(($remote_state['last_register_code'] === '' ? 'Sin datos' : $remote_state['last_register_code']) . ' / ' . ($remote_state['last_register_error'] ?: '-')); ?></td>
                    </tr>
                    <tr>
                        <th>Ultimo heartbeat</th>
                        <td><?php echo esc_html($remote_state['last_heartbeat_at'] ?: 'Sin datos'); ?></td>
                    </tr>
                    <tr>
                        <th>Heartbeat (codigo/error)</th>
                        <td><?php echo esc_html(($remote_state['last_heartbeat_code'] === '' ? 'Sin datos' : $remote_state['last_heartbeat_code']) . ' / ' . ($remote_state['last_heartbeat_error'] ?: '-')); ?></td>
                    </tr>
                    <tr>
                        <th>Ultimo poll</th>
                        <td><?php echo esc_html($remote_state['last_poll_at'] ?: 'Sin datos'); ?></td>
                    </tr>
                    <tr>
                        <th>Poll (codigo/error)</th>
                        <td><?php echo esc_html(($remote_state['last_poll_code'] === '' ? 'Sin datos' : $remote_state['last_poll_code']) . ' / ' . ($remote_state['last_poll_error'] ?: '-')); ?></td>
                    </tr>
                    <tr>
                        <th>Proximo poll (cron)</th>
                        <td><?php echo esc_html($this->format_scheduled_time($next_poll)); ?></td>
                    </tr>
                    <tr>
                        <th>Ultimo comando</th>
                        <td><?php echo esc_html($this->format_last_command($remote_state)); ?></td>
                    </tr>
                </tbody>
            </table>

            <p style="margin-top:12px; color:#555;">
                Este plugin usa un modelo pull: WordPress consulta comandos al backend de Clevers Remote y reporta resultados de vuelta con identificacion firmada del sitio.
            </p>

            <h2>Auditoria local de comandos</h2>
            <table class="widefat striped" style="max-width: 1200px;">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>ID</th>
                        <th>Comando</th>
                        <th>Estado</th>
                        <th>Duracion</th>
                        <th>Mensaje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audit_rows)) : ?>
                        <tr>
                            <td colspan="6">Sin comandos ejecutados aun.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($audit_rows as $row) : ?>
                            <tr>
                                <td><?php echo esc_html(isset($row['executed_at']) ? $row['executed_at'] : ''); ?></td>
                                <td><?php echo esc_html(isset($row['id']) ? $row['id'] : ''); ?></td>
                                <td><?php echo esc_html(isset($row['type']) ? $row['type'] : ''); ?></td>
                                <td><?php echo esc_html(isset($row['status']) ? $row['status'] : ''); ?></td>
                                <td><?php echo esc_html(isset($row['duration_ms']) ? $row['duration_ms'] . ' ms' : ''); ?></td>
                                <td><?php echo esc_html(isset($row['message']) ? $row['message'] : ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_enabled() {
        $options = clevers_remote_get_options();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(CLEVERS_REMOTE_OPTIONS_KEY); ?>[enabled]" value="1" <?php checked(!empty($options['enabled'])); ?> />
            Habilitar agente remoto (reportes + polling remoto por cron)
        </label>
        <?php
    }

    public function render_agent_key() {
        $options = clevers_remote_get_options();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr(CLEVERS_REMOTE_OPTIONS_KEY); ?>[agent_key]" value="<?php echo esc_attr($options['agent_key']); ?>" />
        <?php
    }

    public function render_endpoint_url() {
        $options = clevers_remote_get_options();
        ?>
        <input type="url" class="regular-text" name="<?php echo esc_attr(CLEVERS_REMOTE_OPTIONS_KEY); ?>[endpoint_url]" value="<?php echo esc_attr($options['endpoint_url']); ?>" placeholder="https://app.tu-dominio.com" />
        <p class="description">Base URL del backend de Clevers Remote (sin ruta final). Ejemplo: https://remote.clevers.cl</p>
        <?php
    }

    public function render_interval() {
        $options = clevers_remote_get_options();
        ?>
        <input type="number" min="1" max="168" name="<?php echo esc_attr(CLEVERS_REMOTE_OPTIONS_KEY); ?>[interval_hours]" value="<?php echo esc_attr($options['interval_hours']); ?>" />
        <p class="description">Frecuencia del reporte de telemetria. El polling remoto usa 5 minutos.</p>
        <?php
    }

    public function render_remote_policy() {
        $options = clevers_remote_get_options();
        $key = esc_attr(CLEVERS_REMOTE_OPTIONS_KEY);
        ?>
        <fieldset>
            <label style="display:block; margin-bottom:4px;">
                <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[remote_allow_read]" value="1" <?php checked(!empty($options['remote_allow_read'])); ?> />
                Permitir comandos de lectura (`ping`, `get_snapshot`, `list_plugins`, `list_themes`)
            </label>
            <label style="display:block; margin-bottom:4px;">
                <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[remote_allow_plugin_toggle]" value="1" <?php checked(!empty($options['remote_allow_plugin_toggle'])); ?> />
                Permitir activar/desactivar plugins remotamente
            </label>
            <label style="display:block; margin-bottom:4px;">
                <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[remote_allow_plugin_updates]" value="1" <?php checked(!empty($options['remote_allow_plugin_updates'])); ?> />
                Permitir actualizar plugins remotamente
            </label>
            <label style="display:block; margin-bottom:4px;">
                <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[remote_allow_theme_updates]" value="1" <?php checked(!empty($options['remote_allow_theme_updates'])); ?> />
                Permitir actualizar temas remotamente
            </label>
            <label style="display:block; margin-bottom:4px;">
                <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[remote_allow_core_updates]" value="1" <?php checked(!empty($options['remote_allow_core_updates'])); ?> />
                Permitir actualizar WordPress core remotamente
            </label>
            <label style="display:block; margin-bottom:4px;">
                <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[remote_allow_cron]" value="1" <?php checked(!empty($options['remote_allow_cron'])); ?> />
                Permitir ejecutar cron remoto (`run_due_cron`)
            </label>
            <label style="display:block;">
                <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[remote_allow_report_actions]" value="1" <?php checked(!empty($options['remote_allow_report_actions'])); ?> />
                Permitir acciones de agente (`send_report`, `send_heartbeat`)
            </label>
            <p class="description">Recomendado: activar solo las capacidades que tu backend realmente use y auditar cada comando remoto.</p>
        </fieldset>
        <?php
    }

    public function handle_send_now() {
        $this->assert_admin();
        check_admin_referer('clevers_remote_send_now');

        $response = $this->reporter->send_report(true);
        $this->redirect_with_notice('send_report', !is_wp_error($response), is_wp_error($response) ? $response->get_error_message() : 'Reporte enviado.');
    }

    public function handle_register_now() {
        $this->assert_admin();
        check_admin_referer('clevers_remote_register_now');

        $response = $this->remote->register_site(true);
        $this->redirect_with_notice('register', !is_wp_error($response), is_wp_error($response) ? $response->get_error_message() : 'Registro remoto ejecutado.');
    }

    public function handle_heartbeat_now() {
        $this->assert_admin();
        check_admin_referer('clevers_remote_heartbeat_now');

        $response = $this->remote->send_heartbeat(true);
        $this->redirect_with_notice('heartbeat', !is_wp_error($response), is_wp_error($response) ? $response->get_error_message() : 'Heartbeat enviado.');
    }

    public function handle_poll_now() {
        $this->assert_admin();
        check_admin_referer('clevers_remote_poll_now');

        $response = $this->remote->poll_commands(true);
        if (is_wp_error($response)) {
            $this->redirect_with_notice('poll', false, $response->get_error_message());
        }

        $executed = isset($response['commands_executed']) ? (int) $response['commands_executed'] : 0;
        $this->redirect_with_notice('poll', true, sprintf('Polling ejecutado. Comandos procesados: %d', $executed));
    }

    private function render_notice() {
        $notice_nonce_valid = false;
        if (isset($_GET['_clevers_remote_notice_nonce'])) {
            $notice_nonce_valid = wp_verify_nonce(
                sanitize_text_field(wp_unslash($_GET['_clevers_remote_notice_nonce'])),
                'clevers_remote_notice'
            );
        }

        if (!$notice_nonce_valid || !isset($_GET['clevers_remote_notice_ok'])) {
            return;
        }

        $ok = sanitize_text_field(wp_unslash($_GET['clevers_remote_notice_ok'])) === '1';
        $action = isset($_GET['clevers_remote_notice_action']) ? sanitize_text_field(wp_unslash($_GET['clevers_remote_notice_action'])) : 'accion';
        $message = isset($_GET['clevers_remote_notice_message']) ? sanitize_text_field(wp_unslash($_GET['clevers_remote_notice_message'])) : '';

        $class = $ok ? 'notice notice-success' : 'notice notice-error';
        if ($message === '') {
            $message = $ok ? 'Operacion exitosa.' : 'Operacion fallida.';
        }

        echo '<div class="' . esc_attr($class) . '"><p><strong>' . esc_html($action) . ':</strong> ' . esc_html($message) . '</p></div>';
    }

    private function render_post_button($action, $label, $button_class) {
        ?>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;">
            <?php wp_nonce_field($action); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php submit_button($label, $button_class, '', false); ?>
        </form>
        <?php
    }

    private function assert_admin() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
    }

    private function redirect_with_notice($action, $ok, $message) {
        $redirect_url = wp_get_referer();
        if (!$redirect_url) {
            $redirect_url = admin_url('options-general.php?page=clevers-remote');
        }

        $redirect = add_query_arg(
            array(
                'clevers_remote_notice_action' => sanitize_key($action),
                'clevers_remote_notice_ok' => $ok ? '1' : '0',
                'clevers_remote_notice_message' => $this->truncate_notice_message($message),
                '_clevers_remote_notice_nonce' => wp_create_nonce('clevers_remote_notice'),
            ),
            $redirect_url
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private function format_scheduled_time($timestamp) {
        if (!$timestamp) {
            return 'No programado';
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function format_last_command($state) {
        if (empty($state['last_command_at'])) {
            return 'Sin datos';
        }

        $parts = array(
            $state['last_command_at'],
            $state['last_command_type'] ?: '-',
            $state['last_command_status'] ?: '-',
        );

        if (!empty($state['last_command_id'])) {
            $parts[] = 'id=' . $state['last_command_id'];
        }
        if (!empty($state['last_command_message'])) {
            $parts[] = $state['last_command_message'];
        }

        return implode(' | ', $parts);
    }

    private function truncate_notice_message($message) {
        $message = wp_strip_all_tags((string) $message);
        if (strlen($message) <= 180) {
            return $message;
        }

        return substr($message, 0, 177) . '...';
    }
}
