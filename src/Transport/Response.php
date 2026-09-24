<?php

namespace InnLogger\CodeIgniter3\Transport;

final class Response
{
    /** @var int */
    private $status;

    /** @var string */
    private $body;

    /** @var array<string, string> lower-cased header name => value */
    private $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, string $body = '', array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        foreach ($headers as $name => $value) {
            $this->headers[strtolower(trim((string) $name))] = trim((string) $value);
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);

        return isset($this->headers[$name]) ? $this->headers[$name] : null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Transient gateway/server failure worth retrying (429 is handled as a cooldown instead). */
    public function retryable(): bool
    {
        return in_array($this->status, [502, 503, 504], true);
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Parse raw "Name: value" header lines (status lines are skipped).
     *
     * @param string[] $lines
     * @return array<string, string>
     */
    public static function parseHeaderLines(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            $parts = explode(':', (string) $line, 2);
            if (count($parts) === 2 && strpos($parts[0], ' ') === false) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $headers;
    }
}
