<?php

namespace InnLogger\CodeIgniter3\Tests\Support;

use InnLogger\CodeIgniter3\Transport\Response;
use InnLogger\CodeIgniter3\Transport\TransportException;
use InnLogger\CodeIgniter3\Transport\TransportInterface;

/**
 * Records every request; replies from a queue of Response objects / Throwables (last one repeats).
 */
final class FakeTransport implements TransportInterface
{
    /** @var array<int, array{url: string, headers: array<string, string>, body: string, options: array}> */
    public $requests = [];

    /** @var array<int, Response|\Throwable> */
    private $replies;

    /**
     * @param array<int, Response|\Throwable> $replies
     */
    public function __construct(array $replies = [])
    {
        $this->replies = $replies ?: [new Response(202, '{"success":true,"message":"Log accepted","data":{"log_id":"x"}}')];
    }

    public static function failing(string $message = 'Operation timed out after 2000 milliseconds'): self
    {
        return new self([new TransportException($message)]);
    }

    public static function status(int $status, string $body = ''): self
    {
        return new self([new Response($status, $body)]);
    }

    public function post(string $url, array $headers, string $body, array $options): Response
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
        $index = min(count($this->requests) - 1, count($this->replies) - 1);
        $reply = $this->replies[$index];
        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return $reply;
    }

    public function count(): int
    {
        return count($this->requests);
    }

    /** @return array<string, mixed> */
    public function payload(int $index = 0): array
    {
        return json_decode($this->requests[$index]['body'], true);
    }

    /** @return array<string, string> */
    public function headers(int $index = 0): array
    {
        return $this->requests[$index]['headers'];
    }
}
