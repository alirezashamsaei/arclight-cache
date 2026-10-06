<?php
// Arclight Cache — phase-2 tests: new layers (original code, PHP 7.2 compat).
// Usage: php tests/run2.php

error_reporting(E_ALL);

$root = dirname(__DIR__);
$src = $root . '/src';

if (! defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}

require_once $src . '/Config/Configuration.php';
require_once $src . '/Connection/ConnectionException.php';
require_once $src . '/Client/ClientInterface.php';
require_once $src . '/Client/ArrayFakeClient.php';
require_once $src . '/Client/PhpRedisClient.php';
require_once $src . '/Connection/ConnectionInterface.php';
require_once $src . '/Connection/SingleConnection.php';
require_once $src . '/Connection/Connector.php';
require_once $src . '/Support/Logger.php';
require_once $src . '/Support/Metrics.php';
require_once $src . '/Cache/ObjectCacheInterface.php';
require_once $src . '/Cache/KeyBuilder.php';
require_once $src . '/Cache/ArclightObjectCache.php';
require_once $src . '/Cache/ArrayObjectCache.php';
require_once $src . '/Bridge/QueryMonitor/Collector.php';

use ArclightCache\Bridge\QueryMonitor\Collector;
use ArclightCache\Cache\ArrayObjectCache;
use ArclightCache\Client\ArrayFakeClient;
use ArclightCache\Client\PhpRedisClient;
use ArclightCache\Config\Configuration;
use ArclightCache\Connection\Connector;
use ArclightCache\Support\ArrayLogger;
use ArclightCache\Support\NullLogger;

$pass = 0;
$fail = 0;
$failures = array();

function check2($name, $cond, $detail = '')
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
        echo "ok - $name\n";
    } else {
        $fail++;
        $failures[] = $name . ($detail !== '' ? " :: $detail" : '');
        echo "FAIL - $name" . ($detail !== '' ? " :: $detail" : '') . "\n";
    }
}

// Connector: no Redis ext here -> fake client.
$config = Configuration::fromArray(array('prefix' => 't'));
$client = Connector::createClient($config);
check2('connector falls back without ext', $client instanceof ArrayFakeClient);
$conn = Connector::createConnection($config);
check2('connector builds connection', $conn instanceof ArclightCache\Connection\SingleConnection);

// PhpRedisClient without extension throws ConnectionException, never fatal.
$pr = new PhpRedisClient($config);
$threw = false;
$rightClass = false;
try {
    $pr->connect();
} catch (ArclightCache\Connection\ConnectionException $e) {
    $threw = true;
    $rightClass = (strpos($e->getMessage(), 'phpredis') !== false);
} catch (Exception $e) {
    $threw = true;
}
check2('phpredis client missing-ext throws typed', $threw && $rightClass);

// ArrayObjectCache: full WP surface, non-persistent.
$a = new ArrayObjectCache(new NullLogger());
check2('array cache non-persistent', $a->isPersistent() === false);
check2('array set/get', $a->set('k', 'v', 'g') && $a->get('k', 'g') === 'v');
check2('array add/replace', $a->add('n', 1, 'g') && ! $a->add('n', 2, 'g') && $a->replace('n', 3, 'g'));
// 'n' is 3 from add/replace above; separate counter 'c' starts at 0.
check2('array incr/decr', $a->incr('c', 2, 'g') === 2 && $a->decr('c', 10, 'g') === 0);
check2('array closure refused', $a->set('cl', function () {}, 'g') === false);
check2('array flush', $a->flush() && $a->get('k', 'g') === false);
check2('array prefetch', $a->set('x', 1, 'g') && $a->prefetch(array('x', 'y'), 'g') === array('x' => 1, 'y' => false));

// QM collector.
$qm = new Collector();
$qm->record('get', 'default', true, 1.0);
$qm->record('get', 'default', false, 2.0);
$s = $qm->summary();
check2('qm summary', $s['hits'] === 1 && $s['misses'] === 1 && $s['hit_rate'] === 0.5 && count($s['entries']) === 2);

// Drop-in + plugin entry lint already covered by php -l; assert key symbols loadable.
check2('stubs exist', file_exists($root . '/stubs/object-cache.php'));
check2('plugin entry exists', file_exists($root . '/arclight-cache.php'));
check2('bootstrap exists', file_exists($root . '/bootstrap.php'));
check2('api exists', file_exists($root . '/api.php'));
check2('console exists', file_exists($src . '/Console/Commands.php'));
check2('plugin class exists', file_exists($src . '/Plugin.php'));
check2('templates exist', file_exists($root . '/templates/pages/dashboard.phtml') && file_exists($root . '/templates/pages/tools.phtml') && file_exists($root . '/templates/widgets/overview.phtml'));
check2('lua scripts exist', file_exists($root . '/server/scan.lua') && file_exists($root . '/server/keys.lua'));

// Compat audit over ALL new php (src+stubs+api+bootstrap+plugin file).
$files = array(
    $root . '/arclight-cache.php',
    $root . '/bootstrap.php',
    $root . '/api.php',
    $root . '/stubs/object-cache.php',
);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
foreach ($it as $f) {
    if (substr($f->getPathname(), -4) === '.php') {
        $files[] = $f->getPathname();
    }
}
$violations = array();
foreach ($files as $path) {
    $code = file_get_contents($path);
    if (strpos($code, 'RedisCachePro') !== false) {
        $violations[] = basename($path) . ':reference-leak';
        continue;
    }
    if (strpos($code, 'ArclightCache') === false && strpos($path, 'object-cache.php') === false) {
        $violations[] = basename($path) . ':namespace';
    }
    $stripped = preg_replace('#//.*$#m', '', $code);
    $stripped = preg_replace('#/\*.*?\*/#s', '', $stripped);
    foreach (array('arrow' => '/[^a-zA-Z]fn\s*\(/', 'nullsafe' => '/\?->/', 'match' => '/[^a-zA-Z_]match\s*\(/') as $label => $rx) {
        if (preg_match($rx, $stripped)) {
            $violations[] = basename($path) . ':' . $label;
        }
    }
    // Nullable ?Type hints are 7.1+ so allowed; typed properties are not.
    if (preg_match('/(private|protected|public)\s+(int|string|bool|float|array|callable|iterable|object)\s+\$/', $stripped)) {
        $violations[] = basename($path) . ':typed-prop';
    }
}
check2('phase2 compat+namespace audit', count($violations) === 0, implode(',', $violations));

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
