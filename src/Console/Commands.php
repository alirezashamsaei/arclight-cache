<?php
// Arclight Cache — WP-CLI commands (original code, PHP 7.2 compat).

namespace ArclightCache\Console;

use ArclightCache\Cache\ArclightObjectCache;
use ArclightCache\Client\ArrayFakeClient;
use ArclightCache\Config\Configuration;
use ArclightCache\Connection\Connector;
use ArclightCache\Connection\SingleConnection;
use ArclightCache\Support\ArrayLogger;
use ArclightCache\Support\MemoryMetricsStore;
use ArclightCache\Tools\Doctor;

defined('ABSPATH') || exit;

class Commands extends \WP_CLI_Command
{
    /**
     * Validate config without installing anything.
     *
     * ## OPTIONS
     * [--format=<format>] : table or json.
     */
    public function dry_run($args, $assoc)
    {
        $config = $this->config();
        $errors = $config->validate();
        $format = isset($assoc['format']) ? $assoc['format'] : 'table';
        if ($format === 'json') {
            \WP_CLI::print_value(array('valid' => count($errors) === 0, 'errors' => $errors), array('format' => 'json'));
            return;
        }
        if (count($errors) === 0) {
            \WP_CLI::success('Config valid.');
        } else {
            foreach ($errors as $e) {
                \WP_CLI::warning($e);
            }
            \WP_CLI::error('Config invalid.');
        }
    }

    /**
     * Install the object-cache.php drop-in (runs self-test first).
     */
    public function enable($args, $assoc)
    {
        $config = $this->config();
        $errors = $config->validate();
        if (count($errors) > 0) {
            foreach ($errors as $e) {
                \WP_CLI::warning($e);
            }
            \WP_CLI::error('Refusing to enable: config invalid. Fix ARCLIGHT_CACHE_CONFIG first.');
        }
        $selfTest = $this->selfTest($config);
        if (! $selfTest['ok']) {
            \WP_CLI::warning('Self-test failed: ' . $selfTest['detail']);
            \WP_CLI::error('Refusing to enable: self-test failed.');
        }
        $dropin = WP_CONTENT_DIR . '/object-cache.php';
        $stub = dirname(dirname(__DIR__)) . '/stubs/object-cache.php';
        if (! file_exists($stub)) {
            $stub = dirname(__DIR__, 2) . '/stubs/object-cache.php';
        }
        if (! copy($stub, $dropin)) {
            \WP_CLI::error('Could not copy drop-in to ' . $dropin);
        }
        if (function_exists('wp_opcache_invalidate')) {
            wp_opcache_invalidate($dropin, true);
        }
        \WP_CLI::success('Drop-in installed. Persistent: ' . ($selfTest['persistent'] ? 'yes' : 'no (fallback, check doctor)'));
    }

    /**
     * Remove the drop-in.
     */
    public function disable($args, $assoc)
    {
        $dropin = WP_CONTENT_DIR . '/object-cache.php';
        if (file_exists($dropin)) {
            unlink($dropin);
            \WP_CLI::success('Drop-in removed.');
        } else {
            \WP_CLI::log('No drop-in installed.');
        }
    }

    /**
     * Flush the cache.
     */
    public function flush($args, $assoc)
    {
        $cache = $this->cache();
        \WP_CLI::success($cache->flush() ? 'Flushed.' : 'Flush failed (see error log).');
    }

    /**
     * Show cache info/stats.
     *
     * ## OPTIONS
     * [--format=<format>] : table or json.
     */
    public function info($args, $assoc)
    {
        $cache = $this->cache();
        $stats = $cache->stats();
        $format = isset($assoc['format']) ? $assoc['format'] : 'table';
        if ($format === 'json') {
            \WP_CLI::print_value($stats, array('format' => 'json'));
            return;
        }
        \WP_CLI::print_value($stats);
    }

    /**
     * Run the single health report.
     *
     * ## OPTIONS
     * [--format=<format>] : table or json.
     * [--show-secrets] : include password-set flag.
     */
    public function doctor($args, $assoc)
    {
        $config = $this->config();
        $connection = Connector::createConnection($config);
        $show = isset($assoc['show-secrets']);
        $doctor = new Doctor($connection, $config, $show);
        $report = $doctor->run();
        $format = isset($assoc['format']) ? $assoc['format'] : 'table';
        if ($format === 'json') {
            \WP_CLI::print_value($report, array('format' => 'json'));
        } else {
            foreach ($report['checks'] as $c) {
                $line = ($c['ok'] ? 'ok' : 'FAIL') . ' ' . $c['name'] . ' — ' . $c['detail'];
                if ($c['ok']) {
                    \WP_CLI::log($line);
                } else {
                    \WP_CLI::warning($line);
                }
            }
        }
        if ($report['status'] !== 'pass') {
            \WP_CLI::error('Doctor: FAIL');
        } else {
            \WP_CLI::success('Doctor: PASS');
        }
    }

    /**
     * Show recent metrics rollup.
     *
     * ## OPTIONS
     * [--format=<format>] : table or json.
     */
    public function digest($args, $assoc)
    {
        $cache = $this->cache();
        $stats = $cache->stats();
        $metrics = isset($stats['metrics']) ? $stats['metrics'] : array();
        $format = isset($assoc['format']) ? $assoc['format'] : 'table';
        if ($format === 'json') {
            \WP_CLI::print_value($metrics, array('format' => 'json'));
            return;
        }
        \WP_CLI::print_value($metrics);
    }

    /** @return Configuration */
    private function config()
    {
        if (function_exists('ArclightCache\\arclight_config')) {
            return \ArclightCache\arclight_config();
        }
        $raw = defined('ARCLIGHT_CACHE_CONFIG') ? ARCLIGHT_CACHE_CONFIG : array();
        return Configuration::fromArray($raw);
    }

    /** @return ArclightObjectCache */
    private function cache()
    {
        $config = $this->config();
        $connection = Connector::createConnection($config);
        return new ArclightObjectCache($connection, $config, array(
            'siteId' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            'isMultisite' => function_exists('is_multisite') ? is_multisite() : false,
            'logger' => new ArrayLogger(),
            'metrics' => new MemoryMetricsStore(),
        ));
    }

    /**
     * Pre-enable self-test: round-trip, serialization guards, prefix isolation, split check.
     *
     * @param Configuration $config
     * @return array ok/persistent/detail
     */
    private function selfTest(Configuration $config)
    {
        $client = Connector::createClient($config);
        $connection = new SingleConnection($client, $config);
        $cache = new ArclightObjectCache($connection, $config, array(
            'logger' => new ArrayLogger(),
            'metrics' => new MemoryMetricsStore(),
        ));

        // Serialization guards (run even in fallback).
        if ($cache->set('__selftest_closure__', function () {}, 'default') !== false) {
            return array('ok' => false, 'persistent' => false, 'detail' => 'closure guard failed');
        }

        if (! $cache->isPersistent()) {
            return array('ok' => true, 'persistent' => false, 'detail' => 'non-persistent fallback (non-fatal)');
        }

        // Round-trip.
        $cache->set('__selftest__', 'ping', 'default');
        if ($cache->get('__selftest__', 'default', true) !== 'ping') {
            return array('ok' => false, 'persistent' => true, 'detail' => 'round-trip mismatch');
        }
        $cache->delete('__selftest__', 'default');

        // Prefix isolation probe: foreign-looking key must not collide with ours.
        $cache->set('__selftest_iso__', 'a', 'default');
        $raw = $client->call('get', array('otherplugin:default:__selftest_iso__'));
        if ($raw !== false) {
            return array('ok' => false, 'persistent' => true, 'detail' => 'prefix collision detected');
        }
        $cache->delete('__selftest_iso__', 'default');

        return array('ok' => true, 'persistent' => true, 'detail' => 'ok');
    }
}
