<?php
// Arclight Cache — PhpRedis extension client (original code, PHP 7.2 compat).

namespace ArclightCache\Client;

use ArclightCache\Config\Configuration;
use ArclightCache\Connection\ConnectionException;

defined('ABSPATH') || exit;

class PhpRedisClient implements ClientInterface
{
    /** @var Configuration */
    private $config;

    /** @var mixed Holds \Redis when connected, null otherwise. */
    private $redis = null;

    /** @var array */
    private $log = array();

    /** @param Configuration $config */
    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

    public function connect()
    {
        if (! class_exists('Redis')) {
            throw new ConnectionException('phpredis extension missing (class Redis not found)');
        }
        $redis = new \Redis();
        $host = (string) $this->config->get('host', '127.0.0.1');
        $port = (int) $this->config->get('port', 6379);
        $timeout = (float) $this->config->get('timeout', 1.0);
        $readTimeout = (float) $this->config->get('read_timeout', 1.0);
        $tls = (bool) $this->config->get('tls', false);
        if ($tls && strpos($host, 'tls://') !== 0) {
            $host = 'tls://' . $host;
        }
        $ok = @$redis->connect($host, $port, $timeout, null, 0, $readTimeout);
        if (! $ok) {
            throw new ConnectionException('phpredis: unable to connect to ' . $host . ':' . $port);
        }
        $password = $this->config->get('password', null);
        $username = $this->config->get('username', null);
        if (is_string($password) && $password !== '') {
            $auth = $username ? array($username, $password) : $password;
            if (! @$redis->auth($auth)) {
                throw new ConnectionException('phpredis: AUTH failed');
            }
        }
        $db = (int) $this->config->get('database', 0);
        if ($db !== 0 && ! @$redis->select($db)) {
            throw new ConnectionException('phpredis: SELECT db failed');
        }
        $this->redis = $redis;
    }

    public function isConnected()
    {
        if ($this->redis === null) {
            return false;
        }
        try {
            return (bool) @$this->redis->ping();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function call($command, array $args = array())
    {
        if ($this->redis === null) {
            throw new ConnectionException('phpredis: not connected');
        }
        $this->log[] = array($command, $args);
        $command = strtolower((string) $command);
        $r = $this->redis;
        try {
            switch ($command) {
                case 'get':
                    $v = @$r->get(isset($args[0]) ? $args[0] : '');
                    return $v === false ? false : $v;
                case 'set':
                    // Args may carry EX flag: set(key, val, 'EX', ttl).
                    if (isset($args[2]) && $args[2] === 'EX' && isset($args[3])) {
                        return (bool) @$r->set(isset($args[0]) ? $args[0] : '', isset($args[1]) ? $args[1] : null, array('ex' => (int) $args[3]));
                    }
                    return (bool) @$r->set(isset($args[0]) ? $args[0] : '', isset($args[1]) ? $args[1] : null);
                case 'del':
                    return (int) call_user_func_array(array($r, 'del'), $args);
                case 'exists':
                    return (int) @$r->exists(isset($args[0]) ? $args[0] : '');
                case 'incrby':
                    return (int) @$r->incrBy(isset($args[0]) ? $args[0] : '', isset($args[1]) ? (int) $args[1] : 1);
                case 'hget':
                    $v = @$r->hGet(isset($args[0]) ? $args[0] : '', isset($args[1]) ? $args[1] : '');
                    return $v === false ? false : $v;
                case 'hset':
                    return (int) @$r->hSet(isset($args[0]) ? $args[0] : '', isset($args[1]) ? $args[1] : '', isset($args[2]) ? $args[2] : null);
                case 'hgetall':
                    $v = @$r->hGetAll(isset($args[0]) ? $args[0] : '');
                    return is_array($v) ? $v : array();
                case 'ping':
                    return true;
                case 'flushdb':
                    return (bool) @$r->flushDB();
                default:
                    // Generic passthrough via rawCommand for future commands.
                    return call_user_func_array(array($r, 'rawCommand'), array_merge(array($command), $args));
            }
        } catch (\Exception $e) {
            throw new ConnectionException('phpredis: ' . $e->getMessage(), 0, $e);
        }
    }

    public function pipeline(array $calls)
    {
        if ($this->redis === null) {
            throw new ConnectionException('phpredis: not connected');
        }
        $pipe = $this->redis->multi(\Redis::PIPELINE);
        foreach ($calls as $call) {
            $cmd = strtolower(isset($call[0]) ? (string) $call[0] : '');
            $args = isset($call[1]) && is_array($call[1]) ? $call[1] : array();
            // Replay through pipeline handle via __call-style dispatch.
            switch ($cmd) {
                case 'get':
                    $pipe->get(isset($args[0]) ? $args[0] : '');
                    break;
                case 'hget':
                    $pipe->hGet(isset($args[0]) ? $args[0] : '', isset($args[1]) ? $args[1] : '');
                    break;
                default:
                    $pipe->rawCommand($cmd, $args);
                    break;
            }
        }
        $res = $pipe->exec();
        return is_array($res) ? $res : array();
    }

    public function info($section = null)
    {
        if ($this->redis === null) {
            throw new ConnectionException('phpredis: not connected');
        }
        $info = @$this->redis->info($section ? (string) $section : null);
        return is_array($info) ? $info : array();
    }

    public function scan($cursor, $match = '*', $count = 100)
    {
        if ($this->redis === null) {
            throw new ConnectionException('phpredis: not connected');
        }
        $cursor = max(0, (int) $cursor);
        $count = max(1, min(1000, (int) $count));
        // Implemented on rawCommand, not Redis::scan(): the native wrapper
        // misbehaves on some builds (e.g. phpredis 6.3.0 returns false with
        // the iterator stuck at 0). SCAN reply is [nextCursor, keys].
        $res = $this->redis->rawCommand('SCAN', (string) $cursor, 'MATCH', (string) $match, 'COUNT', (string) $count);
        if (! is_array($res) || count($res) < 2 || ! is_array($res[1])) {
            throw new ConnectionException('phpredis: SCAN failed');
        }
        return array((int) $res[0], array_values($res[1]));
    }

    public function inspect($key)
    {
        if ($this->redis === null) {
            throw new ConnectionException('phpredis: not connected');
        }
        $key = (string) $key;
        $type = @$this->redis->type($key);
        $ttl = @$this->redis->ttl($key);
        $out = array('type' => is_int($type) ? $type : -1, 'ttl' => is_int($ttl) ? $ttl : -2);
        // Type names for display: 1 string, 3 list, 4 set, 5 zset, 6 hash (phpredis constants).
        $names = array(0 => 'none', 1 => 'string', 2 => 'set?', 3 => 'list', 4 => 'set', 5 => 'zset', 6 => 'hash');
        $out['type_name'] = isset($names[$out['type']]) ? $names[$out['type']] : ('type' . $out['type'] . '?');
        if ($out['type'] === 6) {
            $out['fields'] = (int) @$this->redis->hLen($key);
        } elseif ($out['type'] === 1) {
            $out['bytes'] = (int) @$this->redis->strlen($key);
        }
        return $out;
    }

    public function close()
    {
        try {
            if ($this->redis !== null) {
                @$this->redis->close();
            }
        } catch (\Exception $e) {
        }
        $this->redis = null;
    }
}
