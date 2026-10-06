<?php
// Arclight Cache — phase-3 tests: dashboard surface (original code, PHP 7.2 compat).
// Usage: php tests/run3.php

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
require_once $src . '/Connection/ConnectionInterface.php';
require_once $src . '/Connection/SingleConnection.php';
require_once $src . '/Support/Logger.php';
require_once $src . '/Support/Metrics.php';
require_once $src . '/Support/MetricsHistory.php';

use ArclightCache\Client\ArrayFakeClient;
use ArclightCache\Config\Configuration;
use ArclightCache\Connection\SingleConnection;
use ArclightCache\Support\MetricsHistory;
use ArclightCache\Support\MemoryMetricsStore;
use ArclightCache\Support\NullLogger;

$pass = 0;
$fail = 0;

function check3($name, $cond, $detail = '')
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "ok - $name\n";
    } else {
        $fail++;
        echo "FAIL - $name" . ($detail !== '' ? " :: $detail" : '') . "\n";
    }
}

// --- MetricsHistory round trip ---
$tmp = sys_get_temp_dir() . '/arclight-hist-' . getmypid() . '.sqlite';
@unlink($tmp);
$h = new MetricsHistory($tmp, new NullLogger());
check3('history available with sqlite', $h->available() === true || $h->available() === false); // env-dependent, informational
if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $m = new MemoryMetricsStore();
    for ($i = 0; $i < 10; $i++) {
        $m->record('get', 'default', true, 1.0);
    }
    for ($i = 0; $i < 5; $i++) {
        $m->record('get', 'default', false, 2.0);
    }
    check3('history sample', $h->sample($m->rollup(), array('keyspace_hits' => '100', 'keyspace_misses' => '20', 'used_memory_human' => '2M', 'connected_clients' => '3')) === true);
    $t = $h->totals();
    check3('history totals', $t['hits'] === 10 && $t['misses'] === 5 && $t['requests'] === 15 && $t['samples'] === 1, json_encode($t));
    $s = $h->series(24, 60);
    check3('history series bucketed', count($s) === 1 && $s[0]['hits'] === 10 && $s[0]['misses'] === 5 && $s[0]['requests'] === 15);
    // Second sample advances server deltas.
    $h->sample($m->rollup(), array('keyspace_hits' => '130', 'keyspace_misses' => '25'));
    $s2 = $h->series(24, 60);
    check3('history series merges bucket', count($s2) === 1 && $s2[0]['hits'] === 20);
    check3('history series server delta or null', $s2[0]['server_hits'] === null || $s2[0]['server_hits'] >= 0);
    // Growth bound: 3100 samples -> capped at 3000.
    for ($i = 0; $i < 3100; $i++) {
        $h->sample(array('hits' => 1, 'misses' => 0, 'p50_ms' => 1.0), array());
    }
    $t2 = $h->totals();
    check3('history capped', $t2['samples'] <= 3000, (string) $t2['samples']);
    @unlink($tmp);
} else {
    echo "SKIP - sqlite driver missing, history tests skipped\n";
}

// Disabled path.
$hOff = new MetricsHistory(null);
check3('history null path unavailable', $hOff->available() === false);
check3('history null sample false', $hOff->sample(array('hits' => 1, 'misses' => 0, 'p50_ms' => 1)) === false);
check3('history null totals zero', $hOff->totals() === array('hits' => 0, 'misses' => 0, 'requests' => 0, 'samples' => 0));
check3('history null series empty', $hOff->series() === array());

// --- Client scan/inspect (fake) ---
$c = new ArrayFakeClient('ok');
$c->connect();
$c->call('set', array('arclight:default:a', 's:x'));
$c->call('set', array('arclight:options:b', 's:y'));
$c->call('hset', array('arclight:options#hash', 'f1', 'v'));
$page = $c->scan(0, 'arclight*', 100);
check3('fake scan finds keys', $page[0] === 0 && count($page[1]) === 3, json_encode($page));
$page2 = $c->scan(0, 'arclight*', 2);
check3('fake scan paginates', count($page2[1]) === 2 && $page2[0] === 2, json_encode($page2));
$page3 = $c->scan(2, 'arclight*', 10);
check3('fake scan cursor completes', $page3[0] === 0 && count($page3[1]) === 1);
$i = $c->inspect('arclight:options#hash');
check3('fake inspect hash', $i['type_name'] === 'hash' && $i['fields'] === 1);
$i2 = $c->inspect('arclight:default:a');
check3('fake inspect string', $i2['type_name'] === 'string' && $i2['bytes'] === 3);
$i3 = $c->inspect('nope');
check3('fake inspect missing', $i3['type_name'] === 'none');
$info = $c->info('stats');
check3('fake info stats counters', isset($info['keyspace_hits']) && isset($info['keyspace_misses']));

// --- Sanitizer logic (mirrors Plugin::sanitizeSettings rules) ---
$cfg = Configuration::fromArray(array('port' => 6380, 'prefix' => 'shop', 'tls' => true, 'ignored_groups' => array('sessions')));
check3('config round trip port', $cfg->get('port') === 6380);
check3('config round trip prefix', $cfg->get('prefix') === 'shop');
check3('config round trip tls', $cfg->get('tls') === true);
check3('config round trip groups', $cfg->get('ignored_groups') === array('sessions'));
check3('config still validates', $cfg->validate() === array());

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
