<?php

namespace InnLogger\CodeIgniter3\Tests\Unit;

use InnLogger\CodeIgniter3\Client;
use InnLogger\CodeIgniter3\InnLoggerException;
use InnLogger\CodeIgniter3\Tests\Support\FakeTransport;
use InnLogger\CodeIgniter3\Tests\Support\TestCase;
use InnLogger\CodeIgniter3\Transport\Response;
use InnLogger\CodeIgniter3\Transport\TransportException;

final class FailureBehaviourTest extends TestCase
{
    public function test_network_failure_is_swallowed_and_reported_locally(): void
    {
        $transport = FakeTransport::failing();
        $client = $this->client($transport);

        $result = $client->error('Payment failed');

        $this->assertNull($result);
        $this->assertSame(1, $transport->count());
        $this->assertCount(1, $this->reported);
        $this->assertStringStartsWith('InnLogger: POST /api/v1/logs failed: transport failure', $this->reported[0]);
        $this->assertNotNull($client->lastError());
    }

    public function test_timeout_failure_never_leaks_the_secret(): void
    {
        $transport = new FakeTransport([new TransportException('timeout while sending ' . self::SECRET)]);
        $client = $this->client($transport);
        $client->error('x');

        $this->assertStringNotContainsString(self::SECRET, implode("\n", $this->reported));
        $this->assertStringContainsString('[REDACTED]', $this->reported[0]);
        $this->assertStringNotContainsString(self::SECRET, (string) $client->lastError());
    }

    public function test_unexpected_transport_errors_are_swallowed_too(): void
    {
        $transport = new FakeTransport([new \Error('curl extension exploded')]);
        $this->assertNull($this->client($transport)->critical('x'));
        $this->assertCount(1, $this->reported);
    }

    public function test_invalid_credentials_401_is_swallowed(): void
    {
        $transport = FakeTransport::status(401, '{"success":false,"message":"Invalid credentials"}');
        $this->assertNull($this->client($transport, ['retries' => 3])->error('x'));

        $this->assertSame(1, $transport->count(), '4xx is never retried');
        $this->assertSame(['InnLogger: POST /api/v1/logs failed: HTTP 401 (Invalid credentials)'], $this->reported);
    }

    public function test_validation_error_422_is_swallowed(): void
    {
        $transport = FakeTransport::status(422, '{"success":false,"message":"Validation failed","errors":{}}');
        $this->assertNull($this->client($transport)->error('x'));
        $this->assertStringContainsString('HTTP 422', $this->reported[0]);
    }

    public function test_duplicate_event_200_counts_as_delivered(): void
    {
        $transport = FakeTransport::status(200, '{"success":true,"message":"Duplicate event","data":{"log_id":"abc"}}');
        $eventId = $this->client($transport)->error('x');

        $this->assertSame($transport->payload()['event_id'], $eventId);
        $this->assertSame([], $this->reported);
    }

    public function test_retries_transient_failures_with_the_same_event_id_and_fresh_signatures(): void
    {
        $transport = new FakeTransport([
            new TransportException('connection refused'),
            new Response(503),
            new Response(202),
        ]);
        $eventId = $this->client($transport, ['retries' => 2])->error('x');

        $this->assertSame(3, $transport->count());
        $this->assertNotNull($eventId);
        $bodies = array_column($transport->requests, 'body');
        $this->assertCount(1, array_unique($bodies), 'retries re-send the identical body');
        foreach ([0, 1, 2] as $i) {
            $this->assertSame($eventId, $transport->payload($i)['event_id']);
        }
        $nonces = array_map(function ($r) {
            return $r['headers']['X-InnLogger-Nonce'];
        }, $transport->requests);
        $this->assertCount(3, array_unique($nonces), 'each attempt has its own nonce');
        $this->assertSame([], $this->reported);
    }

    public function test_retries_are_bounded(): void
    {
        $transport = FakeTransport::failing();
        $this->client($transport, ['retries' => 50])->error('x');
        $this->assertSame(4, $transport->count(), '1 attempt + at most 3 retries');
    }

    public function test_fail_silent_false_throws_innlogger_exception(): void
    {
        $transport = FakeTransport::status(401, '{"success":false,"message":"Invalid credentials"}');
        $client = $this->client($transport, ['fail_silent' => false]);

        try {
            $client->error('x');
            $this->fail('expected an exception');
        } catch (InnLoggerException $e) {
            $this->assertSame(401, $e->status());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
        $this->assertFalse(Client::isSending(), 'the recursion guard is released after a throw');
    }

    public function test_missing_credentials_send_nothing_and_report_once(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['api_secret' => '']);
        $this->assertNull($client->error('x'));
        $this->assertNull($client->error('y'));

        $this->assertSame(0, $transport->count());
        $this->assertCount(1, $this->reported);
    }

    public function test_plain_http_url_is_rejected_unless_allow_insecure(): void
    {
        $transport = new FakeTransport();
        $this->assertNull($this->client($transport, ['url' => 'http://logger.local'])->error('x'));
        $this->assertSame(0, $transport->count());
        $this->assertStringContainsString('https', $this->reported[0]);

        $this->assertNotNull($this->client($transport, ['url' => 'http://logger.local', 'allow_insecure' => true])->error('x'));
        $this->assertSame('http://logger.local/api/v1/logs', $transport->requests[0]['url']);
    }

    public function test_failing_local_reporter_is_swallowed(): void
    {
        $client = new Client(
            ['url' => 'https://l.test', 'api_key' => 'k', 'api_secret' => 's', 'log_level' => 7],
            FakeTransport::failing(),
            function () {
                throw new \RuntimeException('local log is broken');
            }
        );
        $this->assertNull($client->error('x'));
    }

    public function test_recursive_logging_from_the_reporter_is_ignored(): void
    {
        $transport = FakeTransport::failing();
        $client = null;
        $client = new Client(
            ['url' => 'https://l.test', 'api_key' => 'k', 'api_secret' => 's', 'log_level' => 7, 'retries' => 0],
            $transport,
            function ($line) use (&$client) {
                // e.g. a log_message() hook forwarding back into InnLogger
                $client->error('nested: ' . $line);
            }
        );
        $client->error('outer');
        $this->assertSame(1, $transport->count());
    }

    public function test_context_provider_errors_are_swallowed(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->setContextProvider(function () {
            throw new \RuntimeException('no CI instance');
        });

        $this->assertNotNull($client->error('x'));
    }

    public function test_unencodable_values_do_not_break_sending(): void
    {
        $transport = new FakeTransport();
        $resource = fopen('php://memory', 'r');
        $eventId = $this->client($transport)->error("bad utf8 \xB1\x31", ['bin' => "\xff\xfe", 'nan' => NAN, 'res' => $resource]);
        fclose($resource);

        $this->assertNotNull($eventId);
        $payload = $transport->payload();
        $this->assertSame('[resource stream]', $payload['context']['res']);
        $this->assertSame('NAN', $payload['context']['nan']);
    }
}
