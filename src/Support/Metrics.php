<?php
// Arclight Cache — in-process metrics + SQLite rollups (original code, PHP 7.2 compat).

namespace ArclightCache\Support;

defined('ABSPATH') || exit;

interface MetricsStoreInterface
{
    /** @param string $op @param string $group @param bool $hit @param float $latencyMs */
    public function record($op, $group, $hit, $latencyMs);

    /** @return array Rolled-up stats. */
    public function rollup();

    /** @return bool Persisted (or true when persistence disabled). */
    public function persist();
}

class MemoryMetricsStore implements MetricsStoreInterface
{
    /** @var int */
    private $hits = 0;

    /** @var int */
    private $misses = 0;

    /** @var array */
    private $latencies = array();

    /** @var array op => count */
    private $ops = array();

    public function record($op, $group, $hit, $latencyMs)
    {
        if ($hit) {
            $this->hits++;
        } else {
            $this->misses++;
        }
        $this->latencies[] = (float) $latencyMs;
        if (! isset($this->ops[$op])) {
            $this->ops[$op] = 0;
        }
        $this->ops[$op]++;
    }

    public function rollup()
    {
        $total = $this->hits + $this->misses;
        return array(
            'hits' => $this->hits,
            'misses' => $this->misses,
            'total' => $total,
            'hit_rate' => $total > 0 ? round($this->hits / $total, 4) : 0.0,
            'p50_ms' => $this->percentile(50),
            'ops' => $this->ops,
        );
    }

    public function persist()
    {
        return true;
    }

    /** @param int $p */
    private function percentile($p)
    {
        $n = count($this->latencies);
        if ($n === 0) {
            return 0.0;
        }
        $sorted = $this->latencies;
        sort($sorted, SORT_NUMERIC);
        $idx = (int) floor(($p / 100) * ($n - 1));
        return round((float) $sorted[$idx], 3);
    }
}

class SqliteMetricsStore extends MemoryMetricsStore
{
    /** @var string|null */
    private $path;

    /** @var LoggerInterface */
    private $log;

    /** @var bool */
    private $warned = false;

    /** @param string|null $path @param LoggerInterface|null $log */
    public function __construct($path, ?LoggerInterface $log = null)
    {
        $this->path = $path;
        $this->log = $log ? $log : new NullLogger();
    }

    public function persist()
    {
        if ($this->path === null || $this->path === '') {
            return true; // persistence disabled by config
        }
        if (! class_exists('PDO') || ! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->warnOnce('arclight: pdo_sqlite unavailable, metrics kept in memory');
            return false;
        }
        try {
            $dir = dirname($this->path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $pdo = new \PDO('sqlite:' . $this->path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE IF NOT EXISTS arclight_rollups (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at INTEGER, hits INTEGER, misses INTEGER, p50_ms REAL)');
            $r = $this->rollup();
            $stmt = $pdo->prepare('INSERT INTO arclight_rollups (created_at, hits, misses, p50_ms) VALUES (:t, :h, :m, :p)');
            $stmt->execute(array(':t' => time(), ':h' => $r['hits'], ':m' => $r['misses'], ':p' => $r['p50_ms']));
            return true;
        } catch (\Exception $e) {
            $this->warnOnce('arclight: metrics persist failed, kept in memory');
            return false;
        }
    }

    /** @param string $msg */
    private function warnOnce($msg)
    {
        if (! $this->warned) {
            $this->warned = true;
            $this->log->log('warning', $msg, array());
        }
    }
}
