<?php
// Arclight Cache — Configuration value object (original code, PHP 7.2 compat).
// No typed properties, no arrow functions, no null coalescing assignment.

namespace ArclightCache\Config;

defined('ABSPATH') || exit;

final class Configuration
{
    /** @var array */
    private $data = array();

    /** @var array */
    private $unknownKeys = array();

    /**
     * @param array $data Normalized config.
     */
    private function __construct(array $data, array $unknownKeys)
    {
        $this->data = $data;
        $this->unknownKeys = $unknownKeys;
    }

    /**
     * Build from a raw array (constant / env / filter output).
     *
     * @param mixed $raw
     * @return self
     */
    public static function fromArray($raw)
    {
        if (! is_array($raw)) {
            $raw = array();
        }

        $defaults = self::defaults();
        $known = array_keys($defaults);

        $unknown = array();
        foreach ($raw as $k => $v) {
            if (! in_array($k, $known, true)) {
                $unknown[] = (string) $k;
            }
        }

        // Assoc-replace merge over defaults (lists replaced wholesale).
        $merged = $defaults;
        foreach ($raw as $k => $v) {
            if (array_key_exists($k, $defaults)) {
                $merged[$k] = $v;
            }
        }

        $merged['host'] = self::normalizeHost(isset($merged['host']) ? $merged['host'] : '');
        $merged['port'] = (int) (isset($merged['port']) ? $merged['port'] : 6379);
        $merged['database'] = (int) (isset($merged['database']) ? $merged['database'] : 0);
        $merged['timeout'] = (float) (isset($merged['timeout']) ? $merged['timeout'] : 1.0);
        $merged['read_timeout'] = (float) (isset($merged['read_timeout']) ? $merged['read_timeout'] : 1.0);
        $merged['retries'] = (int) (isset($merged['retries']) ? $merged['retries'] : 2);
        $merged['backoff_ms'] = (int) (isset($merged['backoff_ms']) ? $merged['backoff_ms'] : 100);
        $merged['metrics_sample_rate'] = (float) (isset($merged['metrics_sample_rate']) ? $merged['metrics_sample_rate'] : 1.0);
        if (! is_array($merged['ignored_groups'])) {
            $merged['ignored_groups'] = array();
        }

        return new self($merged, $unknown);
    }

    /**
     * @return array
     */
    public static function defaults()
    {
        return array(
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'username' => null,
            'password' => null,
            'prefix' => 'arclight',
            'timeout' => 1.0,
            'read_timeout' => 1.0,
            'retries' => 2,
            'backoff_ms' => 100,
            'ignored_groups' => array('counts', 'plugins'),
            'split_alloptions' => true,
            'metrics' => true,
            'metrics_sample_rate' => 1.0,
            'sqlite_path' => null,
            'tls' => false,
        );
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }
        return $default;
    }

    /**
     * @return array
     */
    public function unknownKeys()
    {
        return $this->unknownKeys;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return $this->data;
    }

    /**
     * Field-level validation. Empty array = valid.
     *
     * @return string[]
     */
    public function validate()
    {
        $errors = array();
        $d = $this->data;

        if (! is_string($d['host']) || $d['host'] === '') {
            $errors[] = 'host: must be a non-empty string';
        }
        if (! is_int($d['port']) && ! ctype_digit((string) $d['port'])) {
            $errors[] = 'port: must be an integer 1-65535';
        } elseif ($d['port'] < 1 || $d['port'] > 65535) {
            $errors[] = 'port: must be an integer 1-65535';
        }
        if (! is_int($d['database']) || $d['database'] < 0 || $d['database'] > 31) {
            $errors[] = 'database: must be an integer 0-31';
        }
        if ($d['username'] !== null && ! is_string($d['username'])) {
            $errors[] = 'username: must be a string or null';
        }
        if ($d['password'] !== null && ! is_string($d['password'])) {
            $errors[] = 'password: must be a string or null';
        }
        if (! is_string($d['prefix']) || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $d['prefix']) !== 1) {
            $errors[] = 'prefix: 1-32 chars of A-Za-z0-9_-';
        }
        if (! is_numeric($d['timeout']) || $d['timeout'] < 0.05 || $d['timeout'] > 10.0) {
            $errors[] = 'timeout: must be 0.05-10.0 seconds';
        }
        if (! is_numeric($d['read_timeout']) || $d['read_timeout'] < 0.05 || $d['read_timeout'] > 10.0) {
            $errors[] = 'read_timeout: must be 0.05-10.0 seconds';
        }
        if (! is_int($d['retries']) || $d['retries'] < 0 || $d['retries'] > 5) {
            $errors[] = 'retries: must be an integer 0-5';
        }
        if (! is_int($d['backoff_ms']) || $d['backoff_ms'] < 0 || $d['backoff_ms'] > 2000) {
            $errors[] = 'backoff_ms: must be an integer 0-2000';
        }
        if (! is_array($d['ignored_groups'])) {
            $errors[] = 'ignored_groups: must be a string array';
        } else {
            foreach ($d['ignored_groups'] as $g) {
                if (! is_string($g)) {
                    $errors[] = 'ignored_groups: must be a string array';
                    break;
                }
            }
        }
        if (! is_bool($d['split_alloptions'])) {
            $errors[] = 'split_alloptions: must be boolean';
        }
        if (! is_bool($d['metrics'])) {
            $errors[] = 'metrics: must be boolean';
        }
        if (! is_numeric($d['metrics_sample_rate']) || $d['metrics_sample_rate'] < 0.0 || $d['metrics_sample_rate'] > 1.0) {
            $errors[] = 'metrics_sample_rate: must be 0.0-1.0';
        }
        if ($d['sqlite_path'] !== null && ! is_string($d['sqlite_path'])) {
            $errors[] = 'sqlite_path: must be a string or null';
        }
        if (! is_bool($d['tls'])) {
            $errors[] = 'tls: must be boolean';
        }

        return $errors;
    }

    /**
     * @param mixed $host
     * @return string
     */
    private static function normalizeHost($host)
    {
        $host = is_string($host) ? trim($host) : '';
        foreach (array('tcp://', 'tls://', 'redis://') as $scheme) {
            if (strpos($host, $scheme) === 0) {
                $host = substr($host, strlen($scheme));
                break;
            }
        }
        // Strip trailing path/port leftovers like "/0".
        $slash = strpos($host, '/');
        if ($slash !== false) {
            $host = substr($host, 0, $slash);
        }
        return $host === '' ? '127.0.0.1' : $host;
    }
}
