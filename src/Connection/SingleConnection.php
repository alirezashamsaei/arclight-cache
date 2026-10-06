<?php
// Arclight Cache — single-node connection with backoff+jitter (original code).

namespace ArclightCache\Connection;

use ArclightCache\Client\ClientInterface;
use ArclightCache\Config\Configuration;

defined('ABSPATH') || exit;

final class SingleConnection implements ConnectionInterface
{
    /** @var ClientInterface */
    private $client;

    /** @var Configuration */
    private $config;

    /** @var int */
    private $attempts = 0;

    /** @var callable|null usleep-compatible sleeper for tests */
    private $sleeper;

    /**
     * @param ClientInterface $client
     * @param Configuration $config
     * @param callable|null $sleeper function(int $microseconds): void
     */
    public function __construct(ClientInterface $client, Configuration $config, $sleeper = null)
    {
        $this->client = $client;
        $this->config = $config;
        $this->sleeper = $sleeper;
    }

    public function client()
    {
        return $this->client;
    }

    public function attempts()
    {
        return $this->attempts;
    }

    public function isAvailable()
    {
        return $this->client->isConnected();
    }

    public function ensure()
    {
        if ($this->client->isConnected()) {
            return;
        }

        $retries = (int) $this->config->get('retries', 2);
        $baseMs = (int) $this->config->get('backoff_ms', 100);
        $last = null;

        for ($i = 0; $i <= $retries; $i++) {
            $this->attempts++;
            try {
                $this->client->connect();
                return;
            } catch (ConnectionException $e) {
                $last = $e;
            } catch (\Exception $e) {
                $last = new ConnectionException($e->getMessage(), 0, $e);
            }

            if ($i < $retries) {
                $delayMs = $this->backoffWithJitter($baseMs, $i);
                $this->sleepMs($delayMs);
            }
        }

        if ($last instanceof \Exception) {
            throw $last;
        }
        throw new ConnectionException('unable to connect');
    }

    /**
     * Exponential backoff with full jitter.
     *
     * @param int $baseMs
     * @param int $attempt Zero-based retry index.
     * @return int Milliseconds to sleep.
     */
    private function backoffWithJitter($baseMs, $attempt)
    {
        if ($baseMs <= 0) {
            return 0;
        }
        $cap = $baseMs * (1 << min($attempt, 4));
        return mt_rand(0, $cap);
    }

    /** @param int $ms */
    private function sleepMs($ms)
    {
        if ($ms <= 0) {
            return;
        }
        if (is_callable($this->sleeper)) {
            call_user_func($this->sleeper, $ms * 1000);
            return;
        }
        usleep($ms * 1000);
    }
}
