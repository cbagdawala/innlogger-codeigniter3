# InnLogger SDK for CodeIgniter 3

Sends CodeIgniter 3 application logs and exceptions to an InnLogger portal over HTTPS. Each
request is signed with HMAC-SHA256. The SDK fails silently: if InnLogger is slow, down or
misconfigured, your application carries on as normal.

- PHP **7.4 or newer**, including PHP 8.x. CodeIgniter 3.1.x.
- Uses `ext-curl` (millisecond timeouts). Without curl it falls back to PHP streams, which need `allow_url_fopen`.
- Package: `cbagdawala/innlogger-codeigniter3` (namespace `InnLogger\CodeIgniter3\`).

## Contents

| Path | Purpose |
|---|---|
| `application/libraries/Innlogger.php` | The CI3 library (`$this->load->library('innlogger')`). |
| `application/config/innlogger.php` | The configuration file. |
| `application/hooks/innlogger.php` | Optional `pre_system` hook that captures uncaught exceptions, PHP errors and fatal errors. |
| `application/core/MY_Log.php` | Optional: forwards `log_message()` calls to InnLogger. |
| `application/controllers/Innlogger_cli.php` | Optional CLI commands: `test` and `heartbeat`. |
| `src/` | Framework-agnostic core: client, signer, redactor, normalizer, transports. |

## Installation

### A. Composer (recommended)

The package is published to the private repository `github.com/cbagdawala/innlogger-codeigniter3`.
The server that runs Composer needs read access to it. The simplest way is a GitHub token with
**Contents: read-only** on the SDK repositories, set once per server
(`composer config --global --auth github-oauth.github.com <read-only-token>`). Add the repository
to your application's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/cbagdawala/innlogger-codeigniter3" }
]
```

```bash
composer require cbagdawala/innlogger-codeigniter3:^1.0
```

With an SSH key instead of a token, use
`{ "type": "vcs", "url": "git@github.com:cbagdawala/innlogger-codeigniter3.git", "no-api": true }`.

1. Enable Composer in `application/config/config.php`: `$config['composer_autoload'] = TRUE;` (or the path to `vendor/autoload.php`).
2. Copy `vendor/cbagdawala/innlogger-codeigniter3/application/libraries/Innlogger.php` and
   `.../application/config/innlogger.php` into your application's `application/` folder.
   The hook, `MY_Log` and CLI controller are optional; copy them only if you want them.

### B. Copy the files (no Composer)

1. Copy `application/libraries/Innlogger.php` and `application/config/innlogger.php` into your app.
2. Copy `src/` to `application/third_party/innlogger/src/`. The library registers its own
   autoloader for that folder. You can use another location by setting `'src_path'` in the config.

> Keep the file name **`Innlogger.php`** (capital I, lowercase l). CI3 turns
> `load->library('innlogger')` into `libraries/Innlogger.php`, and Linux file names are case-sensitive.

## Configuration

`application/config/innlogger.php`:

```php
$config['innlogger'] = [
    'enabled'     => true,
    'url'         => getenv('INNLOGGER_URL') ?: 'https://logger.example.com',
    'api_key'     => getenv('INNLOGGER_API_KEY') ?: '',     // ilv_...
    'api_secret'  => getenv('INNLOGGER_API_SECRET') ?: '',  // ils_...  keep it out of Git
    'log_level'   => 2,        // threshold, see below
    'timeout'     => 2,        // total seconds per request
    'environment' => 'production',
];
```

You can put overrides for one environment in `application/config/<ENVIRONMENT>/innlogger.php`.
They are merged over the base file. If `environment` is empty, the SDK uses CI's `ENVIRONMENT`.

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Master switch. Accepts env-style strings (`"false"`, `"0"`). |
| `url` | — | Portal base URL. Events go to `{url}/api/v1/logs`, heartbeats to `{url}/api/v1/heartbeat`. |
| `api_key`, `api_secret` | — | Project credentials. Nothing is sent until all three (`url`, key, secret) are set. |
| `log_level` | `2` | Threshold, 0–7 or a name (`'warning'`). |
| `timeout` / `connect_timeout` | `2` / `1` | Seconds, fractions allowed, max 30. The connect timeout is capped at `timeout`. |
| `environment`, `application`, `application_version`, `hostname` | | Added to every event. `hostname` defaults to `gethostname()`. |
| `fail_silent` | `true` | `false` makes delivery failures throw `InnLogger\CodeIgniter3\InnLoggerException`. Use it for debugging only. |
| `category` | `'application'` | Default event category. Exceptions use `exception` unless the context sets `category`. |
| `retries` / `retry_delay_ms` | `1` / `100` | Bounded retry (at most 3) on network errors and 502/503/504. A 429 pauses sending instead (see below). |
| `redact_fields` | `[]` | Keys to mask in addition to the built-in list. |
| `mask_patterns` | `[]` | Regex masks for string values (`'/re/' => 'replacement'`, or a list of regexes). |
| `allow_insecure` | `false` | Allow `http://` URLs. For local development only. |
| `verify_ssl` | `true` | TLS certificate verification. |
| `capture_request_context` | `true` | Add the controller, method, URI, HTTP method, request id and user id. |
| `user_id_session_key` | `null` | Read the user id with `$this->session->userdata(<key>)`. |
| `user_id_resolver` | `null` | `function ($CI) { return ...; }`. Takes precedence over the session key. |
| `capture_exceptions` / `capture_fatal_errors` / `capture_errors` | `true` / `true` / `false` | Used only by the hook (which handlers it installs). |
| `error_log_level` | `3` | The least severe PHP error level the hook reports. |
| `forward_log_message` | `false` | Used only by `MY_Log`. |

## Usage

```php
$this->load->library('innlogger');     // or $autoload['libraries'] = ['innlogger'];

$this->innlogger->critical('System failure', $context);
$this->innlogger->error('Payment failed', ['order_id' => 42]);
$this->innlogger->warning('Potential problem', $context);
$this->innlogger->notice('Important event', $context);
$this->innlogger->info('Customer created', $context);
$this->innlogger->debug('Debug information', $context);
$this->innlogger->trace('Trace information', $context);
$this->innlogger->exception($exception, $context);           // level ERROR
$this->innlogger->exception($exception, $context, 'critical');
$this->innlogger->log('warning', 'Generic call', $context);
$this->innlogger->heartbeat('1.4.2');
```

Each call returns the `event_id` (a UUID v4) when the portal accepted the event. It returns
`null` when the event was filtered out or could not be delivered.

**Reserved context keys.** These keys are moved out of `context` and into event fields:
`exception` (a Throwable, PSR-3 style), `category`, `user_id`, `request_id`, `http_status`, `url`,
`http_method` and `metadata` (an array merged into the event metadata). Explicit values take
precedence over the automatically captured context.

```php
$this->innlogger->error('Charge declined', [
    'category'    => 'payment',
    'http_status' => 502,
    'exception'   => $e,
    'gateway'     => 'stripe',   // stays in context
]);
```

Outside CodeIgniter, or in your own classes, use the core client directly:

```php
$client = new \InnLogger\CodeIgniter3\Client($settingsArray);
$client->error('Payment failed', $context);
```

`Innlogger::shared()` returns the client that the library, the hook and `MY_Log` share.

### Automatic exception and error capture (optional)

`application/config/config.php`: `$config['enable_hooks'] = TRUE;`

`application/config/hooks.php`:

```php
$hook['pre_system'][] = [
    'function' => 'innlogger_register_handlers',
    'filename' => 'innlogger.php',
    'filepath' => 'hooks',
];
```

The hook wraps the handlers CodeIgniter has already installed. InnLogger reports the problem
first, and then CI's `_exception_handler` / `_error_handler` runs exactly as before. The error
page, logging and exit code do not change. Uncaught exceptions and fatal errors are sent as
CRITICAL. PHP warnings and notices are sent only when `capture_errors` is `true`, and only
if they are at `error_log_level` or more severe. Errors silenced with `@` are never sent, and
an identical error is sent only once per request.

### Forwarding `log_message()` (optional)

Copy `application/core/MY_Log.php` if your application has no `MY_Log` of its own. If it
already has one, merge `innlogger_forward()` into it. Then set `'forward_log_message' => true`.
The mapping is `error` → ERROR, `info` → INFO and `debug` → DEBUG. The `log_level` threshold
still applies. InnLogger's own failure messages start with `InnLogger:` and are never forwarded
back.

### CLI: test and heartbeat (optional)

```bash
php index.php innlogger_cli test                # sends a test event, prints status / event_id
php index.php innlogger_cli heartbeat 1.4.2     # POST /api/v1/heartbeat
```

To keep the environment shown as online in the portal, run the heartbeat from cron:
`*/5 * * * * cd /path/to/app && php index.php innlogger_cli heartbeat`.
Web requests to this controller return a 404.

## Threshold

| Value | Level | | Value | Level |
|---:|---|---|---:|---|
| 0 | OFF (send nothing) | | 4 | NOTICE |
| 1 | CRITICAL | | 5 | INFO |
| 2 | ERROR | | 6 | DEBUG |
| 3 | WARNING | | 7 | TRACE |

An event is sent when `level <= log_level` and `log_level > 0`. For example, `2` sends CRITICAL
and ERROR, `5` sends CRITICAL through INFO, `7` sends everything, and `0` sends nothing
(including exceptions). The threshold only controls what is sent. The portal's notification
rules decide separately what generates an alert. Heartbeats ignore the threshold but respect
`enabled`.

## Wire format

- `POST {url}/api/v1/logs` with a JSON body: `event_id`, `level`, `level_name`, `message`, `category`,
  `environment`, `application`, `hostname`, `request_id`, `user_id`, `url`, `http_method`,
  `http_status`, `file`, `line`, `exception{class,message,file,line,trace}`, `context`, `metadata`,
  `occurred_at` (UTC ISO-8601). Null fields are left out.
- The headers are `X-InnLogger-Key`, `X-InnLogger-Timestamp` (unix seconds), `X-InnLogger-Nonce`
  (32 random hex characters, new for every attempt), `X-InnLogger-Request-Id` (a UUID per
  logical send, identical across its retries) and `X-InnLogger-Signature`.
- `category` is always sent. It is the context's `category` if given, otherwise `exception` for
  exceptions, otherwise the configured `category` (default `application`).
- Signature: `hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $rawBody, $apiSecret)`, as
  lowercase hex, computed over the exact bytes sent.
- A 202 response is accepted. A 200 response (duplicate `event_id`) is treated as delivered.

## Failure behaviour

- **Short timeouts.** The defaults are 1 s to connect and 2 s in total. Redirects are not followed.
- **Silent failures.** With `fail_silent` (the default), a network error, timeout, 4xx/5xx response,
  JSON encoding problem or SDK bug is caught (`Throwable`), and the method returns `null`.
  One line starting with `InnLogger:` goes to `log_message('error', ...)`, or to `error_log()`
  outside CI. That line never contains the API secret.
- **Retries.** The default is 1 retry, and the maximum is 3. A retry happens only after a
  network error or a 502, 503 or 504 response. It re-sends the same body, so the same
  `event_id` (the portal de-duplicates it), and the same `X-InnLogger-Request-Id`. The
  timestamp, nonce and signature are new for each attempt. Other 4xx and 5xx responses, such as
  401 invalid credentials, 422 validation errors or a 500, are never retried.
- **Rate limiting (429).** A 429 response is not retried. Instead, the SDK pauses all sending in
  this PHP process, for every client instance, including heartbeats. The pause lasts for
  `retry_after` seconds from the response body, or for the `Retry-After` header, clamped to
  1–3600 s; it is 60 s when the response gives neither. The 429 is reported once. Events
  dropped during the pause are not reported, and a 429 never throws, even with
  `fail_silent => false`. `Client::resetRateLimit()` clears the pause, for example in a
  long-running worker.
- **Configuration problems.** If the credentials are missing or the URL is not HTTPS, nothing is
  sent, and the problem is reported once per client (once per request with the CI library).
- **Recursion guard.** If InnLogger logs while it is already sending, for example through a
  `log_message` hook, the nested call is dropped.
- **Size limits.** Messages and stack traces are truncated to 64 KB. A `context` or `metadata`
  section over 64 KB is replaced with a `_truncated` marker, and the body is kept under 256 KB.
  Invalid UTF-8 is replaced instead of breaking the encoding.
- **Error handlers.** The hook always runs CodeIgniter's original handlers, even when InnLogger
  is down or `fail_silent` is `false`.

## Security notes

- **HTTPS only.** `http://` URLs are refused unless `allow_insecure` is `true`, and TLS
  certificates are verified.
- **The secret is never sent.** Requests carry only the HMAC signature, and each nonce is used
  once. Keep the secret out of Git and read it from the environment, as the shipped config does.
- **Redaction.** Keys are masked with `[REDACTED]` at any depth, in `context`, `metadata` and the
  captured URL query string. Matching ignores case, and `-` is treated like `_`. The built-in
  list is `password`, `password_confirmation`, `token`, `access_token`, `refresh_token`,
  `authorization`, `cookie`, `card_number`, `cvv`, `secret`, `api_secret`, plus `passwd`,
  `set_cookie`, `php_auth_pw`, `cvc`, `card_cvv`, `card_cvc` and `private_key`. Add your own
  keys with `redact_fields`.
- **Value masking.** Before the body is encoded and signed, the SDK also masks text in the
  message, the exception message, the stack trace, previous exceptions, the URL and every
  string in `context` and `metadata`. It masks:
  - `Bearer`, `Basic` and `Digest` credentials,
  - any literal InnLogger secret (`ils_…`),
  - the configured `api_secret`, if it is 8 characters or longer,
  - your own `mask_patterns`, given as `'/regex/' => 'replacement'` or as a plain list of regexes
    (replaced with `[REDACTED]`). Invalid regexes are ignored.
- **Stack trace arguments.** `getTraceAsString()` includes scalar function arguments
  (strings are shortened to 15 characters) unless `zend.exception_ignore_args = On`. That
  setting is the default in `php.ini-production`, but not in a bare PHP or Docker image. Scalar
  arguments such as passwords passed to a function that throws can therefore appear in
  `exception.trace`. The masks above catch known formats only. Set
  `zend.exception_ignore_args = On` in production, and on PHP 8.2+ mark sensitive parameters
  with `#[\SensitiveParameter]`.
- **No secret in dumps.** The API secret is held in a `Secret` vault object, so `var_dump`,
  `print_r`, `var_export`, `debug_zval_dump`, `json_encode` and `serialize` of the library,
  the client or its config never show it. They show `[REDACTED]` or `(not set)`. An
  unserialized client has no secret and does not send. The parameters that receive the
  secret carry `#[\SensitiveParameter]`, so PHP 8.2+ stack traces hide them; on PHP 7.4–8.1
  that line is only a comment.
- **Request data.** The SDK never captures request bodies, headers, cookies or client IP
  addresses automatically. Treat logs as sensitive data: don't log personal or payment details
  deliberately, and use `redact_fields` for any domain-specific keys.

## Development

> This package is developed in the InnLogger repository (`inn-logger-prime-2026`, under `packages/`) and published here automatically. Make changes there, not in the published repository; see `docs/operations/sdk-releases.md` in that repository.

The tests use PHPUnit 9.6 with a fake transport, so no CodeIgniter install is needed. The few CI
globals the drop-in files use are stubbed in `tests/bootstrap.php`. Run them in the portal
container, never on the local machine:

```bash
mutagen sync flush innlogger
ssh divaa-docker 'docker exec -w /var/www/html/packages/sdk-codeigniter3 innlogger-app composer install --no-interaction'
ssh divaa-docker 'docker exec -w /var/www/html/packages/sdk-codeigniter3 innlogger-app ./vendor/bin/phpunit'
```

Syntax check against PHP 7.4, on the host:

```bash
docker run --rm -v /home/chintan/apps/inn-logger-prime-2026/packages/sdk-codeigniter3:/app php:7.4-cli \
  sh -c 'find /app/src /app/application -name "*.php" -exec php -l {} \;'
```
