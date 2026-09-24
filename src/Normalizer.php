<?php

namespace InnLogger\CodeIgniter3;

use JsonSerializable;
use Throwable;

/**
 * Turns arbitrary context values and exceptions into JSON-safe arrays.
 */
final class Normalizer
{
    const MAX_DEPTH = 10;
    const MAX_ITEMS = 500;
    const MAX_TRACE_BYTES = 65536;
    const MAX_PREVIOUS = 5;

    private function __construct()
    {
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function value($value, int $depth = 0)
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (is_nan($value) || is_infinite($value)) ? (string) $value : $value;
        }
        if ($depth >= self::MAX_DEPTH) {
            return '[max depth]';
        }
        if (is_array($value)) {
            $out = [];
            $count = 0;
            foreach ($value as $k => $v) {
                if (++$count > self::MAX_ITEMS) {
                    $out['_truncated'] = (count($value) - self::MAX_ITEMS) . ' more items';
                    break;
                }
                $out[$k] = self::value($v, $depth + 1);
            }

            return $out;
        }
        if ($value instanceof Throwable) {
            return self::exception($value, false);
        }
        if ($value instanceof JsonSerializable) {
            try {
                return self::value($value->jsonSerialize(), $depth + 1);
            } catch (Throwable $e) {
                return '[object ' . get_class($value) . ']';
            }
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_object($value)) {
            try {
                if (method_exists($value, 'toArray')) {
                    $array = $value->toArray();
                    if (is_array($array)) {
                        return self::value($array, $depth + 1);
                    }
                }
                if (method_exists($value, '__toString')) {
                    return (string) $value;
                }
            } catch (Throwable $e) {
                // fall through to the class name
            }

            return '[object ' . get_class($value) . ']';
        }
        if (is_resource($value)) {
            return '[resource ' . get_resource_type($value) . ']';
        }

        return '[' . gettype($value) . ']';
    }

    /**
     * Exception in the wire-contract shape: class, message, file, line, trace.
     *
     * @return array{class: string, message: string, file: string, line: int, trace: string}
     */
    public static function exception(Throwable $e, bool $withTrace = true): array
    {
        $out = [
            'class' => get_class($e),
            'message' => (string) $e->getMessage(),
            'file' => (string) $e->getFile(),
            'line' => (int) $e->getLine(),
        ];
        if ($withTrace) {
            $out['trace'] = self::truncate($e->getTraceAsString(), self::MAX_TRACE_BYTES);
        }

        return $out;
    }

    /**
     * Previous-exception chain (class/message/file/line), outermost first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function previousChain(Throwable $e): array
    {
        $chain = [];
        $previous = $e->getPrevious();
        while ($previous !== null && count($chain) < self::MAX_PREVIOUS) {
            $chain[] = self::exception($previous, false);
            $previous = $previous->getPrevious();
        }

        return $chain;
    }

    public static function truncate(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        $marker = "\n...[truncated]";

        return substr($value, 0, max(0, $maxBytes - strlen($marker))) . $marker;
    }
}
