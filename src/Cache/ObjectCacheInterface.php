<?php
// Arclight Cache — object cache contract (original code, PHP 7.2 compat).

namespace ArclightCache\Cache;

defined('ABSPATH') || exit;

interface ObjectCacheInterface
{
    /** @return bool True when Redis-backed, false when degraded to memory. */
    public function isPersistent();

    public function add($key, $value, $group = 'default', $expire = 0);
    public function get($key, $group = 'default', $force = false, &$found = null);
    public function set($key, $value, $group = 'default', $expire = 0);
    public function replace($key, $value, $group = 'default', $expire = 0);
    public function delete($key, $group = 'default');
    public function incr($key, $offset = 1, $group = 'default');
    public function decr($key, $offset = 1, $group = 'default');

    /** @param int|null $siteId Null = current site. */
    public function flush($siteId = null);

    /** @param int $siteId */
    public function flushSite($siteId);

    /** @return bool */
    public function flushNetwork();

    /** @param string[] $keys */
    public function getMultiple(array $keys, $group = 'default');

    /**
     * @param string[] $keys
     * @return array key => value|false
     */
    public function prefetch(array $keys, $group = 'default');

    /** @return array */
    public function stats();
}
