<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * OPTIONAL CLI controller (web requests get a 404).
 *
 *   php index.php innlogger_cli test        # send a test event, print diagnostics
 *   php index.php innlogger_cli heartbeat   # POST /api/v1/heartbeat (run from cron, e.g. every 5 min)
 *   php index.php innlogger_cli heartbeat 1.4.2
 */
class Innlogger_cli extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) {
            show_404();
        }
        $this->load->library('innlogger');
    }

    public function index()
    {
        $this->test();
    }

    public function test()
    {
        $result = $this->innlogger->test();
        foreach ($result as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            echo str_pad($key, 12) . ': ' . ($value === null ? '-' : $value) . PHP_EOL;
        }
        exit($result['accepted'] ? 0 : 1);
    }

    public function heartbeat($application_version = null)
    {
        $ok = $this->innlogger->heartbeat($application_version);
        echo ($ok ? 'heartbeat accepted' : 'heartbeat failed (see application log)') . PHP_EOL;
        exit($ok ? 0 : 1);
    }
}
