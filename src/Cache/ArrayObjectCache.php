<?php
// Arclight Cache — non-persistent fallback (original code, PHP 7.2 compat).

namespace ArclightCache\Cache;

use ArclightCache\Support\LoggerInterface;
use ArclightCache\Support\NullLogger;

defined('ABSPATH') || exit;

if (! class_exists('ArclightCache\\Support\\NullLogger', false)) {
    require_once dirname(__DIR__) . '/Support/Logger.php';
}

class ArrayObjectCache implements ObjectCacheInterface
{
    /** @var array group => key => value */
    private $store = array();

    /** @var LoggerInterface */
    private $log;

    /** @param LoggerInterface|null $log */
    public function __construct($log = null)
    {
        $this->log = $log ? $log : new NullLogger();
    }

    public function isPersistent()
    {
        return false;
    }

    public function add($key, $value, $group = 'default', $expire = 0)
    {
        $group = $this->g($group);
        if (isset($this->store[$group][$key])) {
            return false;
        }
        return $this->set($key, $value, $group, $expire);
    }

    public function get($key, $group = 'default', $force = false, &$found = null)
    {
        $group = $this->g($group);
        if (array_key_exists($key, isset($this->store[$group]) ? $this->store[$group] : array())) {
            $found = true;
            return $this->store[$group][$key];
        }
        $found = false;
        return false;
    }

    public function set($key, $value, $group = 'default', $expire = 0)
    {
        if ($value instanceof \Closure || is_resource($value)) {
            $this->log->log('warning', 'arclight: refusing to cache unserializable value', array());
            return false;
        }
        $group = $this->g($group);
        if (! isset($this->store[$group])) {
            $this->store[$group] = array();
        }
        $this->store[$group][$key] = $value;
        return true;
    }

    public function replace($key, $value, $group = 'default', $expire = 0)
    {
        $group = $this->g($group);
        if (! isset($this->store[$group][$key])) {
            return false;
        }
        return $this->set($key, $value, $group, $expire);
    }

    public function delete($key, $group = 'default')
    {
        $group = $this->g($group);
        unset($this->store[$group][$key]);
        return true;
    }

    public function incr($key, $offset = 1, $group = 'default')
    {
        $cur = (int) $this->get($key, $group);
        $next = $cur + (int) $offset;
        if ($next < 0) {
            $next = 0;
        }
        $this->set($key, $next, $group);
        return $next;
    }

    public function decr($key, $offset = 1, $group = 'default')
    {
        return $this->incr($key, -(int) $offset, $group);
    }

    public function flush($siteId = null)
    {
        $this->store = array();
        return true;
    }

    public function flushSite($siteId)
    {
        return $this->flush($siteId);
    }

    public function flushNetwork()
    {
        return $this->flush();
    }

    public function getMultiple(array $keys, $group = 'default')
    {
        $out = array();
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $group);
        }
        return $out;
    }

    public function prefetch(array $keys, $group = 'default')
    {
        return $this->getMultiple($keys, $group);
    }

    public function stats()
    {
        $n = 0;
        foreach ($this->store as $group => $keys) {
            $n += count($keys);
        }
        return array('persistent' => false, 'memory_keys' => $n);
    }

    /** @param string $group */
    private function g($group)
    {
        $group = (string) $group;
        return $group === '' ? 'default' : $group;
    }
}
