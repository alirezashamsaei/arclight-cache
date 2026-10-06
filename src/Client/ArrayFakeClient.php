<?php
// Arclight Cache — in-memory fake client for tests + no-extension fallback.
// Original code, PHP 7.2 compat. Fault modes: ok, timeout, auth-fail, flaky.

namespace ArclightCache\Client;

use ArclightCache\Connection\ConnectionException;

defined('ABSPATH') || exit;

if (! class_exists('ArclightCache\\Connection\\ConnectionException', false)) {
    require_once dirname(__DIR__) . '/Connection/ConnectionException.php';
}

class ArrayFakeClient implements ClientInterface
{
    /** @var array */
    private $store = array();

    /** @var array */
    private $hashes = array();

    /** @var bool */
    private $connected = false;

    /** @var string */
    private $mode = 'ok';

    /** @var int */
    private $connectAttempts = 0;

    /** @var int */
    private $pipelineCalls = 0;

    /** @var array */
    private $log = array();

    /** @var string|null */
    private $requirePassword;

    /**
     * @param string $mode ok|timeout|auth-fail|flaky
     * @param string|null $requirePassword When set, connect() demands matching password via setPassword().
     */
    public function __construct($mode = 'ok', $requirePassword = null)
    {
        $this->mode = $mode;
        $this->requirePassword = $requirePassword;
    }

    /** @var string|null */
    private $password = null;

    /** @param string|null $password */
    public function setPassword($password)
    {
        $this->password = $password;
    }

    /** @return int */
    public function connectAttempts()
    {
        return $this->connectAttempts;
    }

    /** @return int */
    public function pipelineCalls()
    {
        return $this->pipelineCalls;
    }

    /** @return array */
    public function commandLog()
    {
        return $this->log;
    }

    public function connect()
    {
        $this->connectAttempts++;
        if ($this->mode === 'timeout') {
            throw new ConnectionException('fake dial timeout');
        }
        if ($this->mode === 'auth-fail') {
            throw new ConnectionException('fake AUTH failed');
        }
        if ($this->mode === 'flaky' && $this->connectAttempts < 3) {
            throw new ConnectionException('fake flaky dial attempt ' . $this->connectAttempts);
        }
        if ($this->requirePassword !== null && $this->password !== $this->requirePassword) {
            throw new ConnectionException('fake AUTH failed');
        }
        $this->connected = true;
    }

    public function isConnected()
    {
        return $this->connected;
    }

    public function call($command, array $args = array())
    {
        $this->log[] = array($command, $args);
        $command = strtolower((string) $command);
        switch ($command) {
            case 'get':
                $k = isset($args[0]) ? (string) $args[0] : '';
                if (array_key_exists($k, $this->store)) {
                    $this->fakeHits++;
                    return $this->store[$k];
                }
                $this->fakeMisses++;
                return false;
            case 'set':
                $k = isset($args[0]) ? (string) $args[0] : '';
                $this->store[$k] = isset($args[1]) ? $args[1] : null;
                return true;
            case 'del':
                $n = 0;
                foreach ($args as $k) {
                    $k = (string) $k;
                    if (array_key_exists($k, $this->store)) {
                        unset($this->store[$k]);
                        $n++;
                    }
                }
                return $n;
            case 'exists':
                $k = isset($args[0]) ? (string) $args[0] : '';
                return array_key_exists($k, $this->store) ? 1 : 0;
            case 'incrby':
                $k = isset($args[0]) ? (string) $args[0] : '';
                $by = isset($args[1]) ? (int) $args[1] : 1;
                $cur = array_key_exists($k, $this->store) ? (int) $this->store[$k] : 0;
                $cur += $by;
                $this->store[$k] = $cur;
                return $cur;
            case 'hget':
                $h = isset($args[0]) ? (string) $args[0] : '';
                $f = isset($args[1]) ? (string) $args[1] : '';
                return isset($this->hashes[$h][$f]) ? $this->hashes[$h][$f] : false;
            case 'hset':
                $h = isset($args[0]) ? (string) $args[0] : '';
                $f = isset($args[1]) ? (string) $args[1] : '';
                if (! isset($this->hashes[$h])) {
                    $this->hashes[$h] = array();
                }
                $isNew = ! array_key_exists($f, $this->hashes[$h]);
                $this->hashes[$h][$f] = isset($args[2]) ? $args[2] : null;
                return $isNew ? 1 : 0;
            case 'hgetall':
                $h = isset($args[0]) ? (string) $args[0] : '';
                return isset($this->hashes[$h]) ? $this->hashes[$h] : array();
            case 'ping':
                return true;
            case 'flushdb':
                $this->store = array();
                $this->hashes = array();
                return true;
            default:
                throw new ConnectionException('fake: unknown command ' . $command);
        }
    }

    public function pipeline(array $calls)
    {
        $this->pipelineCalls++;
        $out = array();
        foreach ($calls as $call) {
            $cmd = isset($call[0]) ? $call[0] : '';
            $args = isset($call[1]) && is_array($call[1]) ? $call[1] : array();
            $out[] = $this->call($cmd, $args);
        }
        return $out;
    }

    public function info($section = null)
    {
        return array(
            'used_memory_human' => '1.00M',
            'maxmemory_policy' => 'allkeys-lru',
            'mem_fragmentation_ratio' => '1.10',
            'redis_version' => '7.2.0-fake',
            'keyspace_hits' => (string) $this->fakeHits,
            'keyspace_misses' => (string) $this->fakeMisses,
            'connected_clients' => '1',
        );
    }

    /** @var int */
    private $fakeHits = 0;

    /** @var int */
    private $fakeMisses = 0;

    public function scan($cursor, $match = '*', $count = 100)
    {
        $all = array_merge(array_keys($this->store), array_keys($this->hashes));
        $rx = $this->globToRegex((string) $match);
        $matched = array();
        foreach ($all as $k) {
            if (preg_match($rx, $k)) {
                $matched[] = $k;
            }
        }
        sort($matched);
        $count = max(1, min(1000, (int) $count));
        $offset = max(0, (int) $cursor);
        $page = array_slice($matched, $offset, $count);
        $next = $offset + count($page) >= count($matched) ? 0 : $offset + count($page);
        return array($next, $page);
    }

    public function inspect($key)
    {
        $key = (string) $key;
        if (isset($this->hashes[$key])) {
            return array('type' => 6, 'type_name' => 'hash', 'ttl' => -1, 'fields' => count($this->hashes[$key]));
        }
        if (array_key_exists($key, $this->store)) {
            $v = $this->store[$key];
            return array('type' => 1, 'type_name' => 'string', 'ttl' => -1, 'bytes' => strlen((string) $v));
        }
        return array('type' => 0, 'type_name' => 'none', 'ttl' => -2);
    }

    /** @param string $glob @return string */
    private function globToRegex($glob)
    {
        $out = '';
        $len = strlen($glob);
        for ($i = 0; $i < $len; $i++) {
            $c = $glob[$i];
            if ($c === '*') {
                $out .= '.*';
            } elseif ($c === '?') {
                $out .= '.';
            } else {
                $out .= preg_quote($c, '/');
            }
        }
        return '/^' . $out . '$/';
    }

    public function close()
    {
        $this->connected = false;
    }
}
