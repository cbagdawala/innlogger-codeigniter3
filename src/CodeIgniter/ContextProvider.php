<?php

namespace InnLogger\CodeIgniter3\CodeIgniter;

use InnLogger\CodeIgniter3\Client;
use InnLogger\CodeIgniter3\Config;
use InnLogger\CodeIgniter3\Redactor;
use Throwable;

/**
 * Collects request context from a CodeIgniter 3 application when available: controller, method,
 * URI, HTTP method, request id and user id. Every lookup is optional and guarded; before the
 * controller exists (pre_system hook, early errors) it falls back to $_SERVER.
 */
final class ContextProvider
{
    /** @var Config */
    private $config;

    /** @var Redactor */
    private $redactor;

    /** @var bool */
    private $cli;

    /**
     * @param bool|null $cli null = detect from PHP_SAPI
     */
    public function __construct(Config $config, Redactor $redactor, ?bool $cli = null)
    {
        $this->config = $config;
        $this->redactor = $redactor;
        $this->cli = $cli !== null ? $cli : (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
    }

    public static function attach(Client $client): void
    {
        $client->setContextProvider(new self($client->config(), $client->redactor()));
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $context = ['request_id' => $this->requestId()];
        if (defined('CI_VERSION')) {
            $context['ci_version'] = (string) constant('CI_VERSION');
        }

        $cli = $this->cli;
        if (!$cli) {
            if (isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])) {
                $context['http_method'] = strtoupper($_SERVER['REQUEST_METHOD']);
            }
            if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
                $context['url'] = $this->safeUri($_SERVER['REQUEST_URI']);
            }
        }

        $ci = $this->instance();
        if ($ci === null) {
            return $context;
        }

        try {
            if (isset($ci->router) && is_object($ci->router)) {
                if (isset($ci->router->class) && is_string($ci->router->class)) {
                    $context['controller'] = $ci->router->class;
                }
                if (isset($ci->router->method) && is_string($ci->router->method)) {
                    $context['method'] = $ci->router->method;
                }
            }
            if (!isset($context['url']) && isset($ci->uri) && is_object($ci->uri) && method_exists($ci->uri, 'uri_string')) {
                $uri = (string) $ci->uri->uri_string();
                $context['url'] = $cli ? 'cli:' . $uri : '/' . ltrim($uri, '/');
            }
        } catch (Throwable $e) {
            // partial context is fine
        }

        $userId = $this->userId($ci);
        if ($userId !== null) {
            $context['user_id'] = $userId;
        }

        return $context;
    }

    /**
     * @return object|null
     */
    private function instance()
    {
        if (!function_exists('get_instance')) {
            return null;
        }
        try {
            $ci = call_user_func('get_instance');

            return is_object($ci) ? $ci : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param object $ci
     * @return int|string|null
     */
    private function userId($ci)
    {
        try {
            $resolver = $this->config->get('user_id_resolver');
            if ($resolver !== null) {
                $id = call_user_func($resolver, $ci);
            } else {
                $key = $this->config->get('user_id_session_key');
                if ($key === null || !isset($ci->session) || !is_object($ci->session) || !method_exists($ci->session, 'userdata')) {
                    return null;
                }
                $id = $ci->session->userdata($key);
            }
        } catch (Throwable $e) {
            return null;
        }

        return (is_int($id) || (is_string($id) && $id !== '')) ? $id : null;
    }

    private function requestId(): string
    {
        foreach (['HTTP_X_REQUEST_ID', 'HTTP_X_CORRELATION_ID'] as $header) {
            if (isset($_SERVER[$header]) && is_string($_SERVER[$header])
                && preg_match('/^[A-Za-z0-9._:\-]{1,100}$/', $_SERVER[$header])) {
                return $_SERVER[$header];
            }
        }

        return Client::processRequestId();
    }

    /** Path plus query string with sensitive parameter values masked. */
    private function safeUri(string $uri): string
    {
        $parts = explode('?', $uri, 2);
        if (!isset($parts[1]) || $parts[1] === '') {
            return $parts[0];
        }
        parse_str($parts[1], $query);
        $query = $this->redactor->redact($query);

        return $parts[0] . '?' . http_build_query($query);
    }
}
