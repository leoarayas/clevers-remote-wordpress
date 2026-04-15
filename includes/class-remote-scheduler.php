<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clevers_Remote_Scheduler {
    const CRON_HOOK = 'clevers_remote_send_report';
    const POLL_CRON_HOOK = 'clevers_remote_poll_commands';
    const SCHEDULE_KEY = 'clevers_remote_interval';
    const POLL_SCHEDULE_KEY = 'clevers_remote_poll_interval';

    private $reporter;

    public function __construct($reporter = null) {
        $this->reporter = $reporter;

        add_filter('cron_schedules', array($this, 'add_schedule'));
        add_action('init', array($this, 'maybe_schedule'));
        add_action('update_option_' . CLEVERS_REMOTE_OPTIONS_KEY, array($this, 'reschedule'), 10, 2);
    }

    public function add_schedule($schedules) {
        $options = clevers_remote_get_options();
        $hours = max(1, intval($options['interval_hours']));

        $schedules[self::SCHEDULE_KEY] = array(
            'interval' => $hours * HOUR_IN_SECONDS,
            'display' => sprintf('Clevers Remote every %d hour(s)', $hours),
        );

        $schedules[self::POLL_SCHEDULE_KEY] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => 'Clevers Remote poll every 5 minutes',
        );

        return $schedules;
    }

    public function schedule() {
        $options = clevers_remote_get_options();
        if (empty($options['enabled'])) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::SCHEDULE_KEY, self::CRON_HOOK);
        }

        if (!wp_next_scheduled(self::POLL_CRON_HOOK)) {
            wp_schedule_event(time() + 120, self::POLL_SCHEDULE_KEY, self::POLL_CRON_HOOK);
        }
    }

    public function clear() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::POLL_CRON_HOOK);
    }

    public function reschedule($old_value, $value) {
        if ($old_value === $value) {
            return;
        }

        $this->clear();

        $options = clevers_remote_get_options();
        if (!empty($options['enabled'])) {
            $this->schedule();
        }
    }

    public function maybe_schedule() {
        $options = clevers_remote_get_options();
        if (empty($options['enabled'])) {
            $this->clear();
            return;
        }

        $this->schedule();
    }
}
