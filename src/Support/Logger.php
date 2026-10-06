<?php
// Arclight Cache — logging contracts + backends (original code, PHP 7.2 compat).

namespace ArclightCache\Support;

defined('ABSPATH') || exit;

interface LoggerInterface
{
    /**
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = array());
}

class NullLogger implements LoggerInterface
{
    public function log($level, $message, array $context = array())
    {
    }
}

class ArrayLogger implements LoggerInterface
{
    /** @var array */
    private $records = array();

    public function log($level, $message, array $context = array())
    {
        $this->records[] = array('level' => $level, 'message' => $message, 'context' => $context);
    }

    /** @return array */
    public function records()
    {
        return $this->records;
    }

    /** @return int */
    public function countFor($level, $needle = '')
    {
        $n = 0;
        foreach ($this->records as $r) {
            if ($r['level'] === $level && ($needle === '' || strpos($r['message'], $needle) !== false)) {
                $n++;
            }
        }
        return $n;
    }
}

class CallbackLogger implements LoggerInterface
{
    /** @var callable */
    private $callback;

    /** @param callable $callback function($level,$message,array $context) */
    public function __construct($callback)
    {
        $this->callback = $callback;
    }

    public function log($level, $message, array $context = array())
    {
        call_user_func($this->callback, $level, $message, $context);
    }
}

class ErrorLogLogger implements LoggerInterface
{
    public function log($level, $message, array $context = array())
    {
        error_log('arclight.' . $level . ': ' . $message);
    }
}
