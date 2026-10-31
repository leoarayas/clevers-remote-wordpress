<?php

use PHPUnit\Framework\TestCase;

final class ReporterTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['mock_state']['options'] = array();
        $GLOBALS['mock_state']['transients'] = array();
        $GLOBALS['mock_state']['http_response'] = null;
    }

    public function test_send_report_returns_error_when_disabled(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_REMOTE_OPTIONS_KEY] = array(
            'enabled' => false,
        );

        $reporter = new Clevers_Remote_Reporter();
        $result = $reporter->send_report(false);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('clevers_remote_disabled', $result->get_error_code());
    }

    public function test_send_report_returns_error_when_key_missing(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_REMOTE_OPTIONS_KEY] = array(
            'enabled' => true,
            'agent_key' => '',
            'endpoint_url' => 'https://api.clevers.dev',
        );

        $reporter = new Clevers_Remote_Reporter();
        $result = $reporter->send_report(false);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('clevers_remote_missing_key', $result->get_error_code());
    }

    public function test_send_report_succeeds_with_valid_config(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_REMOTE_OPTIONS_KEY] = array(
            'enabled' => true,
            'agent_key' => 'valid-agent-key',
            'endpoint_url' => 'https://api.clevers.dev',
        );

        $reporter = new Clevers_Remote_Reporter();
        $result = $reporter->send_report(false);

        $this->assertIsArray($result);
        $this->assertSame(200, $result['response']['code']);
    }
}
