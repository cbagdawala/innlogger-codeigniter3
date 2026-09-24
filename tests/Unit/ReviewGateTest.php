<?php

namespace InnLogger\CodeIgniter3\Tests\Unit;

use InnLogger\CodeIgniter3\Client;
use InnLogger\CodeIgniter3\Config;
use InnLogger\CodeIgniter3\Redactor;
use InnLogger\CodeIgniter3\Secret;
use InnLogger\CodeIgniter3\Tests\Support\FakeTransport;
use InnLogger\CodeIgniter3\Tests\Support\TestCase;
use InnLogger\CodeIgniter3\Transport\Response;

/**
 * Review-gate fixes: secret dumps, value masking, category default, stable request id,
 * 429 cooldown and the retries default.
 */
final class ReviewGateTest extends TestCase
{
    // ------------------------------------------------------------ 1. secrets never dumped

    /**
     * @return string[] every way a developer might dump an object
     */
    private function dumps($value): array
    {
        ob_start();
        var_dump($value);
        $varDump = (string) ob_get_clean();
        ob_start();
        debug_zval_dump($value);
        $zval = (string) ob_get_clean();

        return [
            'print_r' => print_r($value, true),
            'var_export' => var_export($value, true),
            'var_dump' => $varDump,
            'debug_zval_dump' => $zval,
            'serialize' => serialize($value),
            'json_encode' => (string) json_encode($value),
        ];
    }

    public function test_config_dumps_and_serialization_never_contain_the_secret(): void
    {
        $config = Config::fromArray(['url' => 'https://l.test', 'api_key' => self::KEY, 'api_secret' => self::SECRET]);

        foreach ($this->dumps($config) as $how => $output) {
            $this->assertStringNotContainsString(self::SECRET, $output, $how);
        }
        $this->assertSame('[REDACTED]', $config->__debugInfo()['api_secret']);
        $this->assertStringContainsString('[REDACTED]', print_r($config, true));
        $this->assertSame(self::SECRET, $config->get('api_secret'), 'the client can still read it');
        $this->assertSame('(not set)', Config::fromArray([])->__debugInfo()['api_secret']);
    }

    public function test_unserialized_config_has_no_secret_and_cannot_send(): void
    {
        $config = Config::fromArray(['url' => 'https://l.test', 'api_key' => self::KEY, 'api_secret' => self::SECRET]);
        $copy = unserialize(serialize($config));

        $this->assertInstanceOf(Config::class, $copy);
        $this->assertSame('', $copy->get('api_secret'));
        $this->assertFalse($copy->hasCredentials());
        $this->assertSame('https://l.test', $copy->get('url'));
    }

    public function test_client_and_ci_library_dumps_never_contain_the_secret(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['user_id_resolver' => function () {
            return 1;
        }]);
        $lib = new \Innlogger(['innlogger' => [
            'url' => 'https://l.test', 'api_key' => self::KEY, 'api_secret' => self::SECRET, 'transport' => $transport,
        ]]);

        foreach (['client' => $client, 'library' => $lib] as $name => $object) {
            foreach ($this->dumps($object) as $how => $output) {
                if ($name === 'library' && $how === 'serialize') {
                    continue; // covered below: the closure-free client serializes, the library is not meant to
                }
                $this->assertStringNotContainsString(self::SECRET, $output, $name . ' ' . $how);
            }
        }
        $this->assertStringNotContainsString(self::SECRET, serialize($client));
        $this->assertSame('', unserialize(serialize($client))->config()->get('api_secret'));
    }

    public function test_redactor_and_secret_objects_do_not_reveal_literals(): void
    {
        $redactor = new Redactor([], [], [self::SECRET]);
        foreach ($this->dumps($redactor) as $how => $output) {
            $this->assertStringNotContainsString(self::SECRET, $output, $how);
        }
        $secret = new Secret(self::SECRET);
        $clone = clone $secret;
        $this->assertSame(self::SECRET, $clone->reveal());
        $this->assertSame('[REDACTED]', (string) $secret);
        foreach ($this->dumps($secret) as $how => $output) {
            $this->assertStringNotContainsString(self::SECRET, $output, $how);
        }
    }

    public function test_signer_hides_the_secret_from_stack_traces_on_php_82_plus(): void
    {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('#[SensitiveParameter] needs PHP 8.2');
        }
        $attributes = (new \ReflectionMethod(\InnLogger\CodeIgniter3\Signer::class, 'sign'))->getParameters()[3]->getAttributes();
        $this->assertSame('SensitiveParameter', $attributes[0]->getName());
        $attributes = (new \ReflectionMethod(Config::class, 'fromArray'))->getParameters()[0]->getAttributes();
        $this->assertSame('SensitiveParameter', $attributes[0]->getName());
    }

    // ------------------------------------------------------------ 2. value masking

    public function test_message_exception_and_trace_are_masked_before_signing(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['mask_patterns' => ['/\b\d{3}-\d{2}-\d{4}\b/' => '[SSN]', '/acct-\d+/']]);

        $exception = $this->throwWith('Upstream said: Authorization: Bearer abc.def-ghi_123= for ' . self::SECRET);
        $client->exception($exception, [
            'note' => 'basic auth was Basic dXNlcjpwYXNz and ssn 123-45-6789',
            'other_secret' => 'ils_ABCDEFGHIJKLMNOPQRSTUVWX',
        ]);
        $client->error('Customer 123-45-6789 on acct-998877 used Digest abcdef0123 with ils_ZYXWVUTSRQPONMLK');

        $body = $transport->requests[0]['body'] . $transport->requests[1]['body'];
        foreach ([self::SECRET, 'abc.def-ghi_123', 'dXNlcjpwYXNz', '123-45-6789', 'ils_ABCDEFGHIJKLMNOPQRSTUVWX', 'acct-998877', 'abcdef0123', 'ils_ZYXWVUTSRQPONMLK'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, $leak);
        }
        $first = $transport->payload(0);
        $this->assertStringContainsString('Bearer [REDACTED]', $first['message']);
        $this->assertStringContainsString('Bearer [REDACTED]', $first['exception']['message']);
        $this->assertStringNotContainsString(self::SECRET, $first['exception']['trace'], 'scalar args in the trace are masked');
        $this->assertStringContainsString('[SSN]', $first['context']['note']);
        $this->assertSame(
            'Customer [SSN] on [REDACTED] used Digest [REDACTED] with [REDACTED]',
            $transport->payload(1)['message']
        );

        // the signature covers the masked bytes that were actually sent
        $h = $transport->headers(0);
        $this->assertSame(
            hash_hmac('sha256', $h['X-InnLogger-Timestamp'] . "\n" . $h['X-InnLogger-Nonce'] . "\n" . $transport->requests[0]['body'], self::SECRET),
            $h['X-InnLogger-Signature']
        );
    }

    public function test_mask_patterns_are_applied_to_the_trace(): void
    {
        $transport = new FakeTransport();
        $this->client($transport, ['mask_patterns' => ['/->explode\(/' => '->[MASKED](']])
            ->exception($this->throwWith('x'));
        $trace = $transport->payload()['exception']['trace'];

        $this->assertStringContainsString('->[MASKED](', $trace);
        $this->assertStringNotContainsString('->explode(', $trace);
    }

    public function test_previous_exception_messages_and_urls_are_masked(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->setContextProvider(function () {
            return ['url' => '/callback?code=Bearer%20x&next=/home Bearer leakedtoken1'];
        });
        $previous = new \RuntimeException('token ils_PREVIOUSSECRET01 rejected');
        $client->exception(new \LogicException('wrapper', 0, $previous));
        $p = $transport->payload();

        $this->assertSame('token [REDACTED] rejected', $p['metadata']['previous_exceptions'][0]['message']);
        $this->assertStringNotContainsString('leakedtoken1', $p['url']);
    }

    public function test_invalid_mask_patterns_are_ignored(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['mask_patterns' => ['/unclosed(' => 'x', 42 => '/ok\d/']]);
        $client->error('value ok7 stays safe');

        $this->assertSame(['/ok\d/' => '[REDACTED]'], $client->config()->get('mask_patterns'));
        $this->assertSame('value [REDACTED] stays safe', $transport->payload()['message']);
    }

    public function test_short_secrets_are_not_used_as_literal_masks(): void
    {
        $transport = new FakeTransport();
        $client = new Client(['url' => 'https://l.test', 'api_key' => 'k', 'api_secret' => 's', 'log_level' => 7, 'retries' => 0], $transport, function () {
        });
        $client->error('this sentence has several s letters');
        $this->assertSame('this sentence has several s letters', $transport->payload()['message']);
    }

    // ------------------------------------------------------------ 3. category

    public function test_category_defaults_to_application_and_exception_and_is_configurable(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->info('plain');
        $client->exception(new \RuntimeException('boom'));
        $client->critical('psr3', ['exception' => new \RuntimeException('x')]);
        $client->error('explicit', ['category' => 'payment']);
        $client->exception(new \RuntimeException('explicit'), ['category' => 'billing']);

        $this->assertSame('application', $transport->payload(0)['category']);
        $this->assertSame('exception', $transport->payload(1)['category']);
        $this->assertSame('exception', $transport->payload(2)['category']);
        $this->assertSame('payment', $transport->payload(3)['category']);
        $this->assertSame('billing', $transport->payload(4)['category']);

        $custom = new FakeTransport();
        $this->client($custom, ['category' => 'legacy-app'])->info('x');
        $this->assertSame('legacy-app', $custom->payload()['category']);
    }

    // ------------------------------------------------------------ 4. request id

    public function test_request_id_is_stable_across_retries_and_new_per_send(): void
    {
        $transport = new FakeTransport([new Response(503), new Response(502), new Response(202), new Response(202)]);
        $client = $this->client($transport, ['retries' => 3]);
        $client->error('first');
        $client->error('second');

        $ids = array_map(function ($r) {
            return $r['headers']['X-InnLogger-Request-Id'];
        }, $transport->requests);
        $this->assertCount(4, $ids);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame($ids[0], $ids[2]);
        $this->assertNotSame($ids[0], $ids[3], 'a new logical send gets a new request id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $ids[0]);
    }

    // ------------------------------------------------------------ 5. 429 cooldown + retries

    public function test_429_pauses_sending_for_retry_after_from_the_body(): void
    {
        $now = 1000;
        $transport = new FakeTransport([
            new Response(429, '{"success":false,"message":"Rate limit exceeded","retry_after":30}'),
            new Response(202),
        ]);
        $client = $this->client($transport, ['retries' => 3])->setClock(function () use (&$now) {
            return $now;
        });

        $this->assertNull($client->error('first'));
        $this->assertSame(1, $transport->count(), '429 is not retried');
        $this->assertSame(1030, Client::pausedUntil());
        $this->assertCount(1, $this->reported);

        $now = 1029;
        $this->assertNull($client->error('during cooldown'));
        $this->assertFalse($client->heartbeat());
        $this->assertSame(1, $transport->count(), 'nothing is sent during the cooldown');
        $this->assertCount(1, $this->reported, 'drops during the cooldown are not re-reported');

        $now = 1030;
        $this->assertNotNull($client->error('after cooldown'));
        $this->assertSame(2, $transport->count());
    }

    public function test_429_cooldown_is_shared_across_clients_in_the_process(): void
    {
        $first = FakeTransport::status(429, '{"retry_after":60}');
        $this->client($first)->error('x');

        $second = new FakeTransport();
        $this->assertNull($this->client($second)->error('y'));
        $this->assertSame(0, $second->count());
    }

    public function test_429_uses_the_retry_after_header_and_clamps_it(): void
    {
        $clock = function () {
            return 5000;
        };
        $this->client(new FakeTransport([new Response(429, '', ['Retry-After' => '120'])]))->setClock($clock)->error('x');
        $this->assertSame(5120, Client::pausedUntil());

        Client::resetRateLimit();
        $this->client(new FakeTransport([new Response(429, '{"retry_after":999999}')]))->setClock($clock)->error('x');
        $this->assertSame(5000 + 3600, Client::pausedUntil());

        Client::resetRateLimit();
        $this->client(new FakeTransport([new Response(429, '{"retry_after":0}')]))->setClock($clock)->error('x');
        $this->assertSame(5001, Client::pausedUntil());

        Client::resetRateLimit();
        $this->client(new FakeTransport([new Response(429, 'not json')]))->setClock($clock)->error('x');
        $this->assertSame(5060, Client::pausedUntil(), 'no hint: 60 s');
    }

    public function test_429_never_throws_even_when_fail_silent_is_off(): void
    {
        $client = $this->client(FakeTransport::status(429, '{"retry_after":5}'), ['fail_silent' => false]);
        $this->assertNull($client->error('x'));
    }

    public function test_retries_default_to_one_and_cap_at_three(): void
    {
        $this->assertSame(1, Config::fromArray([])->get('retries'));
        $this->assertSame(3, Config::fromArray(['retries' => 9])->get('retries'));

        $transport = FakeTransport::failing();
        $client = new Client(
            ['url' => 'https://l.test', 'api_key' => 'k', 'api_secret' => self::SECRET, 'log_level' => 7, 'retry_delay_ms' => 0],
            $transport,
            function () {
            }
        );
        $client->error('x');
        $this->assertSame(2, $transport->count(), '1 attempt + 1 default retry');
    }

    public function test_only_gateway_errors_are_retried(): void
    {
        foreach ([500 => 1, 502 => 2, 503 => 2, 504 => 2, 401 => 1, 422 => 1] as $status => $expected) {
            $transport = FakeTransport::status($status);
            $this->client($transport, ['retries' => 1])->error('x');
            $this->assertSame($expected, $transport->count(), 'HTTP ' . $status);
        }
    }

    public function test_response_header_parsing(): void
    {
        $headers = Response::parseHeaderLines(["Retry-After: 30\r\n", "Content-Type: application/json\r\n", "\r\n"]);
        $this->assertSame(['retry-after' => '30', 'content-type' => 'application/json'], $headers);
        $this->assertSame('30', (new Response(429, '', $headers))->header('Retry-After'));
    }

    private function throwWith(string $message): \Throwable
    {
        try {
            $this->explode($message, self::SECRET);
        } catch (\RuntimeException $e) {
            return $e;
        }
        $this->fail('expected an exception');
    }

    private function explode(string $message, string $secretArgument): void
    {
        throw new \RuntimeException($message);
    }
}
