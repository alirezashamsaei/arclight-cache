<?php
// Arclight Cache — Connection contract (original code, PHP 7.2 compat).

namespace ArclightCache\Connection;

use ArclightCache\Client\ClientInterface;

defined('ABSPATH') || exit;

interface ConnectionInterface
{
    /** @return ClientInterface */
    public function client();

    /**
     * Dial with retry/backoff. Idempotent; no-op when already available.
     *
     * @return void
     * @throws ConnectionException
     */
    public function ensure();

    /** @return bool */
    public function isAvailable();

    /** @return int Number of dial attempts made (for tests/doctor). */
    public function attempts();
}
