<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| InnLogger
| -------------------------------------------------------------------------
| Keep the API secret out of version control: read it from the environment
| (SetEnv / fastcgi_param / .env loader) as below.
|
| Severity: 0 OFF, 1 CRITICAL, 2 ERROR, 3 WARNING, 4 NOTICE, 5 INFO, 6 DEBUG, 7 TRACE.
| 'log_level' sends every event at that level or more severe (2 = CRITICAL + ERROR, 0 = nothing).
*/
$config['innlogger'] = [
    'enabled' => getenv('INNLOGGER_ENABLED') !== false ? getenv('INNLOGGER_ENABLED') : true,
    'url' => getenv('INNLOGGER_URL') ?: 'https://logger.example.com',
    'api_key' => getenv('INNLOGGER_API_KEY') ?: '',
    'api_secret' => getenv('INNLOGGER_API_SECRET') ?: '',
    'log_level' => getenv('INNLOGGER_LOG_LEVEL') !== false ? (int) getenv('INNLOGGER_LOG_LEVEL') : 2,
    'timeout' => getenv('INNLOGGER_TIMEOUT') ?: 2,          // total seconds per request
    'connect_timeout' => 1,                                  // seconds
    'environment' => getenv('INNLOGGER_ENVIRONMENT') ?: (defined('ENVIRONMENT') ? ENVIRONMENT : 'production'),
    'category' => 'application',                             // default event category ('exception' for exceptions)
    'application' => null,                                   // e.g. 'trusted-nanny'
    'application_version' => null,                           // e.g. '1.4.2'
    'hostname' => null,                                      // null = gethostname()

    // Failure behaviour: never throw into the app; bounded retry (0-3) on network errors and
    // 502/503/504, reusing the same event_id and request id. A 429 pauses sending for
    // retry_after / Retry-After seconds (1-3600) instead of retrying.
    'fail_silent' => true,
    'retries' => 1,
    'retry_delay_ms' => 100,

    // Extra keys to mask (case-insensitive, any depth) on top of the built-in list:
    // password, password_confirmation, token, access_token, refresh_token, authorization,
    // cookie, card_number, cvv, secret, api_secret (+ passwd, set_cookie, php_auth_pw, cvc, ...).
    'redact_fields' => [],

    // Value masking applied to the message, exception message, stack trace, URL and every
    // string in context/metadata. Built in: Bearer/Basic/Digest credentials, ils_... secrets
    // and the configured api_secret. Add regex => replacement pairs (or plain regexes):
    'mask_patterns' => [
        // '/\b\d{3}-\d{2}-\d{4}\b/' => '[SSN]',
    ],

    // Transport security. allow_insecure permits http:// URLs; local development only.
    'allow_insecure' => false,
    'verify_ssl' => true,

    // Request context: controller, method, URI, HTTP method, request id, user id.
    'capture_request_context' => true,
    'user_id_session_key' => null,                           // e.g. 'user_id' => $this->session->userdata('user_id')
    'user_id_resolver' => null,                              // or function ($CI) { return $CI->auth->id(); }

    // Used by the optional pre_system hook (application/hooks/innlogger.php).
    'capture_exceptions' => true,                            // uncaught exceptions, level CRITICAL
    'capture_fatal_errors' => true,                          // fatal errors on shutdown, level CRITICAL
    'capture_errors' => false,                               // warnings/notices via the error handler
    'error_log_level' => 3,                                  // most verbose PHP error level reported

    // Used by the optional application/core/MY_Log.php: forward log_message() calls.
    'forward_log_message' => false,

    // Copy-in install only: where src/ lives when Composer is not used.
    // 'src_path' => APPPATH . 'third_party/innlogger/src',
];
