<?php

namespace InnLogger\CodeIgniter3;

use RuntimeException;

/**
 * Thrown only when fail_silent is false. With the default (fail_silent = true) the SDK never
 * throws into the host application.
 */
class InnLoggerException extends RuntimeException
{
    /** @var int|null */
    private $status;

    public function __construct(string $message, ?int $status = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
    }

    /** HTTP status of the rejected request, or null for a transport-level failure. */
    public function status(): ?int
    {
        return $this->status;
    }
}
