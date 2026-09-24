<?php

namespace InnLogger\CodeIgniter3;

/**
 * Holds a secret string outside the object's own properties (in a private static vault keyed by
 * object id), so var_dump(), print_r(), var_export(), debug_zval_dump(), json_encode() and
 * serialize() of this object, or of anything that contains it, never reveal the value.
 */
final class Secret
{
    /** @var array<int, string> */
    private static $vault = [];

    /** @var int vault slot of this object (not secret) */
    private $slot;

    public function __construct(
        #[\SensitiveParameter]
        string $value
    ) {
        $this->slot = spl_object_id($this);
        self::$vault[$this->slot] = $value;
    }

    public function reveal(): string
    {
        return isset(self::$vault[$this->slot]) ? self::$vault[$this->slot] : '';
    }

    public function isEmpty(): bool
    {
        return $this->reveal() === '';
    }

    public function __destruct()
    {
        unset(self::$vault[$this->slot]);
    }

    public function __clone()
    {
        $value = isset(self::$vault[$this->slot]) ? self::$vault[$this->slot] : '';
        $this->slot = spl_object_id($this);
        self::$vault[$this->slot] = $value;
    }

    /** @return array<string, string> */
    public function __debugInfo()
    {
        return ['value' => $this->isEmpty() ? '(not set)' : '[REDACTED]'];
    }

    /** @return array<string, string> never the value */
    public function __serialize(): array
    {
        return [];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->slot = spl_object_id($this);
        self::$vault[$this->slot] = '';
    }

    public function __toString()
    {
        return $this->isEmpty() ? '' : '[REDACTED]';
    }
}
