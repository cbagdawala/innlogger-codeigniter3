<?php

namespace InnLogger\CodeIgniter3\Tests\Unit;

use InnLogger\CodeIgniter3\ErrorHandler;
use InnLogger\CodeIgniter3\Level;
use InnLogger\CodeIgniter3\Tests\Support\FakeTransport;
use InnLogger\CodeIgniter3\Tests\Support\TestCase;

final class ErrorHandlerTest extends TestCase
{
    /** @var ErrorHandler|null */
    private $handler;

    protected function tearDown(): void
    {
        if ($this->handler !== null) {
            $this->handler->uninstall();
        }
        parent::tearDown();
    }

    public function test_uncaught_exception_is_reported_as_critical_then_the_previous_handler_runs(): void
    {
        $seen = [];
        set_exception_handler(function ($e) use (&$seen) {
            $seen[] = $e;
        });
        $transport = new FakeTransport();
        $this->handler = new ErrorHandler($this->client($transport));
        $this->handler->installExceptionHandler();

        $exception = new \RuntimeException('uncaught');
        $this->handler->handleException($exception);
        $this->handler->uninstall();
        $this->handler = null;
        restore_exception_handler();

        $this->assertSame([$exception], $seen, 'CI\'s own handler still receives the exception');
        $this->assertSame(1, $transport->count());
        $this->assertSame(Level::CRITICAL, $transport->payload()['level']);
        $this->assertSame('RuntimeException', $transport->payload()['exception']['class']);
    }

    public function test_previous_exception_handler_runs_even_when_innlogger_is_down(): void
    {
        $seen = 0;
        set_exception_handler(function () use (&$seen) {
            $seen++;
        });
        $this->handler = new ErrorHandler($this->client(FakeTransport::failing(), ['fail_silent' => false]));
        $this->handler->installExceptionHandler();
        $this->handler->handleException(new \RuntimeException('x'));
        $this->handler->uninstall();
        $this->handler = null;
        restore_exception_handler();

        $this->assertSame(1, $seen);
    }

    public function test_php_warning_is_reported_and_chained_to_the_previous_error_handler(): void
    {
        $calls = [];
        set_error_handler(function ($severity, $message, $file, $line) use (&$calls) {
            $calls[] = [$severity, $message];

            return true;
        });
        $transport = new FakeTransport();
        $this->handler = new ErrorHandler($this->client($transport), Level::WARNING);
        $this->handler->installErrorHandler();

        $result = $this->handler->handleError(E_USER_WARNING, 'disk almost full', __FILE__, 10);

        $this->handler->uninstall();
        $this->handler = null;
        restore_error_handler();

        $this->assertTrue($result, 'the previous handler\'s return value is passed through');
        $this->assertSame([[E_USER_WARNING, 'disk almost full']], $calls);
        $p = $transport->payload();
        $this->assertSame(Level::WARNING, $p['level']);
        $this->assertSame('ErrorException', $p['exception']['class']);
        $this->assertSame(__FILE__, $p['file']);
        $this->assertSame(10, $p['line']);
    }

    public function test_errors_below_the_error_level_or_suppressed_are_not_reported(): void
    {
        $transport = new FakeTransport();
        $this->handler = new ErrorHandler($this->client($transport), Level::WARNING);

        $this->assertFalse($this->handler->handleError(E_USER_NOTICE, 'notice', __FILE__, 1), 'no previous handler: PHP continues');
        $this->assertSame(0, $transport->count());

        $previous = error_reporting(0);
        try {
            $this->handler->handleError(E_USER_WARNING, 'suppressed', __FILE__, 1);
        } finally {
            error_reporting($previous);
        }
        $this->assertSame(0, $transport->count());
    }

    public function test_identical_errors_are_reported_once(): void
    {
        $transport = new FakeTransport();
        $this->handler = new ErrorHandler($this->client($transport), Level::WARNING);
        for ($i = 0; $i < 5; $i++) {
            $this->handler->handleError(E_USER_WARNING, 'same', __FILE__, 99);
        }
        $this->assertSame(1, $transport->count());
    }

    public function test_severity_mapping(): void
    {
        $this->assertSame(Level::CRITICAL, ErrorHandler::levelForSeverity(E_ERROR));
        $this->assertSame(Level::ERROR, ErrorHandler::levelForSeverity(E_RECOVERABLE_ERROR));
        $this->assertSame(Level::WARNING, ErrorHandler::levelForSeverity(E_WARNING));
        $this->assertSame(Level::NOTICE, ErrorHandler::levelForSeverity(E_NOTICE));
        $this->assertSame(Level::DEBUG, ErrorHandler::levelForSeverity(E_DEPRECATED));
    }

    public function test_shutdown_ignores_non_fatal_last_errors(): void
    {
        $transport = new FakeTransport();
        $this->handler = new ErrorHandler($this->client($transport));
        @trigger_error('not fatal', E_USER_NOTICE);
        $this->handler->handleShutdown();
        $this->assertSame(0, $transport->count());
    }

    public function test_register_installs_only_what_config_enables(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['capture_exceptions' => true, 'capture_errors' => false]);
        $before = set_error_handler(null);
        restore_error_handler();

        $this->handler = ErrorHandler::register($client);
        $after = set_error_handler(null);
        restore_error_handler();

        $this->assertSame($before, $after, 'the error handler is untouched when capture_errors is off');
        $current = set_exception_handler(null);
        restore_exception_handler();
        $this->assertSame([$this->handler, 'handleException'], $current);
    }
}
