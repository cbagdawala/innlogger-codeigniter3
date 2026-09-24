<?php

namespace InnLogger\CodeIgniter3;

use InnLogger\CodeIgniter3\Transport\TransportInterface;

/**
 * Normalized, immutable SDK configuration. Unknown or invalid values fall back to safe defaults;
 * building a Config never throws.
 */
final class Config
{
    /** @var array<string, mixed> */
    const DEFAULTS = [
        'enabled' => true,
        'url' => '',
        'api_key' => '',
        'api_secret' => '',
        'log_level' => Level::ERROR,
        'timeout' => 2.0,
        'connect_timeout' => 1.0,
        'environment' => 'production',
        'category' => 'application',
        'application' => null,
        'application_version' => null,
        'hostname' => null,
        'fail_silent' => true,
        'retries' => 1,
        'retry_delay_ms' => 100,
        'redact_fields' => [],
        'mask_patterns' => [],
        'allow_insecure' => false,
        'verify_ssl' => true,
        'capture_request_context' => true,
        'user_id_resolver' => null,
        'user_id_session_key' => null,
        'capture_exceptions' => true,
        'capture_errors' => false,
        'capture_fatal_errors' => true,
        'error_log_level' => Level::WARNING,
        'forward_log_message' => false,
        'transport' => null,
    ];

    const MAX_RETRIES = 3;

    /** @var array<string, mixed> */
    private $values;

    /**
     * @param array<string, mixed> $values
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(
        #[\SensitiveParameter]
        array $input
    ): self {
        $v = self::DEFAULTS;
        foreach ($input as $key => $value) {
            if (array_key_exists($key, $v)) {
                $v[$key] = $value;
            }
        }

        $v['enabled'] = self::bool($v['enabled'], true);
        $v['url'] = rtrim(trim((string) (is_scalar($v['url']) ? $v['url'] : '')), '/');
        $v['api_key'] = is_scalar($v['api_key']) ? trim((string) $v['api_key']) : '';
        if (!($v['api_secret'] instanceof Secret)) {
            $v['api_secret'] = new Secret(is_scalar($v['api_secret']) ? (string) $v['api_secret'] : '');
        }

        $level = Level::from($v['log_level']);
        $v['log_level'] = $level === null ? Level::ERROR : $level;
        $errorLevel = Level::from($v['error_log_level']);
        $v['error_log_level'] = ($errorLevel === null || $errorLevel === Level::OFF) ? Level::WARNING : $errorLevel;

        $v['timeout'] = self::seconds($v['timeout'], 2.0, 30.0);
        $v['connect_timeout'] = min(self::seconds($v['connect_timeout'], 1.0, 30.0), $v['timeout']);

        $v['environment'] = is_scalar($v['environment']) && (string) $v['environment'] !== ''
            ? (string) $v['environment'] : 'production';
        $v['category'] = is_scalar($v['category']) && trim((string) $v['category']) !== ''
            ? substr(trim((string) $v['category']), 0, 100) : 'application';
        foreach (['application', 'application_version', 'hostname', 'user_id_session_key'] as $key) {
            $v[$key] = is_scalar($v[$key]) && (string) $v[$key] !== '' ? (string) $v[$key] : null;
        }

        $v['fail_silent'] = self::bool($v['fail_silent'], true);
        $v['retries'] = max(0, min(self::MAX_RETRIES, is_numeric($v['retries']) ? (int) $v['retries'] : 1));
        $v['retry_delay_ms'] = max(0, min(1000, is_numeric($v['retry_delay_ms']) ? (int) $v['retry_delay_ms'] : 100));
        $v['redact_fields'] = is_array($v['redact_fields']) ? array_values(array_filter($v['redact_fields'], 'is_string')) : [];

        $v['mask_patterns'] = self::patterns($v['mask_patterns']);

        foreach (['allow_insecure', 'capture_errors', 'forward_log_message'] as $key) {
            $v[$key] = self::bool($v[$key], false);
        }
        foreach (['verify_ssl', 'capture_request_context', 'capture_exceptions', 'capture_fatal_errors'] as $key) {
            $v[$key] = self::bool($v[$key], true);
        }
        $v['user_id_resolver'] = is_callable($v['user_id_resolver']) ? $v['user_id_resolver'] : null;
        $v['transport'] = $v['transport'] instanceof TransportInterface ? $v['transport'] : null;

        return new self($v);
    }

    /**
     * @return mixed api_secret is returned as the plain string
     */
    public function get(string $key)
    {
        if (!array_key_exists($key, $this->values)) {
            return null;
        }
        $value = $this->values[$key];

        return $value instanceof Secret ? $value->reveal() : $value;
    }

    /**
     * Masked view for var_dump()/print_r(); var_export() and serialize() cannot reach the
     * secret either because it lives in a Secret vault, not in a property.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo()
    {
        return $this->masked();
    }

    /**
     * Serialized form carries no secret (and no closures/transport). An unserialized Config has
     * an empty api_secret and so cannot send.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $values = $this->values;
        $values['api_secret'] = '';
        $values['user_id_resolver'] = null;
        $values['transport'] = null;

        return $values;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $data['api_secret'] = '';
        $this->values = self::fromArray($data)->values;
    }

    /**
     * @return array<string, mixed>
     */
    public function masked(): array
    {
        $values = $this->values;
        $values['api_secret'] = $values['api_secret']->isEmpty() ? '(not set)' : '[REDACTED]';
        $values['user_id_resolver'] = $values['user_id_resolver'] === null ? null : '(callable)';
        $values['transport'] = $values['transport'] === null ? null : get_class($values['transport']);

        return $values;
    }

    public function enabled(): bool
    {
        return $this->values['enabled'];
    }

    public function threshold(): int
    {
        return $this->values['log_level'];
    }

    /** URL, key and secret are present. */
    public function hasCredentials(): bool
    {
        return $this->values['url'] !== '' && $this->values['api_key'] !== '' && !$this->values['api_secret']->isEmpty();
    }

    /** HTTPS, or plain HTTP only when allow_insecure is set (local development). */
    public function urlAllowed(): bool
    {
        $scheme = strtolower((string) parse_url($this->values['url'], PHP_URL_SCHEME));

        return $scheme === 'https' || ($scheme === 'http' && $this->values['allow_insecure']);
    }

    public function endpoint(string $path): string
    {
        return $this->values['url'] . '/api/v1/' . ltrim($path, '/');
    }

    /**
     * mask_patterns: ['/regex/' => 'replacement'] or a plain list of regexes (replaced with
     * [REDACTED]). Invalid regexes are dropped.
     *
     * @param mixed $patterns
     * @return array<string, string>
     */
    private static function patterns($patterns): array
    {
        if (!is_array($patterns)) {
            return [];
        }
        $out = [];
        foreach ($patterns as $key => $value) {
            if (is_string($key) && $key !== '') {
                $pattern = $key;
                $replacement = is_scalar($value) ? (string) $value : Redactor::MASK;
            } elseif (is_string($value) && $value !== '') {
                $pattern = $value;
                $replacement = Redactor::MASK;
            } else {
                continue;
            }
            if (@preg_match($pattern, '') !== false) {
                $out[$pattern] = $replacement;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function bool($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed === null ? $default : $parsed;
        }

        return $default;
    }

    /**
     * @param mixed $value
     */
    private static function seconds($value, float $default, float $max): float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            return $default;
        }

        return min((float) $value, $max);
    }
}
