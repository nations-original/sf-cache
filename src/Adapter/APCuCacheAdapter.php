<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Adapter;

use DateInterval;
use DateTimeImmutable;
use PHP_SF\Cache\Abstracts\AbstractCacheAdapter;
use PHP_SF\Cache\Exception\CacheValueException;
use PHP_SF\Cache\Exception\InvalidCacheArgumentException;
use PHP_SF\Cache\Exception\InvalidCacheKeyException;
use PHP_SF\Cache\Exception\InvalidConfigurationException;
use Throwable;

/**
 * PSR-16 cache adapter backed by APCu (in-process shared memory).
 *
 * Requires the `ext-apcu` PHP extension with APCu enabled.
 * All methods throw {@link InvalidConfigurationException} if APCu is not available.
 * Use {@link isAvailable()} to check before calling.
 */
final class APCuCacheAdapter extends AbstractCacheAdapter
{
    /**
     * Returns true if APCu is loaded and enabled.
     *
     * Check this before constructing an instance — all methods throw
     * {@link InvalidConfigurationException} when APCu is unavailable.
     */
    public static function isAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }


    /**
     * @return mixed The cached value, or $default if the key does not exist or has expired.
     *
     * @throws InvalidConfigurationException If APCu is not available.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        self::assertAvailable();

        $res = apcu_fetch($key, $success);

        return $success ? $res : $default;
    }

    /**
     * @param DateInterval|int|null $ttl Seconds, a DateInterval, or null (defaults to {@link DEFAULT_TTL}).
     *
     * @throws CacheValueException           If $value is not scalar.
     * @throws InvalidCacheArgumentException On unexpected APCu error.
     * @throws InvalidConfigurationException If APCu is not available.
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        self::assertAvailable();

        if ($ttl === null) {
            $ttl = self::DEFAULT_TTL;
        }

        if (is_scalar($value) === false) {
            throw new CacheValueException();
        }

        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            $ttl = $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        try {
            return (bool)apcu_store($key, $value, $ttl);
        } catch (Throwable $e) {
            throw new InvalidCacheArgumentException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @throws InvalidConfigurationException If APCu is not available.
     */
    public function delete(string $key): bool
    {
        self::assertAvailable();

        return apcu_delete($key);
    }

    /**
     * Deletes cache keys matching a glob-style pattern.
     *
     * Wildcards: `key*`, `*key`, `*key*`, `*`.
     * `*` may only appear at the start and/or end of the pattern.
     *
     * @throws InvalidCacheKeyException If the pattern is invalid.
     */
    public function deleteByKeyPattern(string $keyPattern): bool
    {
        self::assertAvailable();

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

        $arr    = apcu_cache_info()['cache_list'];
        $keys   = array_column($arr, 'info');
        $prefix = $this->resolvePrefix();

        $keys = array_filter($keys, static function (string $v) use ($keyPattern, $prefix): bool {
            $bare = $prefix !== '' ? str_replace($prefix, '', $v) : $v;

            if ($keyPattern === '*') {
                return true;
            }

            if (str_starts_with($keyPattern, '*') && str_ends_with($keyPattern, '*')) {
                return str_contains($bare, substr($keyPattern, 1, -1));
            }

            if (str_starts_with($keyPattern, '*')) {
                return str_ends_with($bare, substr($keyPattern, 1));
            }

            if (str_ends_with($keyPattern, '*')) {
                return str_starts_with($bare, substr($keyPattern, 0, -1));
            }

            return $bare === $keyPattern;
        });

        $result = true;
        foreach ($keys as $key) {
            $result = $result && $this->delete(str_replace($prefix, '', $key));
        }

        return $result;
    }

    /**
     * Clears the entire APCu cache for the current process.
     *
     * @throws InvalidConfigurationException If APCu is not available.
     */
    public function clear(): bool
    {
        self::assertAvailable();

        return apcu_clear_cache();
    }

    /**
     * @throws InvalidConfigurationException If APCu is not available.
     */
    public function has(string $key): bool
    {
        self::assertAvailable();

        return apcu_exists($key);
    }


    /** @throws InvalidConfigurationException If APCu is not available. */
    private static function assertAvailable(): void
    {
        if (self::isAvailable() === false) {
            throw new InvalidConfigurationException(
                'APCu is not available. Install ext-apcu and ensure apcu.enable=1 in php.ini (apcu.enable_cli=1 for CLI).'
            );
        }
    }

    /** Builds the key prefix from SERVER_PREFIX and APP_ENV env vars, or returns '' if either is unset. */
    private function resolvePrefix(): string
    {
        $serverPrefix = $_ENV['SERVER_PREFIX'] ?? '';
        $appEnv       = $_ENV['APP_ENV'] ?? '';

        return ($serverPrefix !== '' && $appEnv !== '')
            ? sprintf('%s:%s:', $serverPrefix, $appEnv)
            : '';
    }

}
