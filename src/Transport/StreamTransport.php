<?php

namespace InnLogger\CodeIgniter3\Transport;

/**
 * Fallback transport over PHP's HTTP stream wrapper, used when ext-curl is missing.
 * Requires allow_url_fopen. Timeouts are second-granular for the connect phase.
 */
final class StreamTransport implements TransportInterface
{
    public static function isSupported(): bool
    {
        return (bool) filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)
            && in_array('https', stream_get_wrappers(), true);
    }

    public function post(string $url, array $headers, string $body, array $options): Response
    {
        if (!self::isSupported()) {
            throw new TransportException('allow_url_fopen is disabled or the https wrapper is missing');
        }

        $lines = ['Connection: close', 'Content-Length: ' . strlen($body)];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $verify = !isset($options['verify_ssl']) || $options['verify_ssl'];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => (float) $options['timeout'],
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
            ],
        ]);

        // The connect phase is governed by default_socket_timeout; shorten it for this call only.
        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) max(1, (int) ceil($options['connect_timeout'])));
        try {
            $result = @file_get_contents($url, false, $context);
            $responseHeaders = function_exists('http_get_last_response_headers')
                ? http_get_last_response_headers()
                : (isset($http_response_header) ? $http_response_header : null);
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }

        $status = 0;
        if (is_array($responseHeaders)) {
            foreach ($responseHeaders as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                    $status = (int) $m[1]; // the last status line wins
                }
            }
        }

        if ($result === false || $status === 0) {
            $last = error_get_last();
            throw new TransportException('stream request failed' . ($last ? ': ' . $last['message'] : ''));
        }

        return new Response($status, (string) $result, Response::parseHeaderLines(is_array($responseHeaders) ? $responseHeaders : []));
    }
}
