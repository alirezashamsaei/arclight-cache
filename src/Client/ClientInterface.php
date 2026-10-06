<?php
// Arclight Cache — Client contract (original code, PHP 7.2 compat).

namespace ArclightCache\Client;

defined('ABSPATH') || exit;

interface ClientInterface
{
    /**
     * Dial the server. Must be idempotent.
     *
     * @return void
     * @throws \ArclightCache\Connection\ConnectionException on failure.
     */
    public function connect();

    /** @return bool */
    public function isConnected();

    /**
     * Single command: get, set, del, exists, incrBy, hGet, hSet, etc.
     *
     * @param string $command Lowercase command name.
     * @param array $args Command arguments.
     * @return mixed
     */
    public function call($command, array $args = array());

    /**
     * Pipelined batch. Returns responses in order.
     *
     * @param array $calls Each: array($command, array $args).
     * @return array
     */
    public function pipeline(array $calls);

    /**
     * @param string|null $section
     * @return array
     */
    public function info($section = null);

    /**
     * Bounded SCAN (never KEYS): next cursor + matched keys.
     *
     * @param int $cursor 0 to start; feed back the returned cursor.
     * @param string $match Glob pattern, e.g. "arclight*".
     * @param int $count Hint per call, 1-1000.
     * @return array array($nextCursor, array $keys). $nextCursor 0 = done.
     */
    public function scan($cursor, $match = '*', $count = 100);

    /**
     * Key introspection for the Redis browser.
     *
     * @param string $key
     * @return array type/memory/ttl/extra depending on backend.
     */
    public function inspect($key);

    /** @return void */
    public function close();
}
