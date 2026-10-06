<?php
// Arclight Cache — WP object-cache drop-in stub (original code, PHP 7.2 compat).
// Installed to WP_CONTENT_DIR/object-cache.php by `wp arclight enable`.

defined('ABSPATH') || exit;

// Load plugin core when the drop-in runs standalone (plugin may be inactive).
// Note: class_exists() WITH autoload (default true) so bootstrap's autoloader resolves it.
if (! class_exists('ArclightCache\\Cache\\ArclightObjectCache')) {
    $roots = array();
    if (defined('WP_PLUGIN_DIR')) {
        $roots[] = WP_PLUGIN_DIR . '/arclight-cache/bootstrap.php';
    } else {
        $roots[] = dirname(__FILE__) . '/../plugins/arclight-cache/bootstrap.php';
        $roots[] = WP_CONTENT_DIR . '/plugins/arclight-cache/bootstrap.php';
    }
    if (defined('WPMU_PLUGIN_DIR')) {
        $roots[] = WPMU_PLUGIN_DIR . '/arclight-cache/bootstrap.php';
    }
    foreach ($roots as $boot) {
        if (is_string($boot) && file_exists($boot)) {
            require_once $boot;
            break;
        }
    }
}

if (! class_exists('ArclightCache\\Cache\\ArclightObjectCache')) {
    return; // Core missing: let WP fall back to its default in-memory cache.
}

/**
 * Standard drop-in contract (see wp-includes/load.php::wp_start_object_cache):
 * define wp_cache_init() so core marks the external cache as in use and
 * skips loading its own cache.php. The wp_cache_* API functions below
 * proxy to the $wp_object_cache instance built here.
 */
if (! function_exists('wp_cache_init')) :
    function wp_cache_init()
    {
        // Idempotent: the drop-in file calls this eagerly AND core calls it
        // from wp_start_object_cache(). Rebuilding would orphan the first
        // instance — including its metrics and its shutdown sampler.
        if (isset($GLOBALS['wp_object_cache'])
            && $GLOBALS['wp_object_cache'] instanceof ArclightCache\Cache\ObjectCacheInterface
        ) {
            $GLOBALS['ArclightCacheDropin'] = $GLOBALS['wp_object_cache'];
            return;
        }
        $GLOBALS['ArclightCacheDropin'] = arclight_build_cache();
        $GLOBALS['wp_object_cache'] = $GLOBALS['ArclightCacheDropin'];
    }
endif;

if (! function_exists('arclight_build_cache')) :
    /**
     * Build config -> connection -> cache.
     *
     * @return ArclightCache\Cache\ObjectCacheInterface
     */
    function arclight_build_cache()
    {
        return arclight_do_build_cache();
    }
endif;

if (! function_exists('arclight_do_build_cache')) :
    function arclight_do_build_cache()
    {
        $config = function_exists('ArclightCache\\arclight_config') ? ArclightCache\arclight_config() : ArclightCache\Config\Configuration::fromArray(array());
        $connection = ArclightCache\Connection\Connector::createConnection($config);
        $isMultisite = function_exists('is_multisite') ? is_multisite() : false;
        $siteId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $metrics = null;
        $historyPath = arclight_metrics_path($config);
        if ((bool) $config->get('metrics', true)) {
            // SQLite-backed store when available so samples survive the request;
            // falls back to memory-only when pdo_sqlite missing/unwritable.
            $metrics = new ArclightCache\Support\SqliteMetricsStore($historyPath);
        }
        $cache = new ArclightCache\Cache\ArclightObjectCache($connection, $config, array(
            'siteId' => $siteId,
            'isMultisite' => $isMultisite,
            'logger' => new ArclightCache\Support\ErrorLogLogger(),
            'metrics' => $metrics,
            'connection' => $connection,
        ));
        if (! class_exists('Redis')) {
            $cache = new ArclightCache\Cache\ArrayObjectCache(new ArclightCache\Support\ErrorLogLogger());
        }
        arclight_register_sampler($cache, $connection, $historyPath);
        return $cache;
    }
endif;

if (! function_exists('arclight_metrics_path')) :
    // Resolve the SQLite path without WP dependencies (drop-in runs early).
    function arclight_metrics_path($config)
    {
        $p = $config->get('sqlite_path', null);
        if (is_string($p) && $p !== '') {
            return $p;
        }
        if (defined('WP_CONTENT_DIR')) {
            return WP_CONTENT_DIR . '/uploads/arclight-cache/metrics.sqlite';
        }
        return null;
    }
endif;

if (! function_exists('arclight_register_sampler')) :
    // THE stats fix: persist this request's rollup + Redis INFO counters to
    // SQLite on shutdown. The dashboard reads history from here — previously
    // the per-request store died with the request and the dashboard read a
    // newborn instance (always 0 hits).
    function arclight_register_sampler($cache, $connection, $historyPath)
    {
        if (! is_string($historyPath) || $historyPath === '') {
            return;
        }
        if (isset($GLOBALS['ArclightSamplerRegistered'])) {
            return;
        }
        $GLOBALS['ArclightSamplerRegistered'] = true;
        register_shutdown_function(function () use ($cache, $connection, $historyPath) {
            try {
                if (! method_exists($cache, 'stats')) {
                    return;
                }
                $stats = $cache->stats();
                $rollup = isset($stats['metrics']) && is_array($stats['metrics']) ? $stats['metrics'] : array();
                if (empty($rollup) || ((int) (isset($rollup['total']) ? $rollup['total'] : 0)) === 0) {
                    return; // nothing to record (e.g. early-boot requests)
                }
                $server = array();
                try {
                    if (method_exists($connection, 'isAvailable') && $connection->isAvailable()) {
                        $info = $connection->client()->info('stats');
                        if (is_array($info)) {
                            $server = $info;
                        }
                        $mem = $connection->client()->info('memory');
                        if (is_array($mem)) {
                            foreach (array('used_memory_human', 'connected_clients', 'maxmemory_policy') as $k) {
                                if (isset($mem[$k])) {
                                    $server[$k] = $mem[$k];
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Server counters optional; rollup still recorded.
                }
                $history = new ArclightCache\Support\MetricsHistory($historyPath);
                $history->sample($rollup, $server);
            } catch (\Exception $e) {
                // Sampling must never break shutdown.
            }
        });
    }
endif;

// Eagerly build for runtimes that read $wp_object_cache without calling
// wp_cache_init() (some CLI/embedded flows). wp_start_object_cache() will
// call wp_cache_init() again, which rebuilds idempotently.
wp_cache_init();

if (! function_exists('wp_cache_add')) :
    function wp_cache_add($key, $data, $group = '', $expire = 0)
    {
        global $wp_object_cache;
        return $wp_object_cache->add($key, $data, $group === '' ? 'default' : $group, $expire);
    }
endif;
if (! function_exists('wp_cache_get')) :
    function wp_cache_get($key, $group = '', $force = false, &$found = null)
    {
        global $wp_object_cache;
        return $wp_object_cache->get($key, $group === '' ? 'default' : $group, $force, $found);
    }
endif;
if (! function_exists('wp_cache_set')) :
    function wp_cache_set($key, $data, $group = '', $expire = 0)
    {
        global $wp_object_cache;
        return $wp_object_cache->set($key, $data, $group === '' ? 'default' : $group, $expire);
    }
endif;
if (! function_exists('wp_cache_replace')) :
    function wp_cache_replace($key, $data, $group = '', $expire = 0)
    {
        global $wp_object_cache;
        return $wp_object_cache->replace($key, $data, $group === '' ? 'default' : $group, $expire);
    }
endif;
if (! function_exists('wp_cache_delete')) :
    function wp_cache_delete($key, $group = '')
    {
        global $wp_object_cache;
        return $wp_object_cache->delete($key, $group === '' ? 'default' : $group);
    }
endif;
if (! function_exists('wp_cache_incr')) :
    function wp_cache_incr($key, $offset = 1, $group = '')
    {
        global $wp_object_cache;
        return $wp_object_cache->incr($key, $offset, $group === '' ? 'default' : $group);
    }
endif;
if (! function_exists('wp_cache_decr')) :
    function wp_cache_decr($key, $offset = 1, $group = '')
    {
        global $wp_object_cache;
        return $wp_object_cache->decr($key, $offset, $group === '' ? 'default' : $group);
    }
endif;
if (! function_exists('wp_cache_flush')) :
    function wp_cache_flush()
    {
        global $wp_object_cache;
        return $wp_object_cache->flush();
    }
endif;
if (! function_exists('wp_cache_get_multiple')) :
    function wp_cache_get_multiple($keys, $group = '')
    {
        global $wp_object_cache;
        return $wp_object_cache->getMultiple($keys, $group === '' ? 'default' : $group);
    }
endif;

// --- Extended API core expects from an external cache (cf. wp-includes/cache.php).
// Non-persistent + global groups are tracked in the drop-in and registered on
// the cache instance lazily (no-op if the instance lacks the setter).
if (! function_exists('arclight_groups')) :
    function arclight_groups($kind)
    {
        global $wp_object_cache;
        $key = $kind === 'global' ? 'ArclightGlobalGroups' : 'ArclightNonPersistent';
        if (! isset($GLOBALS[$key]) || ! is_array($GLOBALS[$key])) {
            $GLOBALS[$key] = array();
        }
        return $GLOBALS[$key];
    }
endif;
if (! function_exists('wp_cache_add_non_persistent_groups')) :
    function wp_cache_add_non_persistent_groups($groups)
    {
        foreach ((array) $groups as $g) {
            $GLOBALS['ArclightNonPersistent'][(string) $g] = true;
        }
    }
endif;
if (! function_exists('wp_cache_add_global_groups')) :
    function wp_cache_add_global_groups($groups)
    {
        foreach ((array) $groups as $g) {
            $GLOBALS['ArclightGlobalGroups'][(string) $g] = true;
        }
    }
endif;
if (! function_exists('wp_cache_switch_to_blog')) :
    function wp_cache_switch_to_blog($blog_id)
    {
        return true;
    }
endif;
if (! function_exists('wp_cache_reset')) :
    function wp_cache_reset()
    {
        global $wp_object_cache;
        return $wp_object_cache->flush();
    }
endif;
if (! function_exists('wp_cache_close')) :
    function wp_cache_close()
    {
        return true;
    }
endif;
if (! function_exists('wp_cache_supports')) :
    function wp_cache_supports($feature)
    {
        switch ((string) $feature) {
            case 'add_multiple':
            case 'set_multiple':
            case 'get_multiple':
            case 'delete_multiple':
            case 'flush_runtime':
            case 'flush_group':
                return true;
            default:
                return false;
        }
    }
endif;
if (! function_exists('wp_cache_add_multiple')) :
    function wp_cache_add_multiple(array $data, $group = '', $expire = 0)
    {
        $out = array();
        foreach ($data as $k => $v) {
            $out[$k] = wp_cache_add($k, $v, $group, $expire);
        }
        return $out;
    }
endif;
if (! function_exists('wp_cache_set_multiple')) :
    function wp_cache_set_multiple(array $data, $group = '', $expire = 0)
    {
        $out = array();
        foreach ($data as $k => $v) {
            $out[$k] = wp_cache_set($k, $v, $group, $expire);
        }
        return $out;
    }
endif;
if (! function_exists('wp_cache_delete_multiple')) :
    function wp_cache_delete_multiple(array $keys, $group = '')
    {
        $out = array();
        foreach ($keys as $k) {
            $out[$k] = wp_cache_delete($k, $group);
        }
        return $out;
    }
endif;
if (! function_exists('wp_cache_flush_runtime')) :
    function wp_cache_flush_runtime()
    {
        return wp_cache_flush();
    }
endif;
if (! function_exists('wp_cache_flush_group')) :
    function wp_cache_flush_group($group)
    {
        global $wp_object_cache;
        if (method_exists($wp_object_cache, 'flushGroup')) {
            return $wp_object_cache->flushGroup($group);
        }
        return false;
    }
endif;
