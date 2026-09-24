<?php

namespace InnLogger\CodeIgniter3;

/**
 * Replaces the values of sensitive keys, recursively and case-insensitively (spec 09 §5).
 * Keys are compared after lower-casing and turning "-" into "_", so "Authorization",
 * "X-Api-Secret" style header names and "access-token" all match.
 *
 * Every string value is also masked (maskString): Bearer/Basic/Digest credentials, literal
 * InnLogger secrets (ils_...), the configured API secret and custom mask_patterns.
 */
final class Redactor
{
    const MASK = '[REDACTED]';

    /** Spec 09 §5 list plus a few common payment/credential aliases. */
    const DEFAULT_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'card_number',
        'cvv',
        'secret',
        'api_secret',
        // extras
        'passwd',
        'set_cookie',
        'php_auth_pw',
        'cvc',
        'card_cvv',
        'card_cvc',
        'private_key',
    ];

    /** Built-in value masks (regex => replacement), same as the Laravel SDK. */
    const DEFAULT_PATTERNS = [
        '/\b(Bearer|Basic|Digest)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 ' . self::MASK,
        '/\bils_[A-Za-z0-9]{8,}/' => self::MASK,
    ];

    /** @var array<string, bool> */
    private $keys = [];

    /** @var array<string, string> */
    private $patterns;

    /** @var Secret[] literal secrets (kept in a vault so dumps never show them) */
    private $literals = [];

    /**
     * @param string[] $extraKeys additional keys to redact
     * @param array<string, string> $maskPatterns extra regex => replacement rules
     * @param string[] $literalSecrets exact strings that must never be sent (min 8 chars)
     */
    public function __construct(
        array $extraKeys = [],
        array $maskPatterns = [],
        #[\SensitiveParameter]
        array $literalSecrets = []
    ) {
        $this->patterns = self::DEFAULT_PATTERNS + $maskPatterns;
        foreach ($literalSecrets as $literal) {
            if (is_string($literal) && strlen($literal) >= 8) {
                $this->literals[] = new Secret($literal);
            }
        }
        foreach (array_merge(self::DEFAULT_KEYS, $extraKeys) as $key) {
            if (is_string($key) && $key !== '') {
                $this->keys[self::normalizeKey($key)] = true;
            }
        }
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->keys);
    }

    public function isSensitive($key): bool
    {
        return is_string($key) && isset($this->keys[self::normalizeKey($key)]);
    }

    /**
     * Mask sensitive keys at any depth and apply maskString() to every string value.
     *
     * @param mixed $data
     * @return mixed
     */
    public function redact($data, int $depth = 0)
    {
        if (is_string($data)) {
            return $this->maskString($data);
        }
        if (!is_array($data) || $depth > 32) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if ($this->isSensitive($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value) || is_string($value)) {
                $data[$key] = $this->redact($value, $depth + 1);
            }
        }

        return $data;
    }

    /**
     * Mask credentials inside free text (messages, exception messages, stack traces, URLs).
     */
    public function maskString(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        foreach ($this->literals as $literal) {
            $secret = $literal->reveal();
            if ($secret !== '' && strpos($value, $secret) !== false) {
                $value = str_replace($secret, self::MASK, $value);
            }
        }
        foreach ($this->patterns as $pattern => $replacement) {
            $masked = @preg_replace($pattern, $replacement, $value);
            if (is_string($masked)) {
                $value = $masked;
            }
        }

        return $value;
    }

    private static function normalizeKey(string $key): string
    {
        return str_replace('-', '_', strtolower(trim($key)));
    }
}
