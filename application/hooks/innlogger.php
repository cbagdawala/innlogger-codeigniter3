<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Optional InnLogger hook: captures uncaught exceptions, PHP errors and fatal errors, then hands
 * them on to CodeIgniter's own handlers unchanged.
 *
 * application/config/config.php:  $config['enable_hooks'] = TRUE;
 * application/config/hooks.php:
 *
 *   $hook['pre_system'][] = [
 *       'function' => 'innlogger_register_handlers',
 *       'filename' => 'innlogger.php',
 *       'filepath' => 'hooks',
 *   ];
 *
 * Which handlers are installed is controlled by capture_exceptions, capture_errors and
 * capture_fatal_errors in config/innlogger.php.
 */
if (!function_exists('innlogger_register_handlers')) {
    function innlogger_register_handlers()
    {
        try {
            require_once APPPATH . 'libraries/Innlogger.php';
            Innlogger::register_handlers();
        } catch (Throwable $e) {
            // never interfere with the application's boot
        }
    }
}
