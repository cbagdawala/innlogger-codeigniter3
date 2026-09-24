<?php

namespace InnLogger\CodeIgniter3\Transport;

interface TransportInterface
{
    /**
     * POST $body to $url. Must return the HTTP response (any status) or throw TransportException
     * when no response was received (DNS, connect, TLS, timeout).
     *
     * @param array<string, string> $headers header name => value
     * @param array{timeout: float, connect_timeout: float, verify_ssl: bool} $options
     * @throws TransportException
     */
    public function post(string $url, array $headers, string $body, array $options): Response;
}
