<?php

namespace InnLogger\CodeIgniter3\Tests\Support;

use InnLogger\CodeIgniter3\Client;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    const KEY = 'ilv_testkey1234567890abcdefghijklmn';
    const SECRET = 'ils_testsecret_abcdefghijklmnopqrstuvwxyz0123456789AB';

    /** @var string[] */
    protected $reported = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reported = [];
        $GLOBALS['__ci_log'] = [];
        $GLOBALS['__ci_instance'] = null;
        \Innlogger::reset();
        Client::resetRateLimit();
    }

    protected function tearDown(): void
    {
        \Innlogger::reset();
        Client::resetRateLimit();
        $GLOBALS['__ci_instance'] = null;
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_REQUEST_ID']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function client(FakeTransport $transport, array $overrides = []): Client
    {
        $config = array_merge([
            'url' => 'https://logger.example.com/',
            'api_key' => self::KEY,
            'api_secret' => self::SECRET,
            'log_level' => 7,
            'environment' => 'testing',
            'application' => 'demo-app',
            'hostname' => 'web01',
            'retries' => 0,
            'retry_delay_ms' => 0,
        ], $overrides);

        $reported = &$this->reported;

        return new Client($config, $transport, static function ($line) use (&$reported) {
            $reported[] = $line;
        });
    }
}
