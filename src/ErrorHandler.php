<?php

namespace InnLogger\CodeIgniter3;

use ErrorException;
use Throwable;

/**
 * Optional PHP error/exception capture that chains to the handlers already installed (CI3's
 * _exception_handler / _error_handler) so the application's normal error handling is unchanged.
 * InnLogger reports first, then the previous handler always runs.
 */
final class ErrorHandler
{
    const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /** @var Client */
    private $client;

    /** @var int errors less severe than this InnLogger level are not reported */
    private $errorLevel;

    /** @var callable|null */
    private $previousExceptionHandler;

    /** @var callable|null */
    private $previousErrorHandler;

    /** @var bool */
    private $exceptionsInstalled = false;

    /** @var bool */
    private $errorsInstalled = false;

    /** @var bool */
    private $fatalInstalled = false;

    /** @var array<string, bool> */
    private $reported = [];

    public function __construct(Client $client, int $errorLevel = Level::WARNING)
    {
        $this->client = $client;
        $this->errorLevel = Level::isValid($errorLevel) ? $errorLevel : Level::WARNING;
    }

    /**
     * Install the handlers enabled in the client's config (capture_exceptions, capture_errors,
     * capture_fatal_errors). Never throws.
     */
    public static function register(Client $client): self
    {
        $config = $client->config();
        $handler = new self($client, (int) $config->get('error_log_level'));
        try {
            if ($config->get('capture_exceptions')) {
                $handler->installExceptionHandler();
            }
            if ($config->get('capture_errors')) {
                $handler->installErrorHandler();
            }
            if ($config->get('capture_fatal_errors')) {
                $handler->installFatalHandler();
            }
        } catch (Throwable $e) {
            // never interfere with the application's boot
        }

        return $handler;
    }

    public function installExceptionHandler(): void
    {
        if ($this->exceptionsInstalled) {
            return;
        }
        $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
        $this->exceptionsInstalled = true;
    }

    public function installErrorHandler(): void
    {
        if ($this->errorsInstalled) {
            return;
        }
        $this->previousErrorHandler = set_error_handler([$this, 'handleError']);
        $this->errorsInstalled = true;
    }

    public function installFatalHandler(): void
    {
        if ($this->fatalInstalled) {
            return;
        }
        register_shutdown_function([$this, 'handleShutdown']);
        $this->fatalInstalled = true;
    }

    /** Restore the handlers this instance replaced (mainly for tests). */
    public function uninstall(): void
    {
        if ($this->exceptionsInstalled) {
            restore_exception_handler();
            $this->exceptionsInstalled = false;
        }
        if ($this->errorsInstalled) {
            restore_error_handler();
            $this->errorsInstalled = false;
        }
    }

    public function handleException(Throwable $exception): void
    {
        $this->reportException($exception, Level::CRITICAL);

        if ($this->previousExceptionHandler !== null) {
            call_user_func($this->previousExceptionHandler, $exception);

            return;
        }
        // No previous handler: behave like PHP's default (fatal "Uncaught ...").
        restore_exception_handler();
        throw $exception;
    }

    /**
     * @return mixed the previous handler's result, or false to continue with PHP's handler
     */
    public function handleError(int $severity, string $message, string $file = '', int $line = 0)
    {
        if ((error_reporting() & $severity) && !Client::isSending()) {
            $level = self::levelForSeverity($severity);
            if ($level <= $this->errorLevel) {
                $this->reportException(new ErrorException($message, 0, $severity, $file, $line), $level);
            }
        }

        if ($this->previousErrorHandler !== null) {
            return call_user_func($this->previousErrorHandler, $severity, $message, $file, $line);
        }

        return false;
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if (!is_array($error) || !in_array($error['type'], self::FATAL_TYPES, true)) {
            return;
        }
        $this->reportException(
            new ErrorException((string) $error['message'], 0, (int) $error['type'], (string) $error['file'], (int) $error['line']),
            Level::CRITICAL
        );
    }

    public static function levelForSeverity(int $severity): int
    {
        switch ($severity) {
            case E_ERROR:
            case E_PARSE:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
                return Level::CRITICAL;
            case E_USER_ERROR:
            case E_RECOVERABLE_ERROR:
                return Level::ERROR;
            case E_WARNING:
            case E_USER_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
                return Level::WARNING;
            case E_NOTICE:
            case E_USER_NOTICE:
            case 2048: // E_STRICT (constant deprecated in PHP 8.4)
                return Level::NOTICE;
            default: // E_DEPRECATED, E_USER_DEPRECATED
                return Level::DEBUG;
        }
    }

    private function reportException(Throwable $exception, int $level): void
    {
        $key = get_class($exception) . '|' . $exception->getFile() . '|' . $exception->getLine() . '|' . $exception->getMessage();
        if (isset($this->reported[$key])) {
            return;
        }
        $this->reported[$key] = true;
        try {
            $this->client->exception($exception, [], $level);
        } catch (Throwable $e) {
            // fail_silent = false must still never break the host's own error handling
        }
    }
}
