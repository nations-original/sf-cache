<?php

declare(strict_types=1);

use JetBrains\PhpStorm\ExpectedValues;
use PHP_SF\Cache\Abstracts\AbstractCacheAdapter;
use PHP_SF\Cache\Adapter\APCuCacheAdapter;
use PHP_SF\Cache\Adapter\FileSystemCacheAdapter;
use PHP_SF\Cache\Adapter\MemcachedCacheAdapter;
use PHP_SF\Cache\Adapter\RedisCacheAdapter;
use PHP_SF\Cache\Connection\Redis;
use Predis\Client;
use Predis\Pipeline\Pipeline;

if (!function_exists('ca')) {
    /**
     * Returns a cache adapter instance, auto-selecting the best available backend.
     *
     * When $cacheAdapter is omitted: APCu is used if available, otherwise Redis.
     *
     * @param string|null $cacheAdapter One of the AbstractCacheAdapter::*_CACHE_ADAPTER constants, or null for auto.
     */
    function ca(
        #[ExpectedValues([
            null,
            AbstractCacheAdapter::APCU_CACHE_ADAPTER,
            AbstractCacheAdapter::REDIS_CACHE_ADAPTER,
            AbstractCacheAdapter::MEMCACHED_CACHE_ADAPTER,
            AbstractCacheAdapter::FILESYSTEM_CACHE_ADAPTER,
        ])]
        string|null $cacheAdapter = null
    ): AbstractCacheAdapter {
        if ($cacheAdapter !== null && $cacheAdapter::isAvailable()) {
            return $cacheAdapter::getInstance();
        }

        if (APCuCacheAdapter::isAvailable()) {
            return aca();
        }

        if (RedisCacheAdapter::isAvailable()) {
            return rca();
        }

        if (MemcachedCacheAdapter::isAvailable()) {
            return mca();
        }

        // Fallback to always available fca
        return fca();
    }
}

if (!function_exists('rca')) {
    /**
     * Returns the {@link RedisCacheAdapter} singleton.
     */
    function rca(): RedisCacheAdapter
    {
        return RedisCacheAdapter::getInstance();
    }
}

if (!function_exists('aca')) {
    /**
     * Returns the {@link APCuCacheAdapter} singleton.
     */
    function aca(): APCuCacheAdapter
    {
        return APCuCacheAdapter::getInstance();
    }
}

if (!function_exists('mca')) {
    /**
     * Returns the {@link MemcachedCacheAdapter} singleton.
     */
    function mca(): MemcachedCacheAdapter
    {
        return MemcachedCacheAdapter::getInstance();
    }
}

if (!function_exists('rc')) {
    /**
     * Returns the raw Predis {@link Client} instance.
     */
    function rc(): Client
    {
        return Redis::getClient();
    }
}

if (!function_exists('rp')) {
    /**
     * Returns the Predis {@link Pipeline} instance.
     */
    function rp(): Pipeline
    {
        return Redis::getPipeline();
    }
}

if (!function_exists('fca')) {
    /**
     * Returns the {@link FileSystemCacheAdapter} singleton.
     */
    function fca(): FileSystemCacheAdapter
    {
        return FileSystemCacheAdapter::getInstance();
    }
}
