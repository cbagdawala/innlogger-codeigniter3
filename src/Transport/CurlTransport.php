<?php

namespace InnLogger\CodeIgniter3\Transport;

/**
 * HTTP transport over ext-curl with millisecond connect/total timeouts and no redirects.
 */
final class CurlTransport implements TransportInterface
{
    public static function isSupported(): bool
    {
        return function_exists('curl_init') && function_exists('curl_exec');
    }

    public function post(string $url, array $headers, string $body, array $options): Response
    {
        if (!self::isSupported()) {
            throw new TransportException('ext-curl is not available');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $verify = !isset($options['verify_ssl']) || $options['verify_ssl'];

        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('curl_init failed');
        }

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) max(1, round($options['connect_timeout'] * 1000)),
            CURLOPT_TIMEOUT_MS => (int) max(1, round($options['timeout'] * 1000)),
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
        }
        $responseHeaders = [];
        $curlOptions[CURLOPT_HEADERFUNCTION] = static function ($handle, $line) use (&$responseHeaders) {
            if (stripos($line, 'HTTP/') === 0) {
                $responseHeaders = []; // a new response (e.g. after 100 Continue) starts over
            } else {
                $responseHeaders[] = $line;
            }

            return strlen($line);
        };
        curl_setopt_array($ch, $curlOptions);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($result === false || $errno !== 0 || $status === 0) {
            throw new TransportException('curl error ' . $errno . ': ' . $error);
        }

        return new Response($status, is_string($result) ? $result : '', Response::parseHeaderLines($responseHeaders));
    }
}
