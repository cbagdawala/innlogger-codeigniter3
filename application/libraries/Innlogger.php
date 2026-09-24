<?php
defined('BASEPATH') or exit('No direct script access allowed');

use InnLogger\CodeIgniter3\Client;
use InnLogger\CodeIgniter3\CodeIgniter\ContextProvider;
use InnLogger\CodeIgniter3\ErrorHandler;

/**
 * InnLogger library for CodeIgniter 3.
 *
 *   $this->load->library('innlogger');
 *   $this->innlogger->error('Payment failed', ['order_id' => 42]);
 *   $this->innlogger->exception($e);
 *
 * Configuration: application/config/innlogger.php ($config['innlogger'] = [...]).
 * The SDK core (src/) is found through Composer (config composer_autoload = TRUE) or, for a
 * copy-in install, under APPPATH.'third_party/innlogger/src' (override with 'src_path').
 *
 * Every method is fail-silent by default: InnLogger problems never reach the application.
 */
class Innlogger
{
    const CORE_NAMESPACE = 'InnLogger\\CodeIgniter3\\';

    /** @var Client|null shared by the library, the hook and MY_Log */
    private static $shared;

    /** @var bool */
    private static $bootFailed = false;

    /** @var ErrorHandler|null */
    private static $handler;

    /** @var Client|null */
    private $client;

    /**
     * CI3's loader passes the contents of config/innlogger.php (i.e. ['innlogger' => [...]]) or the
     * array given to $this->load->library('innlogger', $params).
     *
     * @param mixed $params
     */
    public function __construct(
        #[\SensitiveParameter]
        $params = null
    ) {
        $this->client = self::shared(is_array($params) ? $params : []);
    }

    // ------------------------------------------------------------------ severity API

    public function critical($message, $context = [])
    {
        return $this->client ? $this->client->critical($message, self::ctx($context)) : null;
    }

    public function error($message, $context = [])
    {
        return $this->client ? $this->client->error($message, self::ctx($context)) : null;
    }

    public function warning($message, $context = [])
    {
        return $this->client ? $this->client->warning($message, self::ctx($context)) : null;
    }

    public function notice($message, $context = [])
    {
        return $this->client ? $this->client->notice($message, self::ctx($context)) : null;
    }

    public function info($message, $context = [])
    {
        return $this->client ? $this->client->info($message, self::ctx($context)) : null;
    }

    public function debug($message, $context = [])
    {
        return $this->client ? $this->client->debug($message, self::ctx($context)) : null;
    }

    public function trace($message, $context = [])
    {
        return $this->client ? $this->client->trace($message, self::ctx($context)) : null;
    }

    /**
     * @param int|string $level InnLogger level (1-7) or name
     */
    public function log($level, $message, $context = [])
    {
        return $this->client ? $this->client->log($level, $message, self::ctx($context)) : null;
    }

    /**
     * @param Throwable $exception
     * @param int|string $level defaults to ERROR
     */
    public function exception($exception, $context = [], $level = 2)
    {
        if (!$this->client || !($exception instanceof Throwable)) {
            return null;
        }

        return $this->client->exception($exception, self::ctx($context), $level);
    }

    /** POST /api/v1/heartbeat; returns true when accepted. */
    public function heartbeat($application_version = null)
    {
        return $this->client ? $this->client->heartbeat($application_version === null ? null : (string) $application_version) : false;
    }

    /** Send a test event and return diagnostics (never throws). */
    public function test()
    {
        return $this->client ? $this->client->test() : [
            'configured' => false, 'https' => false, 'enabled' => false, 'status' => null,
            'accepted' => false, 'event_id' => null, 'error' => 'InnLogger SDK core could not be loaded',
        ];
    }

    public function enabled()
    {
        return $this->client !== null && $this->client->config()->enabled();
    }

    /** @return Client|null the framework-agnostic client */
    public function get_client()
    {
        return $this->client;
    }

    // ------------------------------------------------------------------ bootstrap (static)

    /**
     * The shared Client, built once from $params or config/innlogger.php. Null when the SDK core
     * cannot be loaded (reported once to log_message()).
     */
    public static function shared(
        #[\SensitiveParameter]
        array $params = []
    ) {
        if (self::$shared !== null || self::$bootFailed) {
            return self::$shared;
        }
        try {
            $settings = self::settings($params);
            if (!self::autoload($settings)) {
                self::$bootFailed = true;
                self::local_log('InnLogger: SDK core classes not found; install via Composer or copy src/ to application/third_party/innlogger/src');

                return null;
            }
            $client = new Client($settings, null, [__CLASS__, 'local_log']);
            ContextProvider::attach($client);
            self::$shared = $client;
        } catch (Throwable $e) {
            self::$bootFailed = true;
            self::local_log('InnLogger: could not initialise (' . get_class($e) . ')');
        }

        return self::$shared;
    }

    /**
     * Install the PHP error/exception handlers enabled in config (called by the pre_system hook).
     */
    public static function register_handlers()
    {
        if (self::$handler !== null) {
            return self::$handler;
        }
        $client = self::shared();
        if ($client === null) {
            return null;
        }
        try {
            self::$handler = ErrorHandler::register($client);
        } catch (Throwable $e) {
            self::$handler = null;
        }

        return self::$handler;
    }

    public static function handlers_installed()
    {
        return self::$handler !== null;
    }

    /** Forget the shared client (tests, long-running CLI workers). */
    public static function reset()
    {
        if (self::$handler !== null) {
            self::$handler->uninstall();
        }
        self::$handler = null;
        self::$shared = null;
        self::$bootFailed = false;
    }

    /**
     * Local, secret-free reporting of InnLogger's own failures through CI's logger.
     * MY_Log ignores lines starting with "InnLogger:" and the client's recursion guard stops loops.
     */
    public static function local_log($message)
    {
        try {
            if (function_exists('log_message')) {
                log_message('error', (string) $message);
            } else {
                error_log((string) $message);
            }
        } catch (Throwable $e) {
            // nothing else to do
        }
    }

    /**
     * @param mixed $context
     * @return array
     */
    private static function ctx($context)
    {
        if (is_array($context)) {
            return $context;
        }

        return $context === null ? [] : ['context' => $context];
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(
        #[\SensitiveParameter]
        array $params
    ) {
        if (isset($params['innlogger']) && is_array($params['innlogger'])) {
            $settings = $params['innlogger'];
        } elseif ($params) {
            $settings = $params;
        } else {
            $settings = self::config_file();
        }
        if ((!isset($settings['environment']) || $settings['environment'] === '') && defined('ENVIRONMENT')) {
            $settings['environment'] = ENVIRONMENT;
        }

        return $settings;
    }

    /**
     * Read config/innlogger.php, then config/<ENVIRONMENT>/innlogger.php (CI3 convention).
     *
     * @return array<string, mixed>
     */
    private static function config_file()
    {
        if (!defined('APPPATH')) {
            return [];
        }
        $files = [APPPATH . 'config/innlogger.php'];
        if (defined('ENVIRONMENT')) {
            $files[] = APPPATH . 'config/' . ENVIRONMENT . '/innlogger.php';
        }
        $settings = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $config = [];
            include $file;
            if (isset($config['innlogger']) && is_array($config['innlogger'])) {
                $settings = array_merge($settings, $config['innlogger']);
            }
        }

        return $settings;
    }

    /**
     * Make the core classes loadable: Composer first, then a copied src/ directory.
     */
    private static function autoload(array $settings)
    {
        if (class_exists(self::CORE_NAMESPACE . 'Client')) {
            return true;
        }
        $src = isset($settings['src_path']) && is_string($settings['src_path'])
            ? $settings['src_path']
            : (defined('APPPATH') ? APPPATH . 'third_party/innlogger/src' : '');
        $src = rtrim($src, '/\\');
        if ($src === '' || !is_dir($src)) {
            return false;
        }
        spl_autoload_register(static function ($class) use ($src) {
            if (strpos($class, self::CORE_NAMESPACE) !== 0) {
                return;
            }
            $file = $src . '/' . str_replace('\\', '/', substr($class, strlen(self::CORE_NAMESPACE))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        return class_exists(self::CORE_NAMESPACE . 'Client');
    }
}
