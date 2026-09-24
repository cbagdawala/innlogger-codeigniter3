<?php

namespace InnLogger\CodeIgniter3\Tests\Unit;

use InnLogger\CodeIgniter3\Tests\Support\FakeTransport;
use InnLogger\CodeIgniter3\Tests\Support\TestCase;

final class ThresholdTest extends TestCase
{
    const METHODS = [1 => 'critical', 2 => 'error', 3 => 'warning', 4 => 'notice', 5 => 'info', 6 => 'debug', 7 => 'trace'];

    public function test_every_severity_method_sends_its_level_and_name(): void
    {
        $names = [1 => 'CRITICAL', 2 => 'ERROR', 3 => 'WARNING', 4 => 'NOTICE', 5 => 'INFO', 6 => 'DEBUG', 7 => 'TRACE'];
        $transport = new FakeTransport();
        $client = $this->client($transport, ['log_level' => 7]);

        foreach (self::METHODS as $level => $method) {
            $this->assertNotNull($client->{$method}("msg {$method}"));
        }

        $this->assertSame(7, $transport->count());
        foreach (array_keys(self::METHODS) as $i => $level) {
            $payload = $transport->payload($i);
            $this->assertSame($level, $payload['level']);
            $this->assertSame($names[$level], $payload['level_name']);
        }
    }

    /**
     * @return array<string, array{int, int[]}>
     */
    public function thresholds(): array
    {
        return [
            '0 = off' => [0, []],
            '1 = critical only' => [1, [1]],
            '2 = critical + error' => [2, [1, 2]],
            '3 = up to warning' => [3, [1, 2, 3]],
            '5 = critical through info' => [5, [1, 2, 3, 4, 5]],
            '7 = everything' => [7, [1, 2, 3, 4, 5, 6, 7]],
        ];
    }

    /**
     * @dataProvider thresholds
     * @param int[] $expectedLevels
     */
    public function test_threshold_sends_levels_at_or_above_the_configured_severity(int $threshold, array $expectedLevels): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['log_level' => $threshold]);

        foreach (self::METHODS as $method) {
            $client->{$method}('x');
        }

        $sent = array_map(function ($i) use ($transport) {
            return $transport->payload($i)['level'];
        }, array_keys($transport->requests));
        $this->assertSame($expectedLevels, $sent);
    }

    public function test_threshold_zero_also_blocks_exceptions(): void
    {
        $transport = new FakeTransport();
        $this->assertNull($this->client($transport, ['log_level' => 0])->exception(new \RuntimeException('boom'), [], 1));
        $this->assertSame(0, $transport->count());
    }

    public function test_threshold_accepts_level_names(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['log_level' => 'warning']);
        $client->warning('sent');
        $client->notice('dropped');
        $this->assertSame(1, $transport->count());
    }

    public function test_invalid_threshold_falls_back_to_error(): void
    {
        $client = $this->client(new FakeTransport(), ['log_level' => 99]);
        $this->assertSame(2, $client->config()->threshold());
    }

    public function test_disabled_sdk_sends_nothing(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['enabled' => false]);

        $this->assertNull($client->critical('x'));
        $this->assertNull($client->exception(new \RuntimeException('x')));
        $this->assertSame(0, $transport->count());
        $this->assertSame([], $this->reported);
    }

    public function test_enabled_accepts_env_style_strings(): void
    {
        $transport = new FakeTransport();
        $this->client($transport, ['enabled' => 'false'])->critical('x');
        $this->assertSame(0, $transport->count());
    }

    public function test_log_with_an_invalid_level_is_ignored(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $this->assertNull($client->log(0, 'off'));
        $this->assertNull($client->log(9, 'nine'));
        $this->assertNull($client->log('nope', 'bad'));
        $this->assertSame(0, $transport->count());
    }
}
