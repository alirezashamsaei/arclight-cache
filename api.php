<?php
// Arclight Cache — procedural shims (original code, PHP 7.2 compat).

defined('ABSPATH') || exit;

if (! function_exists('arclight_cache')) :
    /**
     * @return ArclightCache\Cache\ObjectCacheInterface|null
     */
    function arclight_cache()
    {
        global $wp_object_cache;
        if (isset($GLOBALS['ArclightCache']) && method_exists($GLOBALS['ArclightCache'], 'cache')) {
            return $GLOBALS['ArclightCache']->cache();
        }
        if ($wp_object_cache instanceof ArclightCache\Cache\ObjectCacheInterface) {
            return $wp_object_cache;
        }
        return null;
    }
endif;

if (! function_exists('arclight_flush')) :
    /** @return bool */
    function arclight_flush()
    {
        $cache = arclight_cache();
        return $cache ? $cache->flush() : false;
    }
endif;

if (! function_exists('arclight_info')) :
    /** @return array */
    function arclight_info()
    {
        $cache = arclight_cache();
        return $cache ? $cache->stats() : array('persistent' => false);
    }
endif;
