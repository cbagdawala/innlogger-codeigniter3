<?php

namespace InnLogger\CodeIgniter3\Tests\Unit;

use InnLogger\CodeIgniter3\Client;
use InnLogger\CodeIgniter3\CodeIgniter\ContextProvider;
use InnLogger\CodeIgniter3\Config;
use InnLogger\CodeIgniter3\Redactor;
use InnLogger\CodeIgniter3\Tests\Support\FakeTransport;
use InnLogger\CodeIgniter3\Tests\Support\TestCase;

/**
 * The drop-in application/libraries/Innlogger.php, exercised with stubbed CI3 globals
 * (see tests/bootstrap.php) instead of a CodeIgniter install.
 */
final class CodeIgniterLibraryTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function params(FakeTransport $transport, array $overrides = []): array
    {
        return ['innlogger' => array_merge([
            'url' => 'https://logger.example.com',
            'api_key' => self::KEY,
            'api_secret' => self::SECRET,
            'log_level' => 7,
            'transport' => $transport,
        ], $overrides)];
    }

    public function test_loader_style_params_configure_the_library(): void
    {
        $transport = new FakeTransport();
        $lib = new \Innlogger($this->params($transport));

        $this->assertTrue($lib->enabled());
        $this->assertNotNull($lib->error('Payment failed', ['order' => 1]));
        $p = $transport->payload();
        $this->assertSame('ERROR', $p['level_name']);
        $this->assertSame('testing', $p['environment'], 'defaults to the CI ENVIRONMENT constant');
        $this->assertSame('3.1.13', $p['metadata']['ci_version']);
        $this->assertSame(__FILE__, $p['file']);
    }

    public function test_all_severity_methods_and_exception_are_exposed(): void
    {
        $transport = new FakeTransport();
        $lib = new \Innlogger($this->params($transport));

        foreach (['critical', 'error', 'warning', 'notice', 'info', 'debug', 'trace'] as $method) {
            $this->assertNotNull($lib->{$method}($method));
        }
        $this->assertNotNull($lib->exception(new \RuntimeException('boom')));
        $this->assertNotNull($lib->log('warning', 'via log()'));

        $levels = array_map(function ($i) use ($transport) {
            return $transport->payload($i)['level'];
        }, array_keys($transport->requests));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 2, 3], $levels);
    }

    public function test_flat_params_are_accepted(): void
    {
        $transport = new FakeTransport();
        $params = $this->params($transport)['innlogger'];
        $lib = new \Innlogger($params);
        $lib->critical('x');
        $this->assertSame(1, $transport->count());
    }

    public function test_config_file_and_environment_override_are_loaded_without_params(): void
    {
        $lib = new \Innlogger();
        $config = $lib->get_client()->config();

        $this->assertSame('https://logger.test', $config->get('url'));
        $this->assertSame('ilv_fromconfigfile', $config->get('api_key'));
        $this->assertSame('fixture-app', $config->get('application'));
        $this->assertSame(4, $config->threshold(), 'config/testing/innlogger.php overrides log_level');
    }

    public function test_client_is_shared_between_instances(): void
    {
        $transport = new FakeTransport();
        $a = new \Innlogger($this->params($transport));
        $b = new \Innlogger();
        $this->assertSame($a->get_client(), $b->get_client());
        $this->assertSame($a->get_client(), \Innlogger::shared());
    }

    public function test_failures_are_reported_through_log_message_without_the_secret(): void
    {
        $lib = new \Innlogger($this->params(FakeTransport::failing()));
        $this->assertNull($lib->error('x'));

        $this->assertCount(1, $GLOBALS['__ci_log']);
        $this->assertSame('error', $GLOBALS['__ci_log'][0][0]);
        $this->assertStringStartsWith('InnLogger:', $GLOBALS['__ci_log'][0][1]);
        $this->assertStringNotContainsString(self::SECRET, $GLOBALS['__ci_log'][0][1]);
    }

    public function test_non_array_context_and_non_throwable_exception_are_tolerated(): void
    {
        $transport = new FakeTransport();
        $lib = new \Innlogger($this->params($transport));

        $this->assertNotNull($lib->error('legacy call', 'order 5'));
        $this->assertSame(['context' => 'order 5'], $transport->payload()['context']);
        $this->assertNull($lib->exception('not an exception'));
    }

    public function test_heartbeat_and_test_are_exposed(): void
    {
        $transport = new FakeTransport();
        $lib = new \Innlogger($this->params($transport));
        $this->assertTrue($lib->heartbeat('2.0.0'));
        $this->assertSame('https://logger.example.com/api/v1/heartbeat', $transport->requests[0]['url']);
        $this->assertTrue($lib->test()['accepted']);
    }

    public function test_request_context_is_collected_from_the_ci_instance(): void
    {
        $GLOBALS['__ci_instance'] = $this->fakeCi(['user_id' => '42']);
        $_SERVER['REQUEST_METHOD'] = 'post';
        $_SERVER['REQUEST_URI'] = '/payment/charge?id=5&token=abc&Password=x';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'req-abc-123';

        $config = Config::fromArray(['user_id_session_key' => 'user_id']);
        $context = (new ContextProvider($config, new Redactor(), false))();

        $this->assertSame('POST', $context['http_method']);
        $this->assertSame('/payment/charge?id=5&token=%5BREDACTED%5D&Password=%5BREDACTED%5D', $context['url']);
        $this->assertSame('req-abc-123', $context['request_id']);
        $this->assertSame('payment', $context['controller']);
        $this->assertSame('charge', $context['method']);
        $this->assertSame('42', $context['user_id']);
        $this->assertSame('3.1.13', $context['ci_version']);
    }

    public function test_user_id_resolver_takes_precedence_and_cli_uses_the_uri_segment(): void
    {
        $GLOBALS['__ci_instance'] = $this->fakeCi([]);
        $config = Config::fromArray(['user_id_resolver' => function ($ci) {
            return $ci->auth_user_id;
        }]);
        $context = (new ContextProvider($config, new Redactor(), true))();

        $this->assertSame(7, $context['user_id']);
        $this->assertSame('cli:payment/charge', $context['url']);
        $this->assertArrayNotHasKey('http_method', $context);
    }

    public function test_context_without_a_ci_instance_still_has_a_request_id(): void
    {
        $context = (new ContextProvider(Config::fromArray([]), new Redactor(), true))();
        $this->assertSame(Client::processRequestId(), $context['request_id']);
        $this->assertArrayNotHasKey('controller', $context);
    }

    public function test_unsafe_request_id_headers_are_ignored(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = "bad\nheader value";
        $context = (new ContextProvider(Config::fromArray([]), new Redactor(), true))();
        $this->assertSame(Client::processRequestId(), $context['request_id']);
    }

    public function test_library_attaches_ci_context_to_events(): void
    {
        $GLOBALS['__ci_instance'] = $this->fakeCi(['uid' => 9]);
        $transport = new FakeTransport();
        $lib = new \Innlogger($this->params($transport, ['user_id_session_key' => 'uid']));
        $lib->error('x');
        $p = $transport->payload();

        $this->assertSame(9, $p['user_id']);
        $this->assertSame('payment', $p['metadata']['controller']);
        $this->assertSame('charge', $p['metadata']['method']);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function fakeCi(array $session)
    {
        $ci = new \stdClass();
        $ci->router = new \stdClass();
        $ci->router->class = 'payment';
        $ci->router->method = 'charge';
        $ci->uri = new class {
            public function uri_string()
            {
                return 'payment/charge';
            }
        };
        $ci->session = new class($session) {
            private $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function userdata($key)
            {
                return isset($this->data[$key]) ? $this->data[$key] : null;
            }
        };
        $ci->auth_user_id = 7;

        return $ci;
    }
}
