<?php
// Arclight Cache — zero-dependency test runner (original code, PHP 7.2 compat).
// Usage: php tests/run.php

error_reporting(E_ALL);

$root = dirname(__DIR__);
$src = $root . '/src';

// Minimal ABSPATH shim so src files load outside WordPress.
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
require_once $src . '/Cache/ObjectCacheInterface.php';
require_once $src . '/Cache/KeyBuilder.php';
require_once $src . '/Cache/ArclightObjectCache.php';
require_once $src . '/Tools/Doctor.php';

use ArclightCache\Cache\ArclightObjectCache;
use ArclightCache\Cache\KeyBuilder;
use ArclightCache\Client\ArrayFakeClient;
use ArclightCache\Config\Configuration;
use ArclightCache\Connection\SingleConnection;
use ArclightCache\Support\ArrayLogger;
use ArclightCache\Support\MemoryMetricsStore;
use ArclightCache\Support\NullLogger;
use ArclightCache\Support\SqliteMetricsStore;
use ArclightCache\Tools\Doctor;

$pass = 0;
$fail = 0;
$failures = array();

function check($name, $cond, $detail = '')
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

function makeCache($mode = 'ok', array $cfg = array(), array $args = array())
{
    $config = Configuration::fromArray(array_merge(array('prefix' => 't'), $cfg));
    $client = new ArrayFakeClient($mode);
    $sleeps = array();
    $conn = new SingleConnection($client, $config, function ($us) use (&$sleeps) {
        $sleeps[] = $us;
    });
    if (! isset($args['logger'])) {
        $args['logger'] = new ArrayLogger();
    }
    if (! array_key_exists('metrics', $args)) {
        $args['metrics'] = new MemoryMetricsStore();
    }
    $cache = new ArclightObjectCache($conn, $config, $args);
    return array($cache, $client, $conn, $config, $args['logger'], $args['metrics'], &$sleeps);
}

// --- Config ---
$c = Configuration::fromArray(array());
check('config defaults valid', $c->validate() === array());
check('config default host', $c->get('host') === '127.0.0.1');
check('config default port', $c->get('port') === 6379);

$bad = Configuration::fromArray(array('port' => 99999, 'prefix' => 'bad prefix!', 'retries' => 9, 'timeout' => 99, 'tls' => 'yes'));
$errs = $bad->validate();
check('config invalid port flagged', (bool) array_filter($errs, function ($e) { return strpos($e, 'port:') === 0; }));
check('config invalid prefix flagged', (bool) array_filter($errs, function ($e) { return strpos($e, 'prefix:') === 0; }));
check('config invalid retries flagged', (bool) array_filter($errs, function ($e) { return strpos($e, 'retries:') === 0; }));
check('config invalid timeout flagged', (bool) array_filter($errs, function ($e) { return strpos($e, 'timeout:') === 0; }));
check('config invalid tls flagged', (bool) array_filter($errs, function ($e) { return strpos($e, 'tls:') === 0; }));

$unk = Configuration::fromArray(array('future_knob' => 1));
check('config unknown keys surfaced', $unk->unknownKeys() === array('future_knob'));
check('config unknown keys still valid', $unk->validate() === array());

$host = Configuration::fromArray(array('host' => 'tcp://10.0.0.5/0'));
check('config host scheme stripped', $host->get('host') === '10.0.0.5');

// --- KeyBuilder ---
$kb = new KeyBuilder('ac', 3, true);
check('keybuilder site key', $kb->build('k', 'g') === 'ac:site3:g:k');
check('keybuilder global key', $kb->build('k', 'g', true) === 'ac:network:g:k');
$kb2 = new KeyBuilder('ac', 1, false);
check('keybuilder single site no segment', $kb2->build('k', 'g') === 'ac:g:k');
check('keybuilder hash key', $kb->hashKey('options') === 'ac:site3:options#hash');
$threw = false;
try {
    $kb->build('', 'g');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('keybuilder empty key rejected', $threw);
$threw = false;
try {
    $kb->build("a\0b", 'g');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('keybuilder NUL rejected', $threw);

// --- Integration vs fake ---
list($cache) = makeCache();
check('set ok', $cache->set('a', 'hello', 'default') === true);
check('get hit', $cache->get('a', 'default') === 'hello');
$found = null;
$cache->get('a', 'default', false, $found);
check('get found flag', $found === true);
check('get miss', $cache->get('nope', 'default') === false);
check('add new', $cache->add('n1', 'v', 'default') === true);
check('add existing false', $cache->add('n1', 'v2', 'default') === false);
check('replace existing', $cache->replace('n1', 'v3', 'default') === true);
check('replace missing false', $cache->replace('missing', 'v', 'default') === false);
check('delete ok', $cache->delete('n1', 'default') === true);
check('object round trip', (function () use ($cache) {
    $o = new stdClass();
    $o->x = array(1, 2, 3);
    $cache->set('obj', $o, 'default');
    $got = $cache->get('obj', 'default');
    return $got instanceof stdClass && $got->x === array(1, 2, 3);
})());
check('incr from zero', $cache->incr('cnt', 2, 'default') === 2);
check('incr again', $cache->incr('cnt', 3, 'default') === 5);
check('decr clamps zero', $cache->decr('cnt', 99, 'default') === 0);
check('persistent true', $cache->isPersistent() === true);

// ignored groups bypass redis
list($cacheI, $clientI) = makeCache('ok', array('ignored_groups' => array('skip')));
$cacheI->set('k', 'v', 'skip');
$logs = array_filter($clientI->commandLog(), function ($l) { return $l[0] === 'set'; });
check('ignored group bypasses redis', count($logs) === 0);
check('ignored group memory hit', $cacheI->get('k', 'skip') === 'v');

// split hash group uses hset/hget
list($cacheS, $clientS) = makeCache('ok', array('split_alloptions' => true));
$cacheS->set('opt1', 'val1', 'options');
$cmds = array_map(function ($l) { return $l[0]; }, $clientS->commandLog());
check('split group uses hset', in_array('hset', $cmds, true));
$cacheS2fresh = null;
{
    $config = Configuration::fromArray(array('prefix' => 't', 'split_alloptions' => true));
    $conn = new SingleConnection($clientS, $config);
    $cacheS2fresh = new ArclightObjectCache($conn, $config, array('logger' => new NullLogger(), 'metrics' => null));
}
check('split group hget round trip', $cacheS2fresh->get('opt1', 'options', true) === 'val1');

// prefetch pipelines
list($cacheP, $clientP) = makeCache();
$cacheP->set('p1', 'a', 'default');
$cacheP->set('p2', 'b', 'default');
$before = $clientP->pipelineCalls();
$res = $cacheP->prefetch(array('p1', 'p2', 'p3'), 'default');
check('prefetch one pipeline', $clientP->pipelineCalls() === $before + 1);
check('prefetch hits', $res['p1'] === 'a' && $res['p2'] === 'b' && $res['p3'] === false);

// get_multiple
$multi = $cacheP->getMultiple(array('p1', 'nope'), 'default');
check('get_multiple shape', $multi['p1'] === 'a' && $multi['nope'] === false);

// multisite isolation
list($cacheA) = makeCache('ok', array('prefix' => 'ms'), array('siteId' => 1, 'isMultisite' => true, 'logger' => new NullLogger(), 'metrics' => null));
list($cacheB, $clientB) = makeCache('ok', array('prefix' => 'ms'), array('siteId' => 2, 'isMultisite' => true, 'logger' => new NullLogger(), 'metrics' => null));
$cacheA->set('shared', 'one', 'default');
// different site must miss (separate memory + separate key namespace on shared client not asserted; memory isolation asserted)
check('multisite memory isolation', $cacheB->get('shared', 'default') === false);

// flush
$cacheP->flush();
check('flush clears memory', $cacheP->get('p1', 'default', true) === false);

// serialization guards
check('closure refused', $cacheP->set('cl', function () {}, 'default') === false);
$rh = fopen('php://memory', 'r');
check('resource refused', $cacheP->set('rs', $rh, 'default') === false);
fclose($rh);

// --- Fault injection ---
list($cacheT, $clientT, $connT, $cfgT, $logT) = makeCache('timeout', array('retries' => 2, 'backoff_ms' => 0));
check('timeout falls back non-persistent', $cacheT->isPersistent() === false);
check('timeout attempts = 1+retries', $connT->attempts() === 3);
check('timeout warns once', $logT->countFor('warning', 'non-persistent') === 1);
check('fallback still serves memory', (function () use ($cacheT) {
    $cacheT->set('m', 'v', 'default');
    return $cacheT->get('m', 'default') === 'v';
})());

list($cacheF, $clientF, $connF) = makeCache('flaky', array('retries' => 5, 'backoff_ms' => 0));
check('flaky recovers', $cacheF->isPersistent() === true);
check('flaky took 3 attempts', $connF->attempts() === 3);

// backoff sleeps recorded with jitter bounds
$sleeps = null;
$config = Configuration::fromArray(array('prefix' => 't', 'retries' => 2, 'backoff_ms' => 100));
$client = new ArrayFakeClient('timeout');
$conn = new SingleConnection($client, $config, function ($us) use (&$sleeps) {
    $sleeps[] = $us;
});
try {
    $conn->ensure();
} catch (Exception $e) {
}
check('backoff sleeps twice', is_array($sleeps) && count($sleeps) === 2);
$okBounds = is_array($sleeps) && $sleeps[0] <= 100 * 1000 && $sleeps[1] <= 200 * 1000;
check('backoff jitter bounds', $okBounds);

// --- Metrics ---
$m = new MemoryMetricsStore();
for ($i = 0; $i < 70; $i++) {
    $m->record('get', 'default', true, 1.0);
}
for ($i = 0; $i < 30; $i++) {
    $m->record('get', 'default', false, 2.0);
}
$r = $m->rollup();
check('metrics hit_rate', $r['hits'] === 70 && $r['misses'] === 30 && $r['hit_rate'] === 0.7);
check('metrics p50 numeric', is_numeric($r['p50_ms']));

$tmpSqlite = sys_get_temp_dir() . '/arclight-test-' . getmypid() . '.sqlite';
@unlink($tmpSqlite);
$sm = new SqliteMetricsStore($tmpSqlite, new NullLogger());
$sm->record('get', 'default', true, 1.5);
check('sqlite persist', $sm->persist() === true && file_exists($tmpSqlite));
@unlink($tmpSqlite);
$blockerFile = sys_get_temp_dir() . '/arclight-blocker-' . getmypid();
@file_put_contents($blockerFile, 'x'); // a FILE where a directory must be: mkdir+open both fail
$smBad = new SqliteMetricsStore($blockerFile . '/m.sqlite', new ArrayLogger());
check('sqlite unwritable drops gracefully', $smBad->persist() === false);
@unlink($blockerFile);

// --- Doctor ---
$config = Configuration::fromArray(array('prefix' => 't'));
$docClient = new ArrayFakeClient('ok');
$docConn = new SingleConnection($docClient, $config);
$doc = new Doctor($docConn, $config);
$rep = $doc->run();
check('doctor pass', $rep['status'] === 'pass');
check('doctor checks shape', isset($rep['checks']) && count($rep['checks']) >= 6);
check('doctor no password leak', strpos(json_encode($rep), 'secret') === false);

$configBad = Configuration::fromArray(array('port' => 0));
$docClient2 = new ArrayFakeClient('auth-fail');
$docConn2 = new SingleConnection($docClient2, $configBad);
$doc2 = new Doctor($docConn2, $configBad);
$rep2 = $doc2->run();
check('doctor fail status', $rep2['status'] === 'fail');
$names = array_map(function ($c) { return $c['name']; }, $rep2['checks']);
check('doctor fail names', in_array('config', $names, true) && in_array('connect', $names, true));

// --- Compat audits (PHP 7.2 syntax surface) ---
$files = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
foreach ($it as $f) {
    if (substr($f->getPathname(), -4) === '.php') {
        $files[] = $f->getPathname();
    }
}
$badPatterns = array('arrow fn(' => '/[^a-zA-Z]fn\s*\(/', 'nullsafe' => '/\?->/', 'match expr' => '/[^a-zA-Z_]match\s*\(/', 'enum ' => '/^enum\s/m', 'typed prop' => '/(private|protected|public)\s+(int|string|bool|float|array|callable|iterable|object)\s+\$/');
$violations = array();
foreach ($files as $path) {
    $code = file_get_contents($path);
    foreach ($badPatterns as $label => $rx) {
        if (preg_match($rx, $code)) {
            // 'match(' appears in comments legitimately; only flag code lines. Simple heuristic: strip comments.
            $stripped = preg_replace('#//.*$#m', '', $code);
            $stripped = preg_replace('#/\*.*?\*/#s', '', $stripped);
            if (preg_match($rx, $stripped)) {
                $violations[] = basename($path) . ':' . $label;
            }
            break;
        }
    }
    if (strpos($code, 'RedisCachePro') !== false) {
        $violations[] = basename($path) . ':reference-leak';
    }
}
check('php72 compat audit', count($violations) === 0, implode(',', $violations));

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
