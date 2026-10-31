<?php

use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase {
    private Clevers_Remote_Client $client;
    private Clevers_Remote_Reporter $reporter;

    protected function setUp(): void {
        $GLOBALS['mock_state']['options'] = array(
            CLEVERS_REMOTE_OPTIONS_KEY => array(
                'enabled' => true,
                'agent_key' => 'test-agent-key',
                'endpoint_url' => 'https://api.clevers.dev',
                'remote_allow_read' => true,
                'remote_allow_plugin_toggle' => false,
                'remote_allow_plugin_updates' => false,
                'remote_allow_core_updates' => false,
                'remote_allow_cron' => true,
            ),
            CLEVERS_REMOTE_STATE_KEY => array(
                'site_id' => 'site-123',
                'site_secret' => 'sec-xyz',
                'last_command_at' => '',
            ),
            CLEVERS_REMOTE_COMMAND_AUDIT_KEY => array(),
        );
        $GLOBALS['mock_state']['http_response'] = null;

        $this->reporter = new Clevers_Remote_Reporter();
        $this->client = new Clevers_Remote_Client($this->reporter);
    }

    public function test_get_supported_commands_returns_list(): void {
        $commands = $this->client->get_supported_commands();
        $this->assertIsArray($commands);
        $this->assertContains('ping', $commands);
        $this->assertContains('get_snapshot', $commands);
        $this->assertContains('run_due_cron', $commands);
        $this->assertContains('activate_plugin', $commands);
    }

    public function test_register_site_stores_state(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_REMOTE_STATE_KEY] = array();
        $GLOBALS['mock_state']['http_response'] = array(
            'response' => array('code' => 200),
            'body' => json_encode(array('ok' => true, 'site_id' => 'new-site-456', 'agent_secret' => 'new-sec-999')),
        );

        $result = $this->client->register_site(true);
        $this->assertIsArray($result);
        $this->assertTrue($result['ok'] ?? false);

        $state = clevers_remote_get_state();
        $this->assertSame('new-site-456', $state['site_id']);
        $this->assertSame('new-sec-999', $state['site_secret']);
    }

    public function test_poll_commands_executes_ping_and_records_audit(): void {
        $GLOBALS['mock_state']['http_response'] = array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'commands' => array(
                    array(
                        'id' => 'cmd-ping-1',
                        'type' => 'ping',
                        'payload' => array(),
                    ),
                ),
            )),
        );

        $result = $this->client->poll_commands(true);
        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['commands_executed']);
        $this->assertSame('success', $result['results'][0]['status']);

        $audit = clevers_remote_get_command_audit();
        $this->assertNotEmpty($audit);
        $this->assertSame('cmd-ping-1', $audit[0]['id']);
        $this->assertSame('ping', $audit[0]['type']);
        $this->assertSame('success', $audit[0]['status']);
    }

    public function test_poll_commands_rejects_disallowed_actions(): void {
        $GLOBALS['mock_state']['http_response'] = array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'commands' => array(
                    array(
                        'id' => 'cmd-disallowed',
                        'type' => 'activate_plugin',
                        'payload' => array('plugin' => 'hello.php'),
                    ),
                ),
            )),
        );

        $result = $this->client->poll_commands(true);
        $this->assertIsArray($result);
        $this->assertSame('failed', $result['results'][0]['status']);
        $this->assertStringContainsString('blocked', $result['results'][0]['message']);
    }
}
