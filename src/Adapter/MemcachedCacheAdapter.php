<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Adapter;

use DateInterval;
use DateTimeImmutable;
use PHP_SF\Cache\Abstracts\AbstractCacheAdapter;
use PHP_SF\Cache\Connection\Memcached;
use PHP_SF\Cache\Exception\CacheValueException;
use PHP_SF\Cache\Exception\InvalidCacheArgumentException;
use PHP_SF\Cache\Exception\InvalidConfigurationException;
use PHP_SF\Cache\Exception\UnsupportedPlatformException;
use Throwable;

/**
 * PSR-16 cache adapter backed by Memcached.
 *
 * Requires the `ext-memcached` PHP extension.
 * Configure the connection once via {@link Memcached::configure()} or set MEMCACHED_SERVER / MEMCACHED_PORT in $_ENV.
 * Use the {@link mca()} helper or {@link MemcachedCacheAdapter::getInstance()} to obtain an instance.
 *
 * **Symfony DI** — inject {@link Memcached} as a constructor argument so the container guarantees
 * the connection is configured before the adapter is first used:
 *
 * ```yaml
 * PHP_SF\Cache\Connection\Memcached:
 *   arguments:
 *     - '%env(MEMCACHED_SERVER)%'
 *     - '%env(int:MEMCACHED_PORT)%'
 *     - '%env(SERVER_PREFIX)%:%env(APP_ENV)%:'
 *
 * PHP_SF\Cache\Adapter\MemcachedCacheAdapter:
 *   arguments:
 *     - '@PHP_SF\Cache\Connection\Memcached'
 * ```
 */
final class MemcachedCacheAdapter extends AbstractCacheAdapter
{
    /**
     * @param Memcached|null $connection When injected by a DI container this ensures the connection
     *                                   is configured before any cache method is called. Pass null
     *                                   (or omit) when using the static/helper API.
     */
    public function __construct(private readonly ?Memcached $connection = null)
    {
    }

    /** Returns true if ext-memcached is loaded and the server is reachable. Delegates to {@link Memcached::isAvailable()}. */
    public static function isAvailable(): bool
    {
        return Memcached::isAvailable();
    }

    /**
     * @return mixed The cached value, or $default if the key does not exist.
     *
     * @throws InvalidConfigurationException If ext-memcached is not loaded.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $client = Memcached::getInstance();
        $result = $client->get($key);

        if ($client->getResultCode() === \Memcached::RES_NOTFOUND) {
            return $default;
        }

        return $result;
    }

    /**
     * @param DateInterval|int|null $ttl Seconds, a DateInterval, or null for default TTL.
     *
     * @throws CacheValueException           If $value is not scalar.
     * @throws InvalidCacheArgumentException On unexpected Memcached error.
     * @throws InvalidConfigurationException If ext-memcached is not loaded.
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = self::DEFAULT_TTL): bool
    {
        if (is_scalar($value) === false) {
            throw new CacheValueException();
        }

        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            $ttl = $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        try {
            return Memcached::getInstance()->set($key, $value, $ttl ?? 0);
        } catch (InvalidConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidCacheArgumentException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @throws InvalidConfigurationException If ext-memcached is not loaded.
     */
    public function delete(string $key): bool
    {
        return Memcached::getInstance()->delete($key);
    }

    /**
     * Not supported by Memcached.
     *
     * Memcached does not expose a reliable API to enumerate all stored keys, so pattern-based deletion
     * is impossible. Use {@link clear()} to flush all keys, or switch to Redis if you need this feature.
     *
     * @throws UnsupportedPlatformException Always.
     * @link https://www.php.net/manual/en/memcached.getallkeys.php
     */
    public function deleteByKeyPattern(string $keyPattern): bool
    {
        throw new UnsupportedPlatformException(
            'Memcached does not support pattern-based key deletion. Use clear() to flush all keys, or switch to RedisCacheAdapter.'
        );
    }

    /**
     * Flushes all keys from the Memcached server.
     *
     * @throws InvalidConfigurationException If ext-memcached is not loaded.
     */
    public function clear(): bool
    {
        return Memcached::getInstance()->flush();
    }

    /**
     * @throws InvalidConfigurationException If ext-memcached is not loaded.
     */
    public function has(string $key): bool
    {
        $client = Memcached::getInstance();
        $client->get($key);

        return $client->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

}
