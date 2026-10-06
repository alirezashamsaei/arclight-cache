<?php
// Arclight Cache — time-series metrics history in SQLite (original code, PHP 7.2 compat).
// The drop-in records per-request rollups + Redis INFO snapshots here on shutdown;
// the dashboard reads aggregated series back for graphs. Never touches Redis.

namespace ArclightCache\Support;

defined('ABSPATH') || exit;

final class MetricsHistory
{
    /** @var string|null */
    private $path;

    /** @var LoggerInterface */
    private $log;

    /** @param string|null $path @param LoggerInterface|null $log */
    public function __construct($path, $log = null)
    {
        $this->path = $path;
        $this->log = $log ? $log : new NullLogger();
    }

    /** @return bool */
    public function available()
    {
        return is_string($this->path) && $this->path !== ''
            && class_exists('PDO') && in_array('sqlite', \PDO::getAvailableDrivers(), true);
    }

    /**
     * Record one sample: per-request rollup + server counters.
     *
     * @param array $rollup MemoryMetricsStore::rollup() shape.
     * @param array $server Array with keyspace_hits/keyspace_misses/used_memory_human/connected_clients (strings ok).
     * @return bool
     */
    public function sample(array $rollup, array $server = array())
    {
        if (! $this->available()) {
            return false;
        }
        try {
            $pdo = $this->db();
            $stmt = $pdo->prepare('INSERT INTO arclight_samples (created_at, hits, misses, p50_ms, server_hits, server_misses, server_memory, server_clients) VALUES (:t, :h, :m, :p, :sh, :sm, :mem, :cli)');
            $stmt->execute(array(
                ':t' => time(),
                ':h' => isset($rollup['hits']) ? (int) $rollup['hits'] : 0,
                ':m' => isset($rollup['misses']) ? (int) $rollup['misses'] : 0,
                ':p' => isset($rollup['p50_ms']) ? (float) $rollup['p50_ms'] : 0.0,
                ':sh' => isset($server['keyspace_hits']) ? (int) $server['keyspace_hits'] : -1,
                ':sm' => isset($server['keyspace_misses']) ? (int) $server['keyspace_misses'] : -1,
                ':mem' => isset($server['used_memory_human']) ? (string) $server['used_memory_human'] : '',
                ':cli' => isset($server['connected_clients']) ? (int) $server['connected_clients'] : -1,
            ));
            // Bound table growth: keep newest 3000 samples (~per-request rows add up fast).
            $pdo->exec('DELETE FROM arclight_samples WHERE id <= (SELECT MAX(id) - 3000 FROM arclight_samples)');
            return true;
        } catch (\Exception $e) {
            $this->log->log('warning', 'arclight: metrics sample failed', array());
            return false;
        }
    }

    /**
     * Aggregated series for graphs: buckets per $bucketSeconds over the last $hours.
     *
     * @param int $hours
     * @param int $bucketSeconds
     * @return array Each: array(t, hits, misses, requests, p50_ms, server_hits, server_misses).
     */
    public function series($hours = 24, $bucketSeconds = 300)
    {
        if (! $this->available()) {
            return array();
        }
        try {
            $pdo = $this->db();
            $since = time() - max(1, (int) $hours) * 3600;
            $bucket = max(60, (int) $bucketSeconds);
            $stmt = $pdo->prepare('SELECT created_at, hits, misses, p50_ms, server_hits, server_misses FROM arclight_samples WHERE created_at >= :since ORDER BY created_at ASC LIMIT 5000');
            $stmt->execute(array(':since' => $since));
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $buckets = array();
            foreach ($rows as $r) {
                $b = (int) floor(((int) $r['created_at']) / $bucket) * $bucket;
                if (! isset($buckets[$b])) {
                    $buckets[$b] = array('t' => $b, 'hits' => 0, 'misses' => 0, 'p50' => array(), 'sh' => -1, 'sm' => -1);
                }
                $buckets[$b]['hits'] += (int) $r['hits'];
                $buckets[$b]['misses'] += (int) $r['misses'];
                $buckets[$b]['p50'][] = (float) $r['p50_ms'];
                // Server counters are cumulative: newest sample in bucket wins.
                if ((int) $r['server_hits'] >= 0) {
                    $buckets[$b]['sh'] = (int) $r['server_hits'];
                }
                if ((int) $r['server_misses'] >= 0) {
                    $buckets[$b]['sm'] = (int) $r['server_misses'];
                }
            }
            ksort($buckets);
            $out = array();
            $prevSh = null;
            $prevSm = null;
            foreach ($buckets as $b) {
                $p50 = 0.0;
                if (count($b['p50']) > 0) {
                    sort($b['p50'], SORT_NUMERIC);
                    $p50 = (float) $b['p50'][(int) floor(0.5 * (count($b['p50']) - 1))];
                }
                // Derive per-bucket server deltas from cumulative counters.
                $dSh = null;
                $dSm = null;
                if ($b['sh'] >= 0 && $prevSh !== null && $b['sh'] >= $prevSh) {
                    $dSh = $b['sh'] - $prevSh;
                }
                if ($b['sm'] >= 0 && $prevSm !== null && $b['sm'] >= $prevSm) {
                    $dSm = $b['sm'] - $prevSm;
                }
                if ($b['sh'] >= 0) {
                    $prevSh = $b['sh'];
                }
                if ($b['sm'] >= 0) {
                    $prevSm = $b['sm'];
                }
                $out[] = array(
                    't' => $b['t'],
                    'hits' => $b['hits'],
                    'misses' => $b['misses'],
                    'requests' => $b['hits'] + $b['misses'],
                    'p50_ms' => round($p50, 2),
                    'server_hits' => $dSh,
                    'server_misses' => $dSm,
                );
            }
            return $out;
        } catch (\Exception $e) {
            return array();
        }
    }

    /**
     * Lifetime totals across all samples (fallback ground truth when server
     * counters unavailable, e.g. INFO disabled).
     *
     * @return array hits/misses/requests/samples.
     */
    public function totals()
    {
        if (! $this->available()) {
            return array('hits' => 0, 'misses' => 0, 'requests' => 0, 'samples' => 0);
        }
        try {
            $pdo = $this->db();
            $row = $pdo->query('SELECT COALESCE(SUM(hits),0) h, COALESCE(SUM(misses),0) m, COUNT(*) c FROM arclight_samples')->fetch(\PDO::FETCH_ASSOC);
            $h = (int) $row['h'];
            $m = (int) $row['m'];
            return array('hits' => $h, 'misses' => $m, 'requests' => $h + $m, 'samples' => (int) $row['c']);
        } catch (\Exception $e) {
            return array('hits' => 0, 'misses' => 0, 'requests' => 0, 'samples' => 0);
        }
    }

    /** @return \PDO */
    private function db()
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS arclight_samples (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at INTEGER, hits INTEGER, misses INTEGER, p50_ms REAL, server_hits INTEGER, server_misses INTEGER, server_memory TEXT, server_clients INTEGER)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_arclight_samples_t ON arclight_samples (created_at)');
        return $pdo;
    }
}
