# Changelog

All notable changes to `cbagdawala/innlogger-codeigniter3` are listed here. Versions follow [semantic versioning](https://semver.org).

## 1.0.1 (2026-09-27)

- Released under the MIT licence and published on Packagist: install with a plain `composer require`, no repository entry or token needed.

## 1.0.0 (2026-09-26)

First release. PHP 7.4 and newer.

- `Innlogger` library with all seven severities, `exception()`, `log()` and `heartbeat()`.
- Optional `pre_system` hook for uncaught exceptions, PHP errors and fatal errors; optional `MY_Log` forwarding; CLI `test` and `heartbeat` commands.
- Request context capture (controller, method, URI, request ID, user ID).
- HMAC-SHA256 signed requests, threshold filtering, bounded retries that reuse the event ID, 429 cooldown.
- Fail-silent curl transport with a PHP-streams fallback, short timeouts and a recursion guard; the secret is kept out of dumps and serialization.
- Recursive, case-insensitive key redaction and value masking of messages and stack traces.
