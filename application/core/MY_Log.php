<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * OPTIONAL: forwards log_message() calls to InnLogger when 'forward_log_message' is TRUE in
 * config/innlogger.php. CodeIgniter's own file logging is unchanged and always runs first.
 *
 * Only install this file if the application has no MY_Log of its own; otherwise copy
 * innlogger_forward() into the existing class and call it from write_log().
 *
 * CI level mapping: error -> ERROR (2), info -> INFO (5), debug -> DEBUG (6). The InnLogger
 * log_level threshold still decides what is sent.
 */
class MY_Log extends CI_Log
{
    public function write_log($level, $msg)
    {
        $result = parent::write_log($level, $msg);
        $this->innlogger_forward($level, $msg);

        return $result;
    }

    protected function innlogger_forward($level, $msg)
    {
        try {
            $msg = (string) $msg;
            // InnLogger's own failure reports must never be forwarded back to InnLogger.
            if (strpos($msg, 'InnLogger:') === 0) {
                return;
            }
            require_once APPPATH . 'libraries/Innlogger.php';
            $client = Innlogger::shared();
            if ($client === null || !$client->config()->get('forward_log_message')) {
                return;
            }
            // CI logs PHP errors/exceptions as "Severity: ..."; the hook already captured those.
            if (Innlogger::handlers_installed() && strpos($msg, 'Severity: ') === 0) {
                return;
            }
            $map = ['error' => 2, 'info' => 5, 'debug' => 6];
            $level = strtolower((string) $level);
            if (!isset($map[$level])) {
                return;
            }
            $client->log($map[$level], $msg, ['category' => 'log_message']);
        } catch (Throwable $e) {
            // never let InnLogger break logging
        }
    }
}
