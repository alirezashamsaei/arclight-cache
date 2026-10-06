<?php
// Arclight Cache — persistent two-tier object cache (original code, PHP 7.2 compat).

namespace ArclightCache\Cache;

use ArclightCache\Client\ClientInterface;
use ArclightCache\Config\Configuration;
use ArclightCache\Connection\ConnectionInterface;
use ArclightCache\Support\LoggerInterface;
use ArclightCache\Support\MetricsStoreInterface;
use ArclightCache\Support\NullLogger;

defined('ABSPATH') || exit;

if (! class_exists('ArclightCache\\Support\\NullLogger', false)) {
    require_once dirname(__DIR__) . '/Support/Logger.php';
}

class ArclightObjectCache implements ObjectCacheInterface
{
    /** @var ConnectionInterface */
    protected $connection;

    /** @var Configuration */
    protected $config;

    /** @var KeyBuilder */
    protected $keys;

    /** @var LoggerInterface */
    protected $log;

    /** @var MetricsStoreInterface|null */
    protected $metrics;

    /** @var array In-memory tier: builtKey => value */
    protected $memory = array();

    /** @var array Groups that bypass Redis entirely */
    protected $ignoredGroups = array();

    /** @var array Groups kept memory-only (non-persistent) */
    protected $nonPersistent = array('counts', 'plugins');

    /** @var bool */
    protected $persistent = false;

    /** @var int */
    protected $siteId = 1;

    /** @var bool */
    protected $isMultisite = false;

    /** @var string Group stored as a single hash when enabled */
    protected $splitGroup = 'options';

    /** @var bool Warned-once flags */
    protected $warnedFallback = false;

    /**
     * @param ConnectionInterface $connection
     * @param Configuration $config
     * @param array $args Optional: siteId, isMultisite, logger, metrics.
     */
    public function __construct(ConnectionInterface $connection, Configuration $config, array $args = array())
    {
        $this->connection = $connection;
        $this->config = $config;
        $this->siteId = isset($args['siteId']) ? (int) $args['siteId'] : 1;
        $this->isMultisite = isset($args['isMultisite']) ? (bool) $args['isMultisite'] : false;
        $this->keys = new KeyBuilder((string) $config->get('prefix', 'arclight'), $this->siteId, $this->isMultisite);
        $this->log = isset($args['logger']) ? $args['logger'] : new NullLogger();
        $this->metrics = isset($args['metrics']) ? $args['metrics'] : null;
        $ignored = $config->get('ignored_groups', array());
        $this->ignoredGroups = is_array($ignored) ? array_values(array_filter($ignored, 'is_string')) : array();

        try {
            $connection->ensure();
            $this->persistent = $connection->isAvailable();
        } catch (\Exception $e) {
            $this->persistent = false;
            $this->warnFallback($e->getMessage());
        }
    }

    public function isPersistent()
    {
        return $this->persistent;
    }

    /** @return ConnectionInterface Live connection (for INFO/SCAN from admin). */
    public function connection()
    {
        return $this->connection;
    }

    /** @return \ArclightCache\Config\Configuration */
    public function config()
    {
        return $this->config;
    }

    public function add($key, $value, $group = 'default', $expire = 0)
    {
        if ($this->get($key, $group, false) !== false) {
            return false;
        }
        return $this->set($key, $value, $group, $expire);
    }

    public function get($key, $group = 'default', $force = false, &$found = null)
    {
        $group = $this->normalizeGroup($group);
        $start = microtime(true);
        $hit = false;
        $value = false;

        if (! $force && array_key_exists($this->memoryKey($key, $group), $this->memory)) {
            $value = $this->memory[$this->memoryKey($key, $group)];
            $hit = true;
        } elseif ($this->useRedis($group)) {
            $raw = $this->redisGet($key, $group);
            if ($raw !== false) {
                $value = $this->decode($raw);
                $this->memory[$this->memoryKey($key, $group)] = $value;
                $hit = true;
            }
        } elseif (array_key_exists($this->memoryKey($key, $group), $this->memory)) {
            $value = $this->memory[$this->memoryKey($key, $group)];
            $hit = true;
        }

        $found = $hit;
        $this->record('get', $group, $hit, $start);
        return $hit ? $value : false;
    }

    public function set($key, $value, $group = 'default', $expire = 0)
    {
        $group = $this->normalizeGroup($group);
        if (! $this->serializable($value)) {
            $this->log->log('warning', 'arclight: refusing to cache unserializable value', array('group' => $group));
            return false;
        }
        $this->memory[$this->memoryKey($key, $group)] = $value;
        $start = microtime(true);
        $ok = true;
        if ($this->useRedis($group)) {
            $ok = $this->redisSet($key, $value, $group, (int) $expire);
        }
        $this->record('set', $group, $ok, $start);
        return $ok;
    }

    public function replace($key, $value, $group = 'default', $expire = 0)
    {
        if ($this->get($key, $group, false) === false) {
            return false;
        }
        return $this->set($key, $value, $group, $expire);
    }

    public function delete($key, $group = 'default')
    {
        $group = $this->normalizeGroup($group);
        unset($this->memory[$this->memoryKey($key, $group)]);
        $start = microtime(true);
        $ok = true;
        if ($this->useRedis($group)) {
            try {
                $this->client()->call('del', array($this->keys->build($key, $group)));
            } catch (\Exception $e) {
                $ok = false;
                $this->warnFallback($e->getMessage());
            }
        }
        $this->record('delete', $group, $ok, $start);
        return $ok;
    }

    public function incr($key, $offset = 1, $group = 'default')
    {
        return $this->incrDecr($key, $offset, $group, 'incrby');
    }

    public function decr($key, $offset = 1, $group = 'default')
    {
        return $this->incrDecr($key, -$offset, $group, 'incrby');
    }

    public function flush($siteId = null)
    {
        $this->memory = array();
        if (! $this->persistent) {
            return true;
        }
        // v1 single-node: flush DB. Multisite-aware keyspaces arrive with
        // future topology work; flushSite keeps memory-scope separation only.
        try {
            if ($siteId === null || (int) $siteId === $this->siteId) {
                $this->client()->call('flushdb', array());
            }
            return true;
        } catch (\Exception $e) {
            $this->warnFallback($e->getMessage());
            return false;
        }
    }

    public function flushSite($siteId)
    {
        if ((int) $siteId === $this->siteId) {
            return $this->flush((int) $siteId);
        }
        // Other sites' memory is not ours; Redis-side namespacing keeps isolation.
        return true;
    }

    public function flushNetwork()
    {
        return $this->flush();
    }

    public function getMultiple(array $keys, $group = 'default')
    {
        $out = array();
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $group, false);
        }
        return $out;
    }

    /**
     * Batched prefetch: one pipeline round trip for $keys, warms memory tier.
     *
     * @param string[] $keys
     * @param string $group
     * @return array key => value|false
     */
    public function prefetch(array $keys, $group = 'default')
    {
        $group = $this->normalizeGroup($group);
        $out = array();
        if (! $this->useRedis($group)) {
            foreach ($keys as $key) {
                $out[$key] = $this->get($key, $group, false);
            }
            return $out;
        }

        $calls = array();
        $split = $this->useSplitHash($group);
        foreach ($keys as $key) {
            if ($split) {
                $calls[] = array('hget', array($this->keys->hashKey($group), (string) $key));
            } else {
                $calls[] = array('get', array($this->keys->build($key, $group)));
            }
        }

        $start = microtime(true);
        try {
            $responses = $this->client()->pipeline($calls);
        } catch (\Exception $e) {
            $this->warnFallback($e->getMessage());
            foreach ($keys as $key) {
                $out[$key] = false;
            }
            return $out;
        }

        foreach ($keys as $i => $key) {
            $raw = isset($responses[$i]) ? $responses[$i] : false;
            if ($raw === false) {
                $out[$key] = false;
                $this->record('get', $group, false, $start);
                continue;
            }
            $value = $this->decode($raw);
            $this->memory[$this->memoryKey($key, $group)] = $value;
            $out[$key] = $value;
            $this->record('get', $group, true, $start);
        }
        return $out;
    }

    public function stats()
    {
        $stats = array(
            'persistent' => $this->persistent,
            'memory_keys' => count($this->memory),
            'attempts' => $this->connection->attempts(),
        );
        if ($this->metrics !== null && method_exists($this->metrics, 'rollup')) {
            $stats['metrics'] = $this->metrics->rollup();
        }
        return $stats;
    }

    // -- internals ---------------------------------------------------------

    /** @return ClientInterface */
    protected function client()
    {
        return $this->connection->client();
    }

    /** @param string $group */
    protected function normalizeGroup($group)
    {
        $group = (string) $group;
        return $group === '' ? 'default' : $group;
    }

    /** @param string $key @param string $group */
    protected function memoryKey($key, $group)
    {
        return $group . "\0" . (string) $key;
    }

    /** @param string $group */
    protected function useRedis($group)
    {
        if (! $this->persistent) {
            return false;
        }
        if (in_array($group, $this->ignoredGroups, true)) {
            return false;
        }
        if (in_array($group, $this->nonPersistent, true)) {
            return false;
        }
        return true;
    }

    /** @param string $group */
    protected function useSplitHash($group)
    {
        return (bool) $this->config->get('split_alloptions', true) && $group === $this->splitGroup;
    }

    /** @param string $key @param string $group */
    protected function redisGet($key, $group)
    {
        try {
            if ($this->useSplitHash($group)) {
                return $this->client()->call('hget', array($this->keys->hashKey($group), (string) $key));
            }
            return $this->client()->call('get', array($this->keys->build($key, $group)));
        } catch (\Exception $e) {
            $this->warnFallback($e->getMessage());
            return false;
        }
    }

    /**
     * @param string $key @param mixed $value @param string $group @param int $expire
     */
    protected function redisSet($key, $value, $group, $expire)
    {
        try {
            $raw = $this->encode($value);
            if ($this->useSplitHash($group)) {
                $this->client()->call('hset', array($this->keys->hashKey($group), (string) $key, $raw));
            } else {
                // Expire honored on plain gets via EX flag when client supports args passthrough.
                if ($expire > 0) {
                    $this->client()->call('set', array($this->keys->build($key, $group), $raw, 'EX', (int) $expire));
                } else {
                    $this->client()->call('set', array($this->keys->build($key, $group), $raw));
                }
            }
            return true;
        } catch (\Exception $e) {
            $this->warnFallback($e->getMessage());
            return false;
        }
    }

    /**
     * @param string $key @param int $offset @param string $group @param string $cmd
     */
    protected function incrDecr($key, $offset, $group, $cmd)
    {
        $group = $this->normalizeGroup($group);
        $current = $this->get($key, $group, false);
        if ($current === false) {
            $current = 0;
        }
        $next = (int) $current + (int) $offset;
        if ($next < 0) {
            $next = 0;
        }
        $this->set($key, $next, $group, 0);
        return $next;
    }

    /** @param mixed $value */
    protected function serializable($value)
    {
        if ($value instanceof \Closure) {
            return false;
        }
        if (is_resource($value)) {
            return false;
        }
        return true;
    }

    /** @param mixed $value */
    protected function encode($value)
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return 's:' . $value;
        }
        return 'p:' . serialize($value);
    }

    /** @param mixed $raw */
    protected function decode($raw)
    {
        if (! is_string($raw)) {
            return $raw;
        }
        if (strpos($raw, 's:') === 0) {
            return substr($raw, 2);
        }
        if (strpos($raw, 'p:') === 0) {
            $value = @unserialize(substr($raw, 2));
            return $value === false ? $raw : $value;
        }
        // Plain integer strings from incr paths.
        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }
        return $raw;
    }

    /** @param string $op @param string $group @param bool $hit @param float $start */
    protected function record($op, $group, $hit, $start)
    {
        if ($this->metrics === null) {
            return;
        }
        $ms = (microtime(true) - $start) * 1000.0;
        $this->metrics->record($op, $group, $hit, $ms);
    }

    /** @param string $detail */
    protected function warnFallback($detail)
    {
        $this->persistent = false;
        if (! $this->warnedFallback) {
            $this->warnedFallback = true;
            $this->log->log('warning', 'arclight: redis unavailable, using non-persistent mode', array('detail' => $detail));
        }
    }
}
