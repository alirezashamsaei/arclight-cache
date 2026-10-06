<?php
// Arclight Cache — cache key builder (original code, PHP 7.2 compat).

namespace ArclightCache\Cache;

defined('ABSPATH') || exit;

final class KeyBuilder
{
    /** @var string */
    private $prefix;

    /** @var int */
    private $siteId;

    /** @var bool */
    private $isMultisite;

    /**
     * @param string $prefix
     * @param int $siteId
     * @param bool $isMultisite
     */
    public function __construct($prefix, $siteId = 1, $isMultisite = false)
    {
        $this->prefix = (string) $prefix;
        $this->siteId = (int) $siteId;
        $this->isMultisite = (bool) $isMultisite;
    }

    /**
     * Build a namespaced key: prefix[:siteN]:group:key
     *
     * @param string $key
     * @param string $group
     * @param bool $global Force network-global (no site segment).
     * @return string
     * @throws \InvalidArgumentException on empty/binary-unsafe key.
     */
    public function build($key, $group = 'default', $global = false)
    {
        $key = (string) $key;
        $group = $group === '' ? 'default' : (string) $group;

        if ($key === '') {
            throw new \InvalidArgumentException('arclight: cache key must not be empty');
        }
        // Binary-safe: reject null bytes and whitespace-only keys.
        if (strpos($key, "\0") !== false || strpos($group, "\0") !== false) {
            throw new \InvalidArgumentException('arclight: key/group must not contain NUL bytes');
        }

        $parts = array($this->prefix);
        if ($this->isMultisite && ! $global) {
            $parts[] = 'site' . $this->siteId;
        } elseif ($global) {
            $parts[] = 'network';
        }
        $parts[] = $this->sanitizeSegment($group);
        $parts[] = $this->sanitizeSegment($key, true);

        return implode(':', $parts);
    }

    /**
     * Hash key for the alloptions-style split group.
     *
     * @param string $group
     * @param bool $global
     * @return string
     */
    public function hashKey($group = 'options', $global = false)
    {
        $parts = array($this->prefix);
        if ($this->isMultisite && ! $global) {
            $parts[] = 'site' . $this->siteId;
        } elseif ($global) {
            $parts[] = 'network';
        }
        $parts[] = $this->sanitizeSegment($group) . '#hash';
        return implode(':', $parts);
    }

    /**
     * @param string $segment
     * @param bool $allowLong Allow long keys (keys may be long; groups short).
     * @return string
     */
    private function sanitizeSegment($segment, $allowLong = false)
    {
        // Keep it reversible-ish but Redis-safe: strip control chars and spaces.
        $segment = preg_replace('/[\x00-\x20\x7f]+/', '_', $segment);
        if ($segment === '' || $segment === null) {
            return '_';
        }
        if (! $allowLong && strlen($segment) > 128) {
            $segment = substr($segment, 0, 96) . '~' . substr(md5($segment), 0, 16);
        }
        return $segment;
    }
}
