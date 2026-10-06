<?php
// Arclight Cache — plugin orchestrator (original code, PHP 7.2 compat).

namespace ArclightCache;

use ArclightCache\Cache\ArclightObjectCache;
use ArclightCache\Cache\ArrayObjectCache;
use ArclightCache\Cache\ObjectCacheInterface;
use ArclightCache\Config\Configuration;
use ArclightCache\Connection\Connector;
use ArclightCache\Support\ErrorLogLogger;
use ArclightCache\Support\MetricsHistory;
use ArclightCache\Support\SqliteMetricsStore;
use ArclightCache\Tools\Doctor;

defined('ABSPATH') || exit;

final class Plugin
{
    /** @var Configuration */
    private $config;

    /** @var ObjectCacheInterface */
    private $cache;

    /** @var string */
    private $version;

    /** @param Configuration $config @param ObjectCacheInterface $cache */
    private function __construct(Configuration $config, ObjectCacheInterface $cache)
    {
        $this->config = $config;
        $this->cache = $cache;
        $this->version = defined('ArclightCache\\VERSION') ? VERSION : '0.2.0';
    }

    /** @return self */
    public static function boot()
    {
        $config = function_exists('ArclightCache\\arclight_config') ? arclight_config() : Configuration::fromArray(array());

        $logger = new ErrorLogLogger();
        $metrics = null;
        if ((bool) $config->get('metrics', true)) {
            $sqlite = $config->get('sqlite_path', null);
            if ($sqlite === null && function_exists('wp_upload_dir')) {
                $up = wp_upload_dir();
                if (! empty($up['basedir'])) {
                    $sqlite = trailingslashit($up['basedir']) . 'arclight-cache/metrics.sqlite';
                }
            }
            $metrics = new SqliteMetricsStore($sqlite, $logger);
        }

        // Reuse the drop-in's live instance when present: it served this
        // request, so its metrics are real. A second instance would report
        // a newborn (always-zero) rollup and open a redundant connection.
        $cache = null;
        if (isset($GLOBALS['wp_object_cache']) && $GLOBALS['wp_object_cache'] instanceof ArclightObjectCache) {
            $cache = $GLOBALS['wp_object_cache'];
        }
        if ($cache === null) {
            $connection = Connector::createConnection($config);
            $isMultisite = function_exists('is_multisite') ? is_multisite() : false;
            $siteId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;

            $cache = new ArclightObjectCache($connection, $config, array(
                'siteId' => $siteId,
                'isMultisite' => $isMultisite,
                'logger' => $logger,
                'metrics' => $metrics,
            ));
        }

        if (! $cache->isPersistent() && class_exists('Redis')) {
            // Connected class exists but dial failed: keep degraded cache, error already logged once.
        }
        if (! class_exists('Redis')) {
            $cache = new ArrayObjectCache($logger);
        }

        $instance = new self($config, $cache);

        if (function_exists('add_action')) {
            $instance->registerHooks();
        }

        return $instance;
    }

    /** @return Configuration */
    public function config()
    {
        return $this->config;
    }

    /** @return ObjectCacheInterface */
    public function cache()
    {
        return $this->cache;
    }

    /** @return void */
    private function registerHooks()
    {
        add_action('admin_menu', array($this, 'adminMenu'));
        add_action('admin_init', array($this, 'registerSettings'));
        add_action('admin_init', array($this, 'maybeShowActivationNotice'));
        add_action('admin_init', array($this, 'handleActions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdmin'));
        add_action('wp_dashboard_setup', array($this, 'dashboardWidget'));
        add_filter('site_status_tests', array($this, 'siteHealthTests'));
        add_action('rest_api_init', array($this, 'restRoutes'));
        add_action('shutdown', array($this, 'persistMetrics'));
    }

    /** @return void Single unified page: Tools merged into the dashboard. */
    public function adminMenu()
    {
        if (! function_exists('add_options_page')) {
            return;
        }
        add_options_page('Arclight Cache', 'Arclight Cache', 'manage_options', 'arclight-cache', array($this, 'renderDashboard'));
    }

    /** @return void */
    public function registerSettings()
    {
        if (! function_exists('register_setting')) {
            return;
        }
        register_setting('arclight_cache', 'arclight_cache_settings', array($this, 'sanitizeSettings'));
    }

    /**
     * Validate dashboard-submitted settings. Unknown keys rejected, wrong
     * types rejected with a settings error; password blank keeps old value.
     *
     * @param mixed $input
     * @return array
     */
    public function sanitizeSettings($input)
    {
        $old = function_exists('get_option') ? get_option('arclight_cache_settings', array()) : array();
        if (! is_array($old)) {
            $old = array();
        }
        if (! is_array($input)) {
            return $old;
        }
        $clean = $old;
        $spec = array(
            'host' => 'string', 'port' => 'port', 'database' => 'db',
            'username' => 'nullable_string', 'password' => 'password',
            'prefix' => 'prefix', 'timeout' => 'seconds', 'read_timeout' => 'seconds',
            'retries' => 'retries', 'backoff_ms' => 'backoff',
            'ignored_groups' => 'csv', 'split_alloptions' => 'bool',
            'metrics' => 'bool', 'tls' => 'bool',
        );
        foreach ($spec as $key => $kind) {
            if (! array_key_exists($key, $input)) {
                if ($kind === 'bool') {
                    $clean[$key] = false; // unchecked checkbox absent from POST
                }
                continue;
            }
            $v = $input[$key];
            switch ($kind) {
                case 'string':
                    $v = is_string($v) ? trim($v) : '';
                    if ($v !== '') {
                        $clean[$key] = $v;
                    }
                    break;
                case 'nullable_string':
                    $clean[$key] = is_string($v) && $v !== '' ? trim($v) : null;
                    break;
                case 'password':
                    if (is_string($v) && $v !== '' && $v !== '__KEEP__') {
                        $clean[$key] = $v;
                    } elseif (is_string($v) && $v === '') {
                        $clean[$key] = null; // cleared deliberately
                    }
                    break;
                case 'port':
                    $v = (int) $v;
                    if ($v >= 1 && $v <= 65535) {
                        $clean[$key] = $v;
                    } else {
                        $this->settingsError($key . ': port 1-65535');
                    }
                    break;
                case 'db':
                    $v = (int) $v;
                    if ($v >= 0 && $v <= 31) {
                        $clean[$key] = $v;
                    } else {
                        $this->settingsError($key . ': database 0-31');
                    }
                    break;
                case 'prefix':
                    $v = is_string($v) ? trim($v) : '';
                    if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $v) === 1) {
                        $clean[$key] = $v;
                    } else {
                        $this->settingsError($key . ': 1-32 chars A-Za-z0-9_-');
                    }
                    break;
                case 'seconds':
                    $v = (float) $v;
                    if ($v >= 0.05 && $v <= 10.0) {
                        $clean[$key] = $v;
                    } else {
                        $this->settingsError($key . ': 0.05-10.0 seconds');
                    }
                    break;
                case 'retries':
                    $v = (int) $v;
                    if ($v >= 0 && $v <= 5) {
                        $clean[$key] = $v;
                    } else {
                        $this->settingsError($key . ': 0-5');
                    }
                    break;
                case 'backoff':
                    $v = (int) $v;
                    if ($v >= 0 && $v <= 2000) {
                        $clean[$key] = $v;
                    } else {
                        $this->settingsError($key . ': 0-2000 ms');
                    }
                    break;
                case 'csv':
                    $parts = is_string($v) ? explode(',', $v) : (array) $v;
                    $groups = array();
                    foreach ($parts as $p) {
                        $p = trim((string) $p);
                        if ($p !== '') {
                            $groups[] = $p;
                        }
                    }
                    $clean[$key] = $groups;
                    break;
                case 'bool':
                    $clean[$key] = ! empty($v) && $v !== '0';
                    break;
            }
        }
        return $clean;
    }

    /** @param string $message */
    private function settingsError($message)
    {
        if (function_exists('add_settings_error')) {
            add_settings_error('arclight_cache_settings', 'arclight_invalid', 'Arclight Cache: ' . $message, 'error');
        }
    }

    /**
     * Handle dashboard action buttons (flush, test connection). Nonce +
     * capability checked; redirects back with ?arclight_msg=.
     *
     * @return void
     */
    public function handleActions()
    {
        if (! isset($_GET['arclight_action']) || ! function_exists('current_user_can')) {
            return;
        }
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        $action = (string) $_GET['arclight_action'];
        if (! isset($_GET['_wpnonce']) || ! wp_verify_nonce((string) $_GET['_wpnonce'], 'arclight_' . $action)) {
            wp_die('Invalid nonce');
        }
        $msg = '';
        if ($action === 'flush') {
            $ok = $this->cache->flush();
            $msg = $ok ? 'flushed' : 'flush-failed';
        } elseif ($action === 'test') {
            $report = $this->doctorReport(false);
            $msg = $report['status'] === 'pass' ? 'test-pass' : 'test-fail';
            set_transient('arclight_test_report', $report, 60);
        }
        wp_safe_redirect(add_query_arg(array('page' => 'arclight-cache', 'arclight_msg' => $msg), admin_url('options-general.php')));
        exit;
    }

    /** @param string $hook */
    public function enqueueAdmin($hook)
    {
        if ($hook !== 'settings_page_arclight-cache') {
            return;
        }
        wp_enqueue_style('arclight-admin', plugins_url('resources/css/admin.css', DIR . '/x'), array(), $this->version);
        wp_enqueue_script('arclight-admin', plugins_url('resources/js/admin.js', DIR . '/x'), array(), $this->version, true);
        $series = $this->graphSeries();
        wp_localize_script('arclight-admin', 'ArclightData', array('series' => $series));
    }

    /** @return void */
    public function maybeShowActivationNotice()
    {
        if (get_transient('arclight_cache_activated') && current_user_can('manage_options')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-info"><p>Arclight Cache active. Define <code>ARCLIGHT_CACHE_CONFIG</code> in wp-config.php, then run <code>wp arclight enable</code>.</p></div>';
            });
            delete_transient('arclight_cache_activated');
        }
        if (! class_exists('Redis') && current_user_can('manage_options')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-warning"><p>Arclight Cache: phpredis extension missing — running non-persistent. Install <code>php-redis</code> for persistent caching.</p></div>';
            });
        }
    }

    /** @return void */
    public function dashboardWidget()
    {
        if (! function_exists('wp_add_dashboard_widget')) {
            return;
        }
        wp_add_dashboard_widget('arclight_cache', 'Arclight Cache', array($this, 'renderWidget'));
    }

    /** @return void */
    public function renderWidget()
    {
        $stats = $this->cache->stats();
        $this->renderTemplate('widgets/overview.phtml', array('stats' => $stats));
    }

    /** @return void Unified dashboard: status + graph + browser + config + tools. */
    public function renderDashboard()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $stats = $this->cache->stats();
        $history = new MetricsHistory($this->metricsPath());
        $totals = $history->totals();
        $server = $this->serverInfo();
        $browser = $this->browseKeys();
        $sources = function_exists('ArclightCache\\arclight_config_source') ? arclight_config_source() : array();
        $constantManaged = in_array('constant', array_values($sources), true);
        $testReport = get_transient('arclight_test_report');
        if ($testReport) {
            delete_transient('arclight_test_report');
        }
        $this->renderTemplate('pages/dashboard.phtml', array(
            'stats' => $stats,
            'config' => $this->config->toArray(),
            'sources' => $sources,
            'constantManaged' => $constantManaged,
            'totals' => $totals,
            'server' => $server,
            'browser' => $browser,
            'series' => $this->graphSeries(),
            'testReport' => $testReport,
            'msg' => isset($_GET['arclight_msg']) ? (string) $_GET['arclight_msg'] : '',
        ));
    }

    /** @return string|null */
    private function metricsPath()
    {
        $p = $this->config->get('sqlite_path', null);
        if (is_string($p) && $p !== '') {
            return $p;
        }
        if (function_exists('wp_upload_dir')) {
            $up = wp_upload_dir();
            if (! empty($up['basedir'])) {
                return trailingslashit($up['basedir']) . 'arclight-cache/metrics.sqlite';
            }
        }
        return null;
    }

    /** @return array Graph buckets for the canvas chart. */
    private function graphSeries()
    {
        $history = new MetricsHistory($this->metricsPath());
        return $history->series(24, 300);
    }

    /**
     * Live server counters (INFO stats + memory). Empty array when unavailable.
     *
     * @return array
     */
    private function serverInfo()
    {
        $out = array();
        try {
            if (method_exists($this->cache, 'connection')) {
                $conn = $this->cache->connection();
                if (method_exists($conn, 'isAvailable') && $conn->isAvailable()) {
                    $client = $conn->client();
                    foreach (array('stats', 'memory', 'server', 'clients') as $section) {
                        try {
                            $info = $client->info($section);
                            if (is_array($info)) {
                                $out = array_merge($out, $info);
                            }
                        } catch (\Exception $e) {
                        }
                    }
                }
            }
        } catch (\Exception $e) {
        }
        return $out;
    }

    /**
     * Redis key overview: SCAN-bounded sample grouped by second key segment
     * (arclight:<group>:...), with per-key type/ttl for the first N.
     *
     * @param int $max
     * @return array groups/sampled/inspected/truncated/total_hint.
     */
    private function browseKeys($max = 60)
    {
        $result = array('groups' => array(), 'sampled' => array(), 'inspected' => array(), 'truncated' => false, 'available' => false);
        try {
            if (! method_exists($this->cache, 'connection')) {
                return $result;
            }
            $conn = $this->cache->connection();
            if (! method_exists($conn, 'isAvailable') || ! $conn->isAvailable()) {
                return $result;
            }
            $client = $conn->client();
            if (! method_exists($client, 'scan')) {
                return $result;
            }
            $prefix = (string) $this->config->get('prefix', 'arclight');
            $cursor = 0;
            $keys = array();
            $rounds = 0;
            do {
                $page = $client->scan($cursor, $prefix . '*', 200);
                $cursor = isset($page[0]) ? (int) $page[0] : 0;
                $found = isset($page[1]) && is_array($page[1]) ? $page[1] : array();
                foreach ($found as $k) {
                    $keys[] = $k;
                    if (count($keys) >= $max) {
                        break 2;
                    }
                }
                $rounds++;
            } while ($cursor !== 0 && $rounds < 5);
            $result['available'] = true;
            $result['truncated'] = ($cursor !== 0);
            $result['sampled'] = $keys;
            foreach ($keys as $k) {
                $seg = explode(':', $k);
                $group = isset($seg[1]) ? $seg[1] : '(root)';
                if (! isset($result['groups'][$group])) {
                    $result['groups'][$group] = 0;
                }
                $result['groups'][$group]++;
            }
            arsort($result['groups']);
            $inspect = array_slice($keys, 0, 20);
            foreach ($inspect as $k) {
                try {
                    $result['inspected'][$k] = $client->inspect($k);
                } catch (\Exception $e) {
                    $result['inspected'][$k] = array('type_name' => 'error');
                }
            }
        } catch (\Exception $e) {
        }
        return $result;
    }

    /**
     * @param bool $showSecrets
     * @return array status/checks/...
     */
    private function doctorReport($showSecrets = false)
    {
        $doctor = new Doctor(
            method_exists($this->cache, 'connection') ? $this->cache->connection() : Connector::createConnection($this->config),
            $this->config,
            $showSecrets
        );
        return $doctor->run();
    }

    /**
     * @param array $tests
     * @return array
     */
    public function siteHealthTests($tests)
    {
        $tests['direct']['arclight_cache'] = array(
            'label' => 'Arclight Cache',
            'test' => array($this, 'siteHealthTest'),
        );
        return $tests;
    }

    /** @return array */
    public function siteHealthTest()
    {
        $stats = $this->cache->stats();
        $ok = ! empty($stats['persistent']);
        return array(
            'label' => $ok ? 'Arclight Cache is persistent' : 'Arclight Cache is non-persistent',
            'status' => $ok ? 'good' : 'recommended',
            'badge' => array('label' => 'Performance', 'color' => $ok ? 'green' : 'orange'),
            'description' => $ok ? 'Redis-backed object cache is active.' : 'Running in-memory fallback. Check connection and phpredis extension.',
            'test' => 'arclight_cache',
        );
    }

    /** @return void */
    public function restRoutes()
    {
        if (! function_exists('register_rest_route')) {
            return;
        }
        register_rest_route('arclight/v1', '/stats', array(
            'methods' => 'GET',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'callback' => array($this, 'restStats'),
        ));
        register_rest_route('arclight/v1', '/series', array(
            'methods' => 'GET',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'callback' => array($this, 'restSeries'),
        ));
        register_rest_route('arclight/v1', '/browse', array(
            'methods' => 'GET',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'callback' => array($this, 'restBrowse'),
        ));
    }

    /** @return array Live stats + lifetime totals + server counters. */
    public function restStats()
    {
        $stats = $this->cache->stats();
        $stats['totals'] = (new MetricsHistory($this->metricsPath()))->totals();
        $stats['server'] = $this->serverInfo();
        return $stats;
    }

    /** @return array Graph series. */
    public function restSeries()
    {
        return $this->graphSeries();
    }

    /** @return array Redis browser snapshot. */
    public function restBrowse()
    {
        return $this->browseKeys();
    }

    /** @return void Persist admin-side metrics sample (drop-in samples itself). */
    public function persistMetrics()
    {
        try {
            $stats = $this->cache->stats();
            $rollup = isset($stats['metrics']) && is_array($stats['metrics']) ? $stats['metrics'] : array();
            if (! empty($rollup) && ((int) (isset($rollup['total']) ? $rollup['total'] : 0)) > 0) {
                $server = array();
                foreach ($this->serverInfo() as $k => $v) {
                    if (in_array($k, array('keyspace_hits', 'keyspace_misses', 'used_memory_human', 'connected_clients'), true)) {
                        $server[$k] = $v;
                    }
                }
                $history = new MetricsHistory($this->metricsPath());
                $history->sample($rollup, $server);
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * @param string $template Relative to templates/.
     * @param array $vars
     * @return void
     */
    private function renderTemplate($template, array $vars = array())
    {
        $file = DIR . '/templates/' . $template;
        if (! file_exists($file)) {
            echo '<div class="notice notice-error"><p>Arclight: missing template ' . esc_html($template) . '</p></div>';
            return;
        }
        extract($vars, EXTR_SKIP);
        include $file;
    }
}
