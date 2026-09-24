<?php

namespace InnLogger\CodeIgniter3\Tests\Unit;

use InnLogger\CodeIgniter3\Normalizer;
use InnLogger\CodeIgniter3\Tests\Support\FakeTransport;
use InnLogger\CodeIgniter3\Tests\Support\TestCase;

final class EventPayloadTest extends TestCase
{
    const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function test_generates_a_unique_uuid_v4_event_id_per_event(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $first = $client->error('a');
        $second = $client->error('b');

        $this->assertMatchesRegularExpression(self::UUID_V4, $first);
        $this->assertMatchesRegularExpression(self::UUID_V4, $second);
        $this->assertNotSame($first, $second);
        $this->assertSame($first, $transport->payload(0)['event_id']);
        $this->assertSame($second, $transport->payload(1)['event_id']);
    }

    public function test_payload_matches_the_wire_contract(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['application_version' => '1.4.2']);
        $client->setContextProvider(function () {
            return [
                'url' => '/api/payment', 'http_method' => 'POST', 'request_id' => 'req_123',
                'user_id' => '123', 'controller' => 'payment', 'method' => 'charge', 'ci_version' => '3.1.13',
            ];
        });
        $line = __LINE__ + 1;
        $client->error('Payment failed', ['category' => 'payment', 'http_status' => 500, 'order' => 7]);
        $p = $transport->payload();

        $this->assertSame('payment', $p['category']);
        $this->assertSame('testing', $p['environment']);
        $this->assertSame('demo-app', $p['application']);
        $this->assertSame('web01', $p['hostname']);
        $this->assertSame('req_123', $p['request_id']);
        $this->assertSame(123, $p['user_id']);
        $this->assertSame('/api/payment', $p['url']);
        $this->assertSame('POST', $p['http_method']);
        $this->assertSame(500, $p['http_status']);
        $this->assertSame(__FILE__, $p['file'], 'file/line point at the caller, not the SDK');
        $this->assertSame($line, $p['line']);
        $this->assertSame(['order' => 7], $p['context'], 'reserved keys are lifted out of context');
        $this->assertSame('payment', $p['metadata']['controller']);
        $this->assertSame('charge', $p['metadata']['method']);
        $this->assertSame('3.1.13', $p['metadata']['ci_version']);
        $this->assertSame('1.4.2', $p['metadata']['application_version']);
        $this->assertSame('innlogger-codeigniter3', $p['metadata']['sdk']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $p['occurred_at']);
        $this->assertArrayNotHasKey('exception', $p);
    }

    public function test_explicit_context_values_override_auto_context(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->setContextProvider(function () {
            return ['user_id' => 1, 'url' => '/auto'];
        });
        $client->info('x', ['user_id' => 99, 'url' => '/explicit']);

        $this->assertSame(99, $transport->payload()['user_id']);
        $this->assertSame('/explicit', $transport->payload()['url']);
    }

    public function test_capture_request_context_false_skips_the_provider(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['capture_request_context' => false]);
        $client->setContextProvider(function () {
            return ['url' => '/auto'];
        });
        $client->info('x');
        $this->assertArrayNotHasKey('url', $transport->payload());
    }

    public function test_exception_is_normalized(): void
    {
        $transport = new FakeTransport();
        $previous = new \InvalidArgumentException('root cause', 3);
        $line = __LINE__ + 1;
        $exception = new \RuntimeException('Gateway timeout', 504, $previous);

        $eventId = $this->client($transport)->exception($exception, ['order_id' => 1]);
        $p = $transport->payload();

        $this->assertNotNull($eventId);
        $this->assertSame(2, $p['level'], 'exception() defaults to ERROR');
        $this->assertSame('Gateway timeout', $p['message']);
        $this->assertSame('RuntimeException', $p['exception']['class']);
        $this->assertSame('Gateway timeout', $p['exception']['message']);
        $this->assertSame(__FILE__, $p['exception']['file']);
        $this->assertSame($line, $p['exception']['line']);
        $this->assertIsString($p['exception']['trace']);
        $this->assertStringContainsString(__FUNCTION__, $p['exception']['trace']);
        $this->assertSame(__FILE__, $p['file']);
        $this->assertSame($line, $p['line']);
        $this->assertSame(['order_id' => 1], $p['context']);
        $this->assertSame(504, $p['metadata']['exception_code']);
        $this->assertSame('InvalidArgumentException', $p['metadata']['previous_exceptions'][0]['class']);
        $this->assertSame('root cause', $p['metadata']['previous_exceptions'][0]['message']);
        $this->assertSame(['class', 'message', 'file', 'line', 'trace'], array_keys($p['exception']));
    }

    public function test_exception_level_can_be_overridden_and_empty_message_uses_class(): void
    {
        $transport = new FakeTransport();
        $this->client($transport)->exception(new \LogicException(''), [], 'critical');
        $this->assertSame(1, $transport->payload()['level']);
        $this->assertSame('LogicException', $transport->payload()['message']);
    }

    public function test_psr3_style_exception_in_context(): void
    {
        $transport = new FakeTransport();
        $this->client($transport)->critical('Checkout crashed', ['exception' => new \DomainException('nope'), 'cart' => 5]);
        $p = $transport->payload();

        $this->assertSame('Checkout crashed', $p['message']);
        $this->assertSame('DomainException', $p['exception']['class']);
        $this->assertSame(['cart' => 5], $p['context']);
    }

    public function test_errors_are_normalized_as_throwables(): void
    {
        $transport = new FakeTransport();
        $this->client($transport)->exception(new \TypeError('bad type'));
        $this->assertSame('TypeError', $transport->payload()['exception']['class']);
    }

    public function test_large_trace_and_context_are_truncated_to_stay_within_limits(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->error('big', ['blob' => str_repeat('a', 70000)]);
        $p = $transport->payload();

        $this->assertTrue($p['context']['_truncated']);
        $this->assertLessThanOrEqual(262144, strlen($transport->requests[0]['body']));
        $this->assertSame(70, strlen(Normalizer::truncate(str_repeat('x', 100), 70)));
    }

    public function test_objects_are_serialized_safely(): void
    {
        $transport = new FakeTransport();
        $json = new class implements \JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['a' => 1, 'password' => 'hunter2'];
            }
        };
        $plain = new \stdClass();
        $this->client($transport)->info('objects', [
            'json' => $json,
            'plain' => $plain,
            'date' => new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
            'nested_exception' => new \RuntimeException('inner'),
        ]);
        $c = $transport->payload()['context'];

        $this->assertSame(['a' => 1, 'password' => '[REDACTED]'], $c['json']);
        $this->assertSame('[object stdClass]', $c['plain']);
        $this->assertSame('2026-01-02T03:04:05+00:00', $c['date']);
        $this->assertSame('RuntimeException', $c['nested_exception']['class']);
    }

    public function test_non_string_messages_are_stringified(): void
    {
        $transport = new FakeTransport();
        $this->client($transport)->info(['a' => 1]);
        $this->assertSame('{"a":1}', $transport->payload()['message']);
    }
}
