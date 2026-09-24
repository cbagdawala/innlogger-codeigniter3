<?php

namespace InnLogger\CodeIgniter3\Tests\Unit;

use InnLogger\CodeIgniter3\Tests\Support\FakeTransport;
use InnLogger\CodeIgniter3\Tests\Support\TestCase;
use InnLogger\CodeIgniter3\Transport\Response;

final class TransportContractTest extends TestCase
{
    public function test_posts_to_the_logs_endpoint_with_a_json_body(): void
    {
        $transport = new FakeTransport();
        $this->client($transport)->error('Payment failed', ['order_id' => 42]);

        $this->assertSame(1, $transport->count());
        $this->assertSame('https://logger.example.com/api/v1/logs', $transport->requests[0]['url']);
        $payload = $transport->payload();
        $this->assertSame('Payment failed', $payload['message']);
        $this->assertSame(2, $payload['level']);
        $this->assertSame('ERROR', $payload['level_name']);
        $this->assertSame(['order_id' => 42], $payload['context']);
    }

    public function test_sends_every_authentication_header(): void
    {
        $transport = new FakeTransport();
        $before = time();
        $this->client($transport)->error('x');
        $headers = $transport->headers();

        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame(self::KEY, $headers['X-InnLogger-Key']);
        $this->assertMatchesRegularExpression('/^\d+$/', $headers['X-InnLogger-Timestamp']);
        $this->assertEqualsWithDelta($before, (int) $headers['X-InnLogger-Timestamp'], 2);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $headers['X-InnLogger-Nonce']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $headers['X-InnLogger-Signature']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $headers['X-InnLogger-Request-Id']);
    }

    public function test_signature_is_hmac_of_timestamp_nonce_and_the_exact_body_bytes(): void
    {
        $transport = new FakeTransport();
        $this->client($transport)->critical('System failure', ['unicode' => 'café ✓', 'path' => '/a/b']);
        $request = $transport->requests[0];
        $headers = $request['headers'];

        $expected = hash_hmac(
            'sha256',
            $headers['X-InnLogger-Timestamp'] . "\n" . $headers['X-InnLogger-Nonce'] . "\n" . $request['body'],
            self::SECRET
        );
        $this->assertSame($expected, $headers['X-InnLogger-Signature']);
        $this->assertStringNotContainsString(self::SECRET, $request['body']);
        $this->assertNotContains(self::SECRET, $headers, 'the secret is never sent');
    }

    public function test_every_request_gets_a_fresh_nonce_and_request_id(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->error('one');
        $client->error('two');

        $this->assertNotSame($transport->headers(0)['X-InnLogger-Nonce'], $transport->headers(1)['X-InnLogger-Nonce']);
        $this->assertNotSame($transport->headers(0)['X-InnLogger-Request-Id'], $transport->headers(1)['X-InnLogger-Request-Id']);
    }

    public function test_passes_short_timeouts_to_the_transport(): void
    {
        $transport = new FakeTransport();
        $this->client($transport, ['timeout' => 1.5, 'connect_timeout' => 5])->error('x');

        $options = $transport->requests[0]['options'];
        $this->assertSame(1.5, $options['timeout']);
        $this->assertSame(1.5, $options['connect_timeout'], 'connect timeout is capped at the total timeout');
        $this->assertTrue($options['verify_ssl']);
    }

    public function test_heartbeat_posts_to_the_heartbeat_endpoint(): void
    {
        $transport = new FakeTransport([new Response(204)]);
        $ok = $this->client($transport, ['log_level' => 0])->heartbeat('1.4.2');

        $this->assertTrue($ok, 'heartbeat ignores the log threshold');
        $this->assertSame('https://logger.example.com/api/v1/heartbeat', $transport->requests[0]['url']);
        $this->assertSame(['environment' => 'testing', 'hostname' => 'web01', 'application_version' => '1.4.2'], $transport->payload());
        $headers = $transport->headers();
        $expected = hash_hmac(
            'sha256',
            $headers['X-InnLogger-Timestamp'] . "\n" . $headers['X-InnLogger-Nonce'] . "\n" . $transport->requests[0]['body'],
            self::SECRET
        );
        $this->assertSame($expected, $headers['X-InnLogger-Signature']);
    }

    public function test_heartbeat_is_skipped_when_disabled(): void
    {
        $transport = new FakeTransport([new Response(204)]);
        $this->assertFalse($this->client($transport, ['enabled' => false])->heartbeat());
        $this->assertSame(0, $transport->count());
    }

    public function test_test_command_reports_diagnostics(): void
    {
        $transport = new FakeTransport();
        $result = $this->client($transport, ['log_level' => 0])->test();

        $this->assertTrue($result['configured']);
        $this->assertTrue($result['accepted']);
        $this->assertSame(202, $result['status']);
        $this->assertNotNull($result['event_id']);
        $this->assertSame($result['event_id'], $transport->payload()['event_id']);
    }

    public function test_test_command_reports_invalid_credentials(): void
    {
        $result = $this->client(FakeTransport::status(401, '{"success":false,"message":"Invalid credentials"}'))->test();

        $this->assertFalse($result['accepted']);
        $this->assertSame(401, $result['status']);
        $this->assertSame('HTTP 401 (Invalid credentials)', $result['error']);
    }
}
