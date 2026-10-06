<?php
// Arclight Cache — single health report (original code, PHP 7.2 compat).

namespace ArclightCache\Tools;

use ArclightCache\Config\Configuration;
use ArclightCache\Connection\ConnectionInterface;

defined('ABSPATH') || exit;

final class Doctor
{
    /** @var ConnectionInterface */
    private $connection;

    /** @var Configuration */
    private $config;

    /** @var bool */
    private $showSecrets;

    /**
     * @param ConnectionInterface $connection
     * @param Configuration $config
     * @param bool $showSecrets
     */
    public function __construct(ConnectionInterface $connection, Configuration $config, $showSecrets = false)
    {
        $this->connection = $connection;
        $this->config = $config;
        $this->showSecrets = (bool) $showSecrets;
    }

    /**
     * Run all checks. Never throws; each check reports ok/detail.
     *
     * @return array status => pass|fail, checks => name/ok/detail[]
     */
    public function run()
    {
        $checks = array();
        $checks[] = $this->checkConfig();
        $checks[] = $this->checkExtension();
        $checks[] = $this->checkConnect();
        $checks[] = $this->checkRoundTrip();
        $checks[] = $this->checkInfo();
        $checks[] = $this->checkPrefix();

        $fail = false;
        foreach ($checks as $c) {
            if (! $c['ok']) {
                $fail = true;
                break;
            }
        }

        $unknown = $this->config->unknownKeys();
        if (count($unknown) > 0) {
            $checks[] = array('name' => 'unknown_keys', 'ok' => true, 'detail' => 'ignored: ' . implode(',', $unknown));
        }

        return array(
            'status' => $fail ? 'fail' : 'pass',
            'endpoint' => $this->redactedEndpoint(),
            'attempts' => $this->connection->attempts(),
            'checks' => $checks,
        );
    }

    /** @return array */
    private function checkConfig()
    {
        $errors = $this->config->validate();
        return array(
            'name' => 'config',
            'ok' => count($errors) === 0,
            'detail' => count($errors) === 0 ? 'valid' : implode('; ', $errors),
        );
    }

    /** @return array */
    private function checkExtension()
    {
        // v1 supports the fake + PhpRedis. Report what is present.
        $hasRedis = extension_loaded('redis');
        $client = get_class($this->connection->client());
        return array(
            'name' => 'extension',
            'ok' => true,
            'detail' => $hasRedis ? 'phpredis present' : ('client=' . $client . ' (no phpredis, testing/fallback mode)'),
        );
    }

    /** @return array */
    private function checkConnect()
    {
        try {
            $this->connection->ensure();
            return array('name' => 'connect', 'ok' => $this->connection->isAvailable(), 'detail' => 'dial ok');
        } catch (\Exception $e) {
            return array('name' => 'connect', 'ok' => false, 'detail' => $e->getMessage());
        }
    }

    /** @return array */
    private function checkRoundTrip()
    {
        try {
            $t0 = microtime(true);
            $client = $this->connection->client();
            $probe = 'arclight-doctor-' . getmypid();
            $client->call('set', array($probe, '1'));
            $got = $client->call('get', array($probe));
            $client->call('del', array($probe));
            $ms = round((microtime(true) - $t0) * 1000, 2);
            $ok = ($got === '1' || $got === 1 || $got === 's:1');
            return array('name' => 'round_trip', 'ok' => $ok, 'detail' => $ms . 'ms');
        } catch (\Exception $e) {
            return array('name' => 'round_trip', 'ok' => false, 'detail' => $e->getMessage());
        }
    }

    /** @return array */
    private function checkInfo()
    {
        try {
            $info = $this->connection->client()->info();
            $policy = isset($info['maxmemory_policy']) ? $info['maxmemory_policy'] : 'unknown';
            $frag = isset($info['mem_fragmentation_ratio']) ? $info['mem_fragmentation_ratio'] : '?';
            $ok = ($policy !== 'noeviction');
            return array('name' => 'memory', 'ok' => $ok, 'detail' => 'policy=' . $policy . ' frag=' . $frag);
        } catch (\Exception $e) {
            return array('name' => 'memory', 'ok' => false, 'detail' => $e->getMessage());
        }
    }

    /** @return array */
    private function checkPrefix()
    {
        try {
            $prefix = (string) $this->config->get('prefix', 'arclight');
            $client = $this->connection->client();
            // Collision probe: our namespace probe must not see foreign data.
            $probe = $prefix . ':site1:default:__arclight_probe__';
            $client->call('set', array($probe, 'ok'));
            $got = $client->call('get', array($probe));
            $client->call('del', array($probe));
            $ok = ($got !== false);
            return array('name' => 'prefix', 'ok' => $ok, 'detail' => 'prefix=' . $prefix);
        } catch (\Exception $e) {
            return array('name' => 'prefix', 'ok' => false, 'detail' => $e->getMessage());
        }
    }

    /** @return string */
    private function redactedEndpoint()
    {
        $tls = $this->config->get('tls', false) ? 'tls://' : '';
        $ep = $tls . $this->config->get('host') . ':' . $this->config->get('port') . '/' . $this->config->get('database');
        if ($this->showSecrets && $this->config->get('password') !== null) {
            $ep .= ' (password set)';
        }
        return $ep;
    }
}
