<?php
// Arclight Cache — client factory (original code, PHP 7.2 compat).

namespace ArclightCache\Connection;

use ArclightCache\Client\ArrayFakeClient;
use ArclightCache\Client\ClientInterface;
use ArclightCache\Client\PhpRedisClient;
use ArclightCache\Config\Configuration;

defined('ABSPATH') || exit;

final class Connector
{
    /**
     * Build the best available client: PhpRedis when the extension exists,
     * otherwise an in-memory fake so the site stays up (non-persistent).
     *
     * @param Configuration $config
     * @return ClientInterface
     */
    public static function createClient(Configuration $config)
    {
        if (class_exists('Redis')) {
            return new PhpRedisClient($config);
        }
        return new ArrayFakeClient('ok');
    }

    /**
     * Build a single-node connection around the best client.
     *
     * @param Configuration $config
     * @param callable|null $sleeper
     * @return SingleConnection
     */
    public static function createConnection(Configuration $config, $sleeper = null)
    {
        return new SingleConnection(self::createClient($config), $config, $sleeper);
    }
}
