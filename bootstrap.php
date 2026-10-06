<?php
// Arclight Cache — autoloader + env config + log helper (original code, PHP 7.2 compat).

namespace ArclightCache;

use ArclightCache\Config\Configuration;

defined('ABSPATH') || exit;

// Some modules group several classes per file (Support/Logger.php,
// Support/Metrics.php); map those explicitly before file-per-class fallback.
spl_autoload_register(function ($fqcn) {
    if (strpos($fqcn, 'ArclightCache\\') !== 0) {
        return;
    }
    $grouped = array(
        'ArclightCache\\Support\\LoggerInterface' => '/src/Support/Logger.php',
        'ArclightCache\\Support\\NullLogger' => '/src/Support/Logger.php',
        'ArclightCache\\Support\\ArrayLogger' => '/src/Support/Logger.php',
        'ArclightCache\\Support\\CallbackLogger' => '/src/Support/Logger.php',
        'ArclightCache\\Support\\ErrorLogLogger' => '/src/Support/Logger.php',
        'ArclightCache\\Support\\MetricsStoreInterface' => '/src/Support/Metrics.php',
        'ArclightCache\\Support\\MemoryMetricsStore' => '/src/Support/Metrics.php',
        'ArclightCache\\Support\\SqliteMetricsStore' => '/src/Support/Metrics.php',
    );
    if (isset($grouped[$fqcn])) {
        require_once __DIR__ . $grouped[$fqcn];
        return;
    }
    $rel = str_replace('\\', '/', substr($fqcn, strlen('ArclightCache\\')));
    $file = __DIR__ . '/src/' . $rel . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Env JSON (ARCLIGHT_CONFIG) is merged inside arclight_config() with proper
// precedence (constant > db > env > filter), so nothing is defined here —
// defining a constant from env would wrongly shadow dashboard DB settings.

if (! function_exists(__NAMESPACE__ . '\arclight_log')) :
    /**
     * @param string $level
     * @param string $message
     * @return void
     */
    function arclight_log($level, $message)
    {
        if (function_exists('error_log')) {
            error_log('arclight.' . $level . ': ' . $message);
        }
    }
endif;

if (! function_exists(__NAMESPACE__ . '\arclight_config')) :
    /**
     * Resolve effective config. Precedence (highest wins):
     * PHP constant ARCLIGHT_CACHE_CONFIG > dashboard DB option > env JSON > filter.
     * The wp_options row lets the dashboard configure Redis without wp-config
     * edits or WP-CLI; the constant remains the override for managed hosts.
     *
     * @return Configuration
     */
    function arclight_config()
    {
        $raw = array();
        if (function_exists('getenv')) {
            $env = getenv('ARCLIGHT_CONFIG');
            $decoded = is_string($env) && $env !== '' ? json_decode($env, true) : null;
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }
        // get_option() needs wp_cache_get(), which may not exist yet during
        // early drop-in boot (the drop-in defines it later in the same file).
        // Skip DB settings on that pass; constant/env still apply, and the
        // plugin-side boot (after options load) sees the DB row normally.
        if (function_exists('get_option') && function_exists('wp_cache_get')) {
            $db = get_option('arclight_cache_settings', array());
            if (is_array($db) && ! empty($db)) {
                $raw = array_merge($raw, $db);
            }
        }
        if (defined('ARCLIGHT_CACHE_CONFIG') && is_array(ARCLIGHT_CACHE_CONFIG)) {
            $raw = array_merge($raw, ARCLIGHT_CACHE_CONFIG);
        }
        if (function_exists('apply_filters')) {
            $raw = apply_filters('arclight_cache_config', $raw);
        }
        return Configuration::fromArray($raw);
    }
endif;

if (! function_exists(__NAMESPACE__ . '\arclight_config_source')) :
    /**
     * Where did each effective value come from? For the dashboard banner:
     * "managed by constant — dashboard edits disabled" vs editable.
     *
     * @return array key => 'constant'|'db'|'env'|'default'
     */
    function arclight_config_source()
    {
        $sources = array();
        $defaults = Configuration::defaults();
        foreach ($defaults as $k => $v) {
            $sources[$k] = 'default';
        }
        if (function_exists('getenv')) {
            $env = getenv('ARCLIGHT_CONFIG');
            $decoded = is_string($env) && $env !== '' ? json_decode($env, true) : null;
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $sources[$k] = 'env';
                }
            }
        }
        if (function_exists('get_option') && function_exists('wp_cache_get')) {
            $db = get_option('arclight_cache_settings', array());
            if (is_array($db)) {
                foreach ($db as $k => $v) {
                    $sources[$k] = 'db';
                }
            }
        }
        if (defined('ARCLIGHT_CACHE_CONFIG') && is_array(ARCLIGHT_CACHE_CONFIG)) {
            foreach (ARCLIGHT_CACHE_CONFIG as $k => $v) {
                $sources[$k] = 'constant';
            }
        }
        return $sources;
    }
endif;
