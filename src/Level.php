<?php

namespace InnLogger\CodeIgniter3;

/**
 * InnLogger severity scale (0 OFF ... 7 TRACE). Lower number = more severe.
 * A threshold T sends every event whose level is <= T; T = 0 sends nothing.
 */
final class Level
{
    const OFF = 0;
    const CRITICAL = 1;
    const ERROR = 2;
    const WARNING = 3;
    const NOTICE = 4;
    const INFO = 5;
    const DEBUG = 6;
    const TRACE = 7;

    /** @var array<int, string> */
    private static $names = [
        self::OFF => 'OFF',
        self::CRITICAL => 'CRITICAL',
        self::ERROR => 'ERROR',
        self::WARNING => 'WARNING',
        self::NOTICE => 'NOTICE',
        self::INFO => 'INFO',
        self::DEBUG => 'DEBUG',
        self::TRACE => 'TRACE',
    ];

    private function __construct()
    {
    }

    public static function name(int $level): string
    {
        return isset(self::$names[$level]) ? self::$names[$level] : 'UNKNOWN';
    }

    public static function isValid(int $level): bool
    {
        return $level >= self::CRITICAL && $level <= self::TRACE;
    }

    /**
     * Accepts an int (0-7), a numeric string, a level name ("error") or a PSR-3 / CI3 name
     * ("emergency", "alert", "warn", "all"). Returns null when the value cannot be mapped.
     *
     * @param mixed $value
     */
    public static function from($value): ?int
    {
        if (is_int($value)) {
            return ($value >= self::OFF && $value <= self::TRACE) ? $value : null;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if ($value !== '' && ctype_digit($value)) {
                return self::from((int) $value);
            }
            $map = [
                'off' => self::OFF, 'none' => self::OFF,
                'emergency' => self::CRITICAL, 'alert' => self::CRITICAL, 'critical' => self::CRITICAL, 'fatal' => self::CRITICAL,
                'error' => self::ERROR,
                'warning' => self::WARNING, 'warn' => self::WARNING,
                'notice' => self::NOTICE,
                'info' => self::INFO,
                'debug' => self::DEBUG,
                'trace' => self::TRACE, 'all' => self::TRACE,
            ];

            return isset($map[$value]) ? $map[$value] : null;
        }

        return null;
    }

    /**
     * True when an event of $level must be transmitted under $threshold.
     */
    public static function passes(int $level, int $threshold): bool
    {
        return $threshold > self::OFF && self::isValid($level) && $level <= $threshold;
    }
}
