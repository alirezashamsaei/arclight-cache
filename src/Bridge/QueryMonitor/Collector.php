<?php
// Arclight Cache — Query Monitor bridge (original code, PHP 7.2 compat).

namespace ArclightCache\Bridge\QueryMonitor;

defined('ABSPATH') || exit;

final class Collector
{
    /** @var array */
    private $entries = array();

    /** @var int */
    private $hits = 0;

    /** @var int */
    private $misses = 0;

    /** @param string $op @param string $group @param bool $hit @param float $ms */
    public function record($op, $group, $hit, $ms)
    {
        if ($hit) {
            $this->hits++;
        } else {
            $this->misses++;
        }
        if (count($this->entries) < 200) {
            $this->entries[] = array('op' => $op, 'group' => $group, 'hit' => $hit, 'ms' => round($ms, 2));
        }
    }

    /** @return array */
    public function summary()
    {
        $total = $this->hits + $this->misses;
        return array(
            'hits' => $this->hits,
            'misses' => $this->misses,
            'total' => $total,
            'hit_rate' => $total > 0 ? round($this->hits / $total, 4) : 0.0,
            'entries' => $this->entries,
        );
    }

    /**
     * Register with Query Monitor when present.
     *
     * @param Collector $collector
     * @return void
     */
    public static function register(Collector $collector)
    {
        if (! function_exists('add_filter')) {
            return;
        }
        add_filter('qm/collectors', function ($collectors) use ($collector) {
            if (is_array($collectors)) {
                $collectors['arclight_cache'] = $collector;
            }
            return $collectors;
        });
    }
}
