<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Laravel;

use Illuminate\Support\ServiceProvider;
use PHP_SF\Cache\Adapter\FileSystemCacheAdapter;
use PHP_SF\Cache\CacheInterface;
use PHP_SF\Cache\Connection\Memcached;
use PHP_SF\Cache\Connection\Redis;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Wires php-sf-cache adapters into the Laravel service container.
 *
 * Auto-discovered via composer.json extra.laravel.providers.
 * Reads connection details from Laravel's existing config (database.redis, cache.stores.memcached).
 * Binds PHP_SF\Cache\CacheInterface to the auto-selected adapter (APCu if available, else Redis).
 * Override the binding in AppServiceProvider if you need a specific adapter.
 */
class CacheServiceProvider extends ServiceProvider
{
    /** Configures adapters from Laravel config and binds {@link CacheInterface} to the auto-selected adapter. */
    public function register(): void
    {
        $this->configureRedis();
        $this->configureMemcached();
        $this->configureFileSystem();

        $this->app->singleton(CacheInterface::class, static fn () => fca());
    }


    /**
     * Reads Redis connection from database.redis.cache (fallback: database.redis.default)
     * and calls {@link Redis::configure()}. Supports the REDIS_URL env var via the 'url' key.
     */
    private function configureRedis(): void
    {
        /** @var array<string,mixed>|null $redis */
        $redis = config('database.redis.cache') ?? config('database.redis.default');

        if (empty($redis) || !is_array($redis)) {
            return;
        }

        $prefix = config('app.name', 'app') . ':' . config('app.env', 'production') . ':';

        if (!empty($redis['url'])) {
            Redis::configure((string)$redis['url'], $prefix);
            return;
        }

        $host = (string)($redis['host'] ?? '127.0.0.1');
        $port = (int)($redis['port'] ?? 6379);
        $db   = (int)($redis['database'] ?? 0);

        Redis::configure(sprintf('redis://%s:%d/%d', $host, $port, $db), $prefix);
    }

    /** Reads Memcached config from cache.stores.memcached.servers[0] and calls {@link Memcached::configure()}. */
    private function configureMemcached(): void
    {
        if (!extension_loaded('memcached')) {
            return;
        }

        /** @var array<int,array<string,mixed>> $servers */
        $servers = config('cache.stores.memcached.servers', []);

        if (empty($servers)) {
            return;
        }

        $first  = $servers[0];
        $prefix = config('app.name', 'app') . ':' . config('app.env', 'production') . ':';

        Memcached::configure(
            (string)($first['host'] ?? 'localhost'),
            (int)($first['port'] ?? 11211),
            $prefix,
        );
    }

    /** Configures {@link FileSystemCacheAdapter} to use storage/framework/cache/php_sf_cache. */
    private function configureFileSystem(): void
    {
        FileSystemCacheAdapter::configure(
            new Filesystem(),
            storage_path('framework/cache/php_sf_cache'),
        );
    }

}
