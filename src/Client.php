<?php

namespace InnLogger\CodeIgniter3;

use InnLogger\CodeIgniter3\Transport\CurlTransport;
use InnLogger\CodeIgniter3\Transport\Response;
use InnLogger\CodeIgniter3\Transport\StreamTransport;
use InnLogger\CodeIgniter3\Transport\TransportException;
use InnLogger\CodeIgniter3\Transport\TransportInterface;
use Throwable;

/**
 * Framework-agnostic InnLogger client: builds the event, applies the threshold and redaction,
 * signs the exact JSON bytes and posts them. With fail_silent (default) nothing it does can
 * throw into the host application.
 */
class Client
{
    const SDK_NAME = 'innlogger-codeigniter3';
    const SDK_VERSION = '1.0.0';

    const MAX_BODY_BYTES = 262144;
    const MAX_SECTION_BYTES = 65536;
    const MAX_MESSAGE_BYTES = 65536;

    const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    /** Context keys lifted into top-level event fields instead of `context`. */
    const RESERVED_CONTEXT_KEYS = ['exception', 'category', 'user_id', 'request_id', 'http_status', 'url', 'http_method', 'metadata'];

    /** @var Config */
    private $config;

    /** @var TransportInterface */
    private $transport;

    /** @var Redactor */
    private $redactor;

    /** @var callable|null fn(): array  - request context (url, http_method, user_id, request_id, controller, method) */
    private $contextProvider;

    /** @var callable|null fn(string $message): void  - local, secret-free failure reporting */
    private $reporter;

    /** @var string|null */
    private $lastError;

    /** @var array<string, bool> */
    private $reportedOnce = [];

    /** @var string|null */
    private static $processRequestId;

    /** Recursion guard shared by every instance (e.g. a log_message() hook calling back into us). */
    private static $sending = false;

    /** After a 429: no sends from this process until this unix time (shared by every instance). */
    private static $pausedUntil = 0;

    /** @var callable fn(): int current unix time */
    private $clock;

    /**
     * @param Config|array<string, mixed> $config
     * @param callable|null $reporter fn(string $message): void, defaults to error_log()
     */
    public function __construct(
        #[\SensitiveParameter]
        $config,
        ?TransportInterface $transport = null,
        ?callable $reporter = null
    ) {
        $this->config = $config instanceof Config ? $config : Config::fromArray(is_array($config) ? $config : []);
        $this->transport = $transport ?: ($this->config->get('transport') ?: self::defaultTransport());
        $this->reporter = $reporter;
        $this->init();
    }

    private function init(): void
    {
        $this->redactor = new Redactor(
            $this->config->get('redact_fields'),
            $this->config->get('mask_patterns'),
            [$this->config->get('api_secret')]
        );
        $this->clock = static function () {
            return time();
        };
    }

    /** Masked view for var_dump()/print_r(): never the API secret. */
    public function __debugInfo()
    {
        return [
            'config' => $this->config->masked(),
            'transport' => get_class($this->transport),
            'last_error' => $this->lastError,
            'paused_until' => self::$pausedUntil,
        ];
    }

    /**
     * Only the (secret-free) config is serialized; an unserialized client has no API secret and
     * therefore does not send.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return ['config' => $this->config];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->config = isset($data['config']) && $data['config'] instanceof Config ? $data['config'] : Config::fromArray([]);
        $this->transport = self::defaultTransport();
        $this->init();
    }

    /** Inject a clock (tests). */
    public function setClock(callable $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    /** Clear the process-wide 429 cooldown (tests, long-running workers). */
    public static function resetRateLimit(): void
    {
        self::$pausedUntil = 0;
    }

    public static function pausedUntil(): int
    {
        return self::$pausedUntil;
    }

    public static function defaultTransport(): TransportInterface
    {
        return CurlTransport::isSupported() ? new CurlTransport() : new StreamTransport();
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function setContextProvider(?callable $provider): self
    {
        $this->contextProvider = $provider;

        return $this;
    }

    public function setReporter(?callable $reporter): self
    {
        $this->reporter = $reporter;

        return $this;
    }

    /** Last failure description (never contains the API secret), or null. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public static function isSending(): bool
    {
        return self::$sending;
    }

    // ---------------------------------------------------------------- severity API

    /** @param mixed $message */
    public function critical($message, array $context = []): ?string
    {
        return $this->log(Level::CRITICAL, $message, $context);
    }

    /** @param mixed $message */
    public function error($message, array $context = []): ?string
    {
        return $this->log(Level::ERROR, $message, $context);
    }

    /** @param mixed $message */
    public function warning($message, array $context = []): ?string
    {
        return $this->log(Level::WARNING, $message, $context);
    }

    /** @param mixed $message */
    public function notice($message, array $context = []): ?string
    {
        return $this->log(Level::NOTICE, $message, $context);
    }

    /** @param mixed $message */
    public function info($message, array $context = []): ?string
    {
        return $this->log(Level::INFO, $message, $context);
    }

    /** @param mixed $message */
    public function debug($message, array $context = []): ?string
    {
        return $this->log(Level::DEBUG, $message, $context);
    }

    /** @param mixed $message */
    public function trace($message, array $context = []): ?string
    {
        return $this->log(Level::TRACE, $message, $context);
    }

    /**
     * Report a Throwable. Default level ERROR; the uncaught-exception hook uses CRITICAL.
     *
     * @param int|string $level
     */
    public function exception(Throwable $exception, array $context = [], $level = Level::ERROR): ?string
    {
        $context['exception'] = $exception;
        $message = $exception->getMessage() !== '' ? $exception->getMessage() : get_class($exception);

        return $this->log($level, $message, $context);
    }

    public function shouldSend(int $level): bool
    {
        return $this->config->enabled() && Level::passes($level, $this->config->threshold());
    }

    /**
     * Send one event. Returns the event_id when the portal accepted it (202, or 200 for a
     * duplicate), null when it was filtered out or delivery failed.
     *
     * @param int|string $level
     * @param mixed $message
     */
    public function log($level, $message, array $context = []): ?string
    {
        if (self::$sending) {
            return null;
        }
        self::$sending = true;
        try {
            $level = Level::from($level);
            if ($level === null || !$this->shouldSend($level) || !$this->deliverable()) {
                return null;
            }
            $event = $this->buildEvent($level, $message, $context);
            $body = $this->encode($event);
            if ($body === null) {
                return null;
            }

            return $this->send('logs', $body) ? $event['event_id'] : null;
        } catch (InnLoggerException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->fail('unexpected SDK error (' . get_class($e) . ')', null, $e);
        } finally {
            self::$sending = false;
        }
    }

    /**
     * POST /api/v1/heartbeat. Honors `enabled` but not the log threshold.
     */
    public function heartbeat(?string $applicationVersion = null): bool
    {
        if (self::$sending || !$this->config->enabled()) {
            return false;
        }
        self::$sending = true;
        try {
            if (!$this->deliverable()) {
                return false;
            }
            $payload = self::withoutNulls([
                'environment' => $this->config->get('environment'),
                'hostname' => $this->hostname(),
                'application_version' => $applicationVersion !== null ? $applicationVersion : $this->config->get('application_version'),
            ]);
            $body = $this->encode($payload);

            return $body !== null && $this->send('heartbeat', $body);
        } catch (InnLoggerException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->fail('unexpected SDK error (' . get_class($e) . ')', null, $e);

            return false;
        } finally {
            self::$sending = false;
        }
    }

    /**
     * Diagnostics for a "send a test event" command: never throws, ignores the threshold.
     *
     * @return array{configured: bool, https: bool, enabled: bool, status: int|null, accepted: bool, event_id: string|null, error: string|null}
     */
    public function test(string $message = 'InnLogger CodeIgniter 3 SDK test event'): array
    {
        $result = [
            'configured' => $this->config->hasCredentials(),
            'https' => $this->config->urlAllowed(),
            'enabled' => $this->config->enabled(),
            'status' => null,
            'accepted' => false,
            'event_id' => null,
            'error' => null,
        ];
        if (!$result['configured'] || !$result['https']) {
            $result['error'] = !$result['configured'] ? 'url, api_key and api_secret are required' : 'url must use https (or set allow_insecure for local development)';

            return $result;
        }
        try {
            $event = $this->buildEvent(Level::INFO, $message, ['category' => 'innlogger-test']);
            $body = (string) $this->encode($event);
            $response = $this->transport->post($this->config->endpoint('logs'), $this->headers($body), $body, $this->transportOptions());
            $result['status'] = $response->status();
            $result['accepted'] = $response->successful();
            $result['event_id'] = $event['event_id'];
            if (!$response->successful()) {
                $result['error'] = $this->describe($response);
            }
        } catch (Throwable $e) {
            $result['error'] = 'transport failure: ' . $this->scrub($e->getMessage());
        }

        return $result;
    }

    // ---------------------------------------------------------------- event building

    /**
     * Build the wire-contract event (spec 03 §2). Public so integrations and tests can inspect it.
     *
     * @param mixed $message
     * @return array<string, mixed>
     */
    public function buildEvent(int $level, $message, array $context = []): array
    {
        $exception = null;
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $exception = $context['exception'];
        }
        $metadata = isset($context['metadata']) && is_array($context['metadata']) ? $context['metadata'] : [];
        $reserved = array_intersect_key($context, array_flip(self::RESERVED_CONTEXT_KEYS));
        foreach (self::RESERVED_CONTEXT_KEYS as $key) {
            unset($context[$key]);
        }

        $auto = $this->autoContext();

        $event = [
            'event_id' => Uuid::v4(),
            'level' => $level,
            'level_name' => Level::name($level),
            'message' => Normalizer::truncate($this->redactor->maskString($this->stringify($message)), self::MAX_MESSAGE_BYTES),
            'category' => $this->category($reserved, $exception !== null),
            'environment' => $this->config->get('environment'),
            'application' => $this->config->get('application'),
            'hostname' => $this->hostname(),
            'request_id' => self::scalarOrNull(self::pick($reserved, $auto, 'request_id')),
            'user_id' => self::scalarOrNull(self::pick($reserved, $auto, 'user_id')),
            'url' => self::masked($this->redactor, self::scalarOrNull(self::pick($reserved, $auto, 'url'))),
            'http_method' => self::scalarOrNull(self::pick($reserved, $auto, 'http_method')),
            'http_status' => isset($reserved['http_status']) && is_numeric($reserved['http_status']) ? (int) $reserved['http_status'] : null,
            'file' => null,
            'line' => null,
            'exception' => null,
            'context' => null,
            'metadata' => null,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if (is_string($event['user_id']) && ctype_digit($event['user_id'])) {
            $event['user_id'] = (int) $event['user_id'];
        }

        $meta = [
            'sdk' => self::SDK_NAME,
            'sdk_version' => self::SDK_VERSION,
            'php_version' => PHP_VERSION,
        ];
        if ($this->config->get('application_version') !== null) {
            $meta['application_version'] = $this->config->get('application_version');
        }
        foreach (['controller', 'method', 'ci_version'] as $key) {
            if (isset($auto[$key]) && is_scalar($auto[$key]) && $auto[$key] !== '') {
                $meta[$key] = (string) $auto[$key];
            }
        }

        if ($exception !== null) {
            $event['exception'] = Normalizer::exception($exception);
            $event['exception']['message'] = $this->redactor->maskString($event['exception']['message']);
            $event['exception']['trace'] = $this->redactor->maskString($event['exception']['trace']);
            $event['file'] = $event['exception']['file'];
            $event['line'] = $event['exception']['line'];
            if ($exception->getCode() !== 0) {
                $meta['exception_code'] = $exception->getCode();
            }
            $previous = Normalizer::previousChain($exception);
            if ($previous) {
                $meta['previous_exceptions'] = $previous;
            }
        } else {
            $caller = $this->callerFrame();
            if ($caller !== null) {
                $event['file'] = $caller[0];
                $event['line'] = $caller[1];
            }
        }

        $meta = array_merge($meta, Normalizer::value($metadata));
        $event['metadata'] = $this->limitSection($this->redactor->redact(Normalizer::value($meta)));
        if ($context) {
            $event['context'] = $this->limitSection($this->redactor->redact(Normalizer::value($context)));
        }

        return self::withoutNulls($event);
    }

    /**
     * @return array<string, mixed>
     */
    private function autoContext(): array
    {
        if (!$this->config->get('capture_request_context') || $this->contextProvider === null) {
            return [];
        }
        try {
            $context = call_user_func($this->contextProvider);

            return is_array($context) ? $context : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function hostname(): ?string
    {
        $hostname = $this->config->get('hostname');
        if ($hostname === null) {
            $detected = function_exists('gethostname') ? gethostname() : false;
            $hostname = is_string($detected) && $detected !== '' ? $detected : null;
        }

        return $hostname;
    }

    /**
     * First stack frame outside the SDK and the CI3 glue files.
     *
     * @return array{0: string, 1: int}|null
     */
    private function callerFrame(): ?array
    {
        $srcDir = __DIR__ . DIRECTORY_SEPARATOR;
        $skipSuffixes = ['libraries/Innlogger.php', 'core/MY_Log.php', 'core/Common.php', 'hooks/innlogger.php'];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            if (!isset($frame['file'], $frame['line'])) {
                continue;
            }
            $file = (string) $frame['file'];
            if (strpos($file, $srcDir) === 0) {
                continue;
            }
            $normalized = str_replace('\\', '/', $file);
            foreach ($skipSuffixes as $suffix) {
                if (substr($normalized, -strlen($suffix)) === $suffix) {
                    continue 2;
                }
            }

            return [$file, (int) $frame['line']];
        }

        return null;
    }

    /**
     * Replace an oversized context/metadata section with a marker so the event still arrives.
     *
     * @param mixed $section
     * @return mixed
     */
    private function limitSection($section)
    {
        $json = json_encode($section, self::JSON_FLAGS);
        $bytes = is_string($json) ? strlen($json) : 0;
        if ($bytes > self::MAX_SECTION_BYTES) {
            return ['_truncated' => true, '_original_bytes' => $bytes];
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): ?string
    {
        $body = json_encode($payload, self::JSON_FLAGS);
        if (!is_string($body)) {
            $this->fail('could not encode the event as JSON');

            return null;
        }
        if (strlen($body) > self::MAX_BODY_BYTES && isset($payload['context'])) {
            $payload['context'] = ['_truncated' => true, '_reason' => 'body over 256 KB'];
            if (isset($payload['exception']['trace'])) {
                $payload['exception']['trace'] = Normalizer::truncate($payload['exception']['trace'], 16384);
            }
            $body = json_encode($payload, self::JSON_FLAGS);
        }
        if (!is_string($body) || strlen($body) > self::MAX_BODY_BYTES) {
            $this->fail('event exceeds the 256 KB body limit and was dropped');

            return null;
        }

        return $body;
    }

    // ---------------------------------------------------------------- transport

    private function deliverable(): bool
    {
        $now = (int) call_user_func($this->clock);
        if (self::$pausedUntil > $now) {
            // Rate limited (429): drop quietly; the 429 itself was reported once.
            $this->lastError = 'rate limited by InnLogger; sending paused for ' . (self::$pausedUntil - $now) . ' s';

            return false;
        }
        if (!$this->config->hasCredentials()) {
            $this->failOnce('config', 'url, api_key and api_secret must be configured; event not sent');

            return false;
        }
        if (!$this->config->urlAllowed()) {
            $this->failOnce('https', 'url must use https (set allow_insecure only for local development); event not sent');

            return false;
        }

        return true;
    }

    /**
     * Post a body with bounded retries. The same body (and so the same event_id) and the same
     * X-InnLogger-Request-Id are re-sent; each attempt gets a fresh timestamp, nonce and signature.
     * Retried: transport failures and 502/503/504. A 429 is never retried: it pauses sending for
     * this process for retry_after seconds (body) or the Retry-After header, clamped to 1-3600 s.
     */
    private function send(string $path, string $body): bool
    {
        $url = $this->config->endpoint($path);
        $requestId = Uuid::v4();
        $attempts = 1 + (int) $this->config->get('retries');
        $failure = null;
        $status = null;
        $cause = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->transport->post($url, $this->headers($body, $requestId), $body, $this->transportOptions());
                if ($response->successful()) {
                    $this->lastError = null;

                    return true;
                }
                $status = $response->status();
                $failure = $this->describe($response);
                $cause = null;
                if ($status === 429) {
                    $seconds = $this->pauseFor($response);
                    $this->lastError = 'POST /api/v1/' . $path . ' rate limited (HTTP 429); sending paused for ' . $seconds . ' s';
                    // Reported, but never thrown: back-pressure is not an application error.
                    $this->report($this->lastError);

                    return false;
                }
                if (!$response->retryable()) {
                    break;
                }
            } catch (Throwable $e) {
                $status = null;
                $cause = $e;
                $failure = 'transport failure: ' . ($e instanceof TransportException ? $this->scrub($e->getMessage()) : get_class($e));
            }
            if ($attempt < $attempts) {
                $delay = (int) $this->config->get('retry_delay_ms') * $attempt;
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }

        $this->fail('POST /api/v1/' . $path . ' failed: ' . $failure, $status, $cause);

        return false;
    }

    private function pauseFor(Response $response): int
    {
        $json = $response->json();
        $retryAfter = is_array($json) && isset($json['retry_after']) ? $json['retry_after'] : $response->header('retry-after');
        $seconds = is_numeric($retryAfter) ? (int) $retryAfter : 60;
        $seconds = max(1, min(3600, $seconds));
        self::$pausedUntil = (int) call_user_func($this->clock) + $seconds;

        return $seconds;
    }

    /**
     * @param string|null $requestId X-InnLogger-Request-Id; stable across retries of one send
     * @return array<string, string>
     */
    public function headers(string $body, ?string $requestId = null): array
    {
        $timestamp = (string) time();
        $nonce = Signer::nonce();

        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => self::SDK_NAME . '/' . self::SDK_VERSION . ' PHP/' . PHP_VERSION,
            'X-InnLogger-Key' => (string) $this->config->get('api_key'),
            'X-InnLogger-Timestamp' => $timestamp,
            'X-InnLogger-Nonce' => $nonce,
            'X-InnLogger-Signature' => Signer::sign($timestamp, $nonce, $body, (string) $this->config->get('api_secret')),
            'X-InnLogger-Request-Id' => $requestId !== null ? $requestId : Uuid::v4(),
        ];
    }

    /**
     * @return array{timeout: float, connect_timeout: float, verify_ssl: bool}
     */
    private function transportOptions(): array
    {
        return [
            'timeout' => (float) $this->config->get('timeout'),
            'connect_timeout' => (float) $this->config->get('connect_timeout'),
            'verify_ssl' => (bool) $this->config->get('verify_ssl'),
        ];
    }

    private function describe(Response $response): string
    {
        $json = $response->json();
        $message = is_array($json) && isset($json['message']) && is_string($json['message']) ? $json['message'] : '';

        return 'HTTP ' . $response->status() . ($message !== '' ? ' (' . substr($this->scrub($message), 0, 200) . ')' : '');
    }

    // ---------------------------------------------------------------- failure handling

    /**
     * Record + locally report a failure; throw only when fail_silent is off.
     *
     * @return null
     */
    private function fail(string $message, ?int $status = null, ?Throwable $previous = null)
    {
        $this->lastError = $message;
        $this->report($message);
        if (!$this->config->get('fail_silent')) {
            throw new InnLoggerException('InnLogger: ' . $message, $status, $previous);
        }

        return null;
    }

    private function failOnce(string $key, string $message): void
    {
        if (isset($this->reportedOnce[$key]) && $this->config->get('fail_silent')) {
            $this->lastError = $message;

            return;
        }
        $this->reportedOnce[$key] = true;
        $this->fail($message);
    }

    private function report(string $message): void
    {
        try {
            $line = 'InnLogger: ' . $this->scrub($message);
            if ($this->reporter !== null) {
                call_user_func($this->reporter, $line);
            } else {
                error_log($line);
            }
        } catch (Throwable $e) {
            // the local logger failed too; nothing more we can safely do
        }
    }

    /** Remove the API secret from any text that could reach a local log. */
    private function scrub(string $text): string
    {
        // Same masking as event values: the configured secret (>= 8 chars; shorter ones would
        // mangle ordinary words), ils_... secrets, Bearer/Basic/Digest credentials, mask_patterns.
        return $this->redactor->maskString($text);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $reserved
     */
    private function category(array $reserved, bool $isException): string
    {
        if (isset($reserved['category']) && is_scalar($reserved['category']) && trim((string) $reserved['category']) !== '') {
            return substr(trim((string) $reserved['category']), 0, 100);
        }

        return $isException ? 'exception' : (string) $this->config->get('category');
    }

    /**
     * @param int|string|null $value
     * @return int|string|null
     */
    private static function masked(Redactor $redactor, $value)
    {
        return is_string($value) ? $redactor->maskString($value) : $value;
    }

    /**
     * @param mixed $message
     */
    private function stringify($message): string
    {
        if (is_string($message)) {
            return $message;
        }
        if (is_scalar($message) || (is_object($message) && method_exists($message, '__toString'))) {
            return (string) $message;
        }
        $json = json_encode(Normalizer::value($message), self::JSON_FLAGS);

        return is_string($json) ? $json : '[' . gettype($message) . ']';
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $fallback
     * @return mixed
     */
    private static function pick(array $primary, array $fallback, string $key)
    {
        if (isset($primary[$key]) && $primary[$key] !== '') {
            return $primary[$key];
        }

        return isset($fallback[$key]) && $fallback[$key] !== '' ? $fallback[$key] : null;
    }

    /**
     * @param mixed $value
     * @return int|string|null
     */
    private static function scalarOrNull($value)
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, static function ($value) {
            return $value !== null;
        });
    }

    /** Stable per-process request id used when no X-Request-Id is available. */
    public static function processRequestId(): string
    {
        if (self::$processRequestId === null) {
            self::$processRequestId = 'req_' . bin2hex(random_bytes(8));
        }

        return self::$processRequestId;
    }
}
