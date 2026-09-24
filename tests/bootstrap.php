<?php

/*
 * Test bootstrap: Composer autoload plus the handful of CodeIgniter 3 globals the drop-in files
 * touch (BASEPATH, APPPATH, ENVIRONMENT, CI_VERSION, log_message(), get_instance()). No CI3
 * install is needed.
 */

require __DIR__ . '/../vendor/autoload.php';

define('BASEPATH', __DIR__ . '/fixtures/system/');
define('APPPATH', __DIR__ . '/fixtures/app/');
define('ENVIRONMENT', 'testing');
define('CI_VERSION', '3.1.13');

$GLOBALS['__ci_log'] = [];
$GLOBALS['__ci_instance'] = null;

if (!function_exists('log_message')) {
    function log_message($level, $message)
    {
        $GLOBALS['__ci_log'][] = [$level, $message];
    }
}

if (!function_exists('get_instance')) {
    function get_instance()
    {
        return $GLOBALS['__ci_instance'];
    }
}

require_once __DIR__ . '/../application/libraries/Innlogger.php';
