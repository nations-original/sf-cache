<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Adapter;

use DateInterval;
use DateTimeImmutable;
use PHP_SF\Cache\Abstracts\AbstractCacheAdapter;
use PHP_SF\Cache\Connection\Redis;
use PHP_SF\Cache\Exception\CacheValueException;
use PHP_SF\Cache\Exception\InvalidCacheArgumentException;
use PHP_SF\Cache\Exception\InvalidCacheKeyException;
use PHP_SF\Cache\Exception\InvalidConfigurationException;
use Throwable;

/**
 * PSR-16 cache adapter backed by Redis (via Predis).
 *
 * Configure the connection once via {@link Redis::configure()} or set REDIS_CACHE_URL in $_ENV.
 * Use the {@link rca()} helper or {@link RedisCacheAdapter::getInstance()} to obtain an instance.
 *
 * **Symfony DI** — inject {@link Redis} as a constructor argument so the container guarantees
 * the connection is configured before the adapter is first used:
 *
 * ```yaml
 * PHP_SF\Cache\Connection\Redis:
 *   arguments:
 *     - '%env(REDIS_CACHE_URL)%'
 *     - '%env(SERVER_PREFIX)%:%env(APP_ENV)%:'
 *
 * PHP_SF\Cache\Adapter\RedisCacheAdapter:
 *   arguments:
 *     - '@PHP_SF\Cache\Connection\Redis'
 * ```
 */
final class RedisCacheAdapter extends AbstractCacheAdapter
{
    /**
     * @param Redis|null $connection When injected by a DI container this ensures the connection
     *                               is configured before any cache method is called. Pass null
     *                               (or omit) when using the static/helper API.
     */
    public function __construct(private readonly ?Redis $connection = null)
    {
    }

    /** Returns true if the Redis server is reachable. Delegates to {@link Redis::isAvailable()}. */
    public static function isAvailable(): bool
    {
        return Redis::isAvailable();
    }

    /**
     * Note: Redis stores all values as strings via Predis. Integer/float values are returned as strings.
     *
     * @return mixed The cached value (always a string for scalar types), or $default if missing.
     *
     * @throws InvalidConfigurationException If the Redis server is not reachable.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Redis::getClient()->get($key) ?? $default;
    }

    /**
     * @param DateInterval|int|null $ttl Seconds, a DateInterval, or null for no expiry.
     *
     * @throws CacheValueException           If $value is not scalar.
     * @throws InvalidCacheArgumentException On unexpected Redis error.
     * @throws InvalidConfigurationException If the Redis server is not reachable.
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
            if ($ttl !== null) {
                Redis::getClient()->setex($key, $ttl, $value);
            } else {
                Redis::getClient()->set($key, $value);
            }

            return true;
        } catch (InvalidConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidCacheArgumentException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @return bool True if the key existed and was deleted.
     *
     * @throws InvalidConfigurationException If the Redis server is not reachable.
     */
    public function delete(string $key): bool
    {
        return Redis::getClient()->del($key) > 0;
    }

    /**
     * Deletes cache keys matching a glob-style pattern.
     *
     * Supported wildcards:
     *   - `key*`   — keys starting with "key"
     *   - `*key`   — keys ending with "key"
     *   - `*key*`  — keys containing "key"
     *   - `*`      — all keys
     *
     * Note: only alphanumeric characters and `*` are allowed in the pattern.
     * The `*` wildcard may only appear at the start and/or end of the pattern.
     *
     * @throws InvalidCacheKeyException If the pattern is invalid.
     */
    public function deleteByKeyPattern(string $keyPattern): bool
    {
        if (preg_match('/^[a-zA-Z0-9*_\-.:]+$/', $keyPattern) === 0) {
            throw new InvalidCacheKeyException(
                sprintf('Key pattern "%s" is invalid. Only alphanumeric characters, hyphens, underscores, dots, colons and "*" are allowed.', $keyPattern)
            );
        }

        if (str_contains(trim($keyPattern, '*'), '*')) {
            throw new InvalidCacheKeyException(
                sprintf('Key pattern "%s" is invalid. "*" may only appear at the start and/or end of the pattern.', $keyPattern)
            );
        }

        $prefix = Redis::getPrefix();
        $keys   = Redis::getClient()->keys($keyPattern);

        if (empty($keys)) {
            return true;
        }

        $result = true;
        foreach ($keys as $key) {
            $result = $result && $this->delete($prefix !== '' ? str_replace($prefix, '', $key) : $key);
        }

        return $result;
    }

    /**
     * Flushes the currently selected Redis database.
     *
     * @throws InvalidConfigurationException If the Redis server is not reachable.
     */
    public function clear(): bool
    {
        return Redis::getClient()->flushdb()->getPayload() === 'OK';
    }

    /**
     * @throws InvalidConfigurationException If the Redis server is not reachable.
     */
    public function has(string $key): bool
    {
        return Redis::getClient()->exists($key) > 0;
    }

    /**
     * Publishes a message to a Redis pub/sub channel.
     *
     * @param string $channel The channel name.
     * @param mixed  $message Scalar or JSON-serializable value.
     *
     * @throws InvalidCacheArgumentException On publish failure.
     */
    public function pub(string $channel, mixed $message): bool
    {
        try {
            if (!is_scalar($message)) {
                $message = json_encode($message);
                if ($message === false) {
                    throw new CacheValueException('Message could not be JSON-encoded.');
                }
            }

            Redis::getClient()->publish($channel, (string)$message);
            return true;
        } catch (InvalidConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidCacheArgumentException($e->getMessage(), $e->getCode(), $e);
        }
    }

}
