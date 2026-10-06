<?php
/**
 * Plugin Name: Arclight Cache
 * Description: Original Redis object cache with doctor, CLI, dashboard and Query Monitor bridge.
 * Version: 0.2.0
 * Requires PHP: 7.2
 * License: MIT
 */

namespace ArclightCache;

defined('ABSPATH') || exit;

if (version_compare(PHP_VERSION, '7.2', '<')) {
    return;
}

if (defined('ArclightCache\\VERSION')) {
    return;
}

define('ArclightCache\\VERSION', '0.2.0');
define('ArclightCache\\FILE', __FILE__);
define('ArclightCache\\DIR', __DIR__);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/api.php';

// Eagerly load the core chain on every runtime (web + CLI): the autoloader
// covers lazy loads, but Plugin::boot() needs these present before any
// autoload trigger fires in some load orders. Console/Commands.php extends
// WP_CLI_Command, so it loads under WP-CLI only.
require_once __DIR__ . '/src/Config/Configuration.php';
require_once __DIR__ . '/src/Connection/ConnectionException.php';
require_once __DIR__ . '/src/Client/ClientInterface.php';
require_once __DIR__ . '/src/Client/ArrayFakeClient.php';
require_once __DIR__ . '/src/Client/PhpRedisClient.php';
require_once __DIR__ . '/src/Connection/ConnectionInterface.php';
require_once __DIR__ . '/src/Connection/SingleConnection.php';
require_once __DIR__ . '/src/Connection/Connector.php';
require_once __DIR__ . '/src/Support/Logger.php';
require_once __DIR__ . '/src/Support/Metrics.php';
require_once __DIR__ . '/src/Cache/ObjectCacheInterface.php';
require_once __DIR__ . '/src/Cache/KeyBuilder.php';
require_once __DIR__ . '/src/Cache/ArclightObjectCache.php';
require_once __DIR__ . '/src/Cache/ArrayObjectCache.php';
require_once __DIR__ . '/src/Tools/Doctor.php';
require_once __DIR__ . '/src/Plugin.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/src/Console/Commands.php';
}

add_action('plugins_loaded', function () {
    $GLOBALS['ArclightCache'] = Plugin::boot();

    if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
        \WP_CLI::add_command('arclight', 'ArclightCache\\Console\\Commands');
    }
});

register_activation_hook(__FILE__, function () {
    // Remind admin to define config before enabling the drop-in.
    if (! defined('WP_CLI') || ! WP_CLI) {
        set_transient('arclight_cache_activated', wp_create_nonce('arclight-activated'), 30);
    }
});
