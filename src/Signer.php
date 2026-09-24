<?php

namespace InnLogger\CodeIgniter3;

/**
 * HMAC-SHA256 request signer (spec 03 §1, 09 §2).
 *
 * signature = lowercase hex HMAC-SHA256(timestamp . "\n" . nonce . "\n" . rawBody, apiSecret)
 */
final class Signer
{
    private function __construct()
    {
    }

    public static function sign(
        string $timestamp,
        string $nonce,
        string $rawBody,
        #[\SensitiveParameter]
        string $secret
    ): string {
        return hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $rawBody, $secret);
    }

    /** 32 lowercase hex characters from a CSPRNG. */
    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
