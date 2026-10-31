<?php

use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase {
    private Clevers_Remote_Scheduler $scheduler;

    protected function setUp(): void {
        $GLOBALS['mock_state']['options'] = array();
        $GLOBALS['mock_state']['scheduled'] = array();
        $this->scheduler = new Clevers_Remote_Scheduler();
    }

    public function test_add_schedule_returns_correct_interval(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_REMOTE_OPTIONS_KEY] = array(
            'interval_hours' => 12,
        );

        $schedules = $this->scheduler->add_schedule(array());
        $this->assertArrayHasKey('clevers_remote_interval', $schedules);
        $this->assertSame(12 * 3600, $schedules['clevers_remote_interval']['interval']);
        $this->assertArrayHasKey('clevers_remote_poll_interval', $schedules);
        $this->assertSame(300, $schedules['clevers_remote_poll_interval']['interval']);
    }

    public function test_schedule_creates_event_when_enabled(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_REMOTE_OPTIONS_KEY] = array(
            'enabled' => true,
            'agent_key' => 'valid-key',
            'endpoint_url' => 'https://api.clevers.dev',
            'interval_hours' => 6,
        );

        $this->scheduler->schedule();
        $this->assertNotFalse(wp_next_scheduled('clevers_remote_send_report'));
        $this->assertNotFalse(wp_next_scheduled('clevers_remote_poll_commands'));
    }

    public function test_clear_removes_scheduled_hooks(): void {
        $GLOBALS['mock_state']['scheduled']['clevers_remote_send_report'] = time();
        $GLOBALS['mock_state']['scheduled']['clevers_remote_poll_commands'] = time();

        $this->scheduler->clear();
        $this->assertFalse(wp_next_scheduled('clevers_remote_send_report'));
        $this->assertFalse(wp_next_scheduled('clevers_remote_poll_commands'));
    }
}
