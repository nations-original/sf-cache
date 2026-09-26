<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Adapter;

use DateInterval;
use DateTimeImmutable;
use PHP_SF\Cache\Abstracts\AbstractCacheAdapter;
use PHP_SF\Cache\Exception\InvalidCacheArgumentException;
use PHP_SF\Cache\Exception\InvalidCacheKeyException;
use PHP_SF\Cache\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * PSR-16 cache adapter backed by the filesystem.
 *
 * Unlike the other adapters, FileSystemCacheAdapter:
 *   - Accepts scalar, array, AND object values (anything serializable).
 *   - Has no external service dependency — works in any environment.
 *   - Supports {@link deleteByKeyPattern()} by storing the original key alongside the value.
 *
 * Configuration priority (highest → lowest):
 *   1. {@link configure()} — explicit call, e.g. from a DI container or bootstrap file.
 *   2. $_ENV['CACHE_DIR'] — directory path from environment.
 *   3. sys_get_temp_dir()/php_sf_cache — automatic fallback.
 *
 * Usage in Symfony (services.yaml):
 *   PHP_SF\Cache\Adapter\FileSystemCacheAdapter:
 *     arguments:
 *       $filesystem: '@filesystem'
 *       $cacheDir: '%kernel.cache_dir%/php_sf_cache'
 *
 * Usage with explicit config (legacy / custom framework):
 *   FileSystemCacheAdapter::configure(new Filesystem(), '/var/cache/myapp');
 *   fca()->set('key', $value);
 *
 */
final class FileSystemCacheAdapter extends AbstractCacheAdapter
{
    /** Singleton instance; reset to null whenever {@link configure()} is called. */
    private static ?self       $instance             = null;

    /** Symfony Filesystem set via {@link configure()}; null means a default instance is created on first use. */
    private static ?Filesystem $configuredFilesystem = null;

    /** Cache directory set via {@link configure()}; empty string means fall back to env/tmp. */
    private static string      $configuredCacheDir   = '';


    /** Always returns true — the filesystem is always available. */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Explicitly configure the adapter. Must be called before {@link getInstance()} / {@link fca()}.
     * Resets the singleton so the next call reconnects with the new settings.
     */
    public static function configure(Filesystem $filesystem, string $cacheDir): void
    {
        self::$configuredFilesystem = $filesystem;
        self::$configuredCacheDir   = $cacheDir;
        self::$instance             = null;
    }

    /**
     * Returns the singleton instance, creating it if necessary.
     *
     * Cache directory resolution order: {@link configure()} → $_ENV['CACHE_DIR'] → sys_get_temp_dir()/php_sf_cache.
     *
     * @throws InvalidConfigurationException If the cache directory cannot be created.
     */
    public static function getInstance(): static
    {
        if (self::$instance === null) {
            self::$instance = new self(
                filesystem: self::$configuredFilesystem ?? new Filesystem(),
                cacheDir:   self::$configuredCacheDir !== ''
                                ? self::$configuredCacheDir
                                : ($_ENV['CACHE_DIR'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php_sf_cache'),
            );
        }

        return self::$instance;
    }


    /**
     * @throws InvalidConfigurationException If the cache directory does not exist and cannot be created.
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $cacheDir,
    ) {
        // Sync static state so that fca() / getInstance() returns this instance
        // when it was created by a DI container rather than via configure().
        self::$configuredFilesystem = $filesystem;
        self::$configuredCacheDir   = $cacheDir;
        self::$instance             = $this;

        try {
            if (!$this->filesystem->exists($this->cacheDir)) {
                $this->filesystem->mkdir($this->cacheDir);
            }
        } catch (Throwable $e) {
            throw new InvalidConfigurationException(
                sprintf('FileSystemCacheAdapter: cannot create cache directory "%s": %s', $this->cacheDir, $e->getMessage()),
                0,
                $e
            );
        }
    }


    /** @return mixed The cached value, or $default if missing or expired. */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->read($key)['value'];
    }

    /**
     * Stores any serializable value (scalar, array, or object).
     *
     * @param DateInterval|int|null $ttl Seconds, a DateInterval, or null for no expiry.
     *
     * @throws InvalidCacheArgumentException If the cache file cannot be written.
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = self::DEFAULT_TTL): bool
    {
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            $ttl = $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        $data = [
            'key'        => $key,
            'value'      => $value,
            'expires_at' => $ttl !== null ? time() + $ttl : null,
        ];

        try {
            $this->filesystem->dumpFile($this->getFilePath($key), serialize($data));
            return true;
        } catch (Throwable $e) {
            throw new InvalidCacheArgumentException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @return bool True if the file existed and was removed; false if it did not exist.
     */
    public function delete(string $key): bool
    {
        $path = $this->getFilePath($key);

        if (!$this->filesystem->exists($path)) {
            return false;
        }

        $this->filesystem->remove($path);
        return true;
    }

    /** Removes all .cache files from the cache directory. Always returns true unless an exception is thrown. */
    public function clear(): bool
    {
        try {
            $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: [];
            if (!empty($files)) {
                $this->filesystem->remove($files);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Returns false and removes the cache file if the entry has expired. */
    public function has(string $key): bool
    {
        $path = $this->getFilePath($key);

        if (!$this->filesystem->exists($path)) {
            return false;
        }

        $data = $this->read($key);

        if ($data === null) {
            return false;
        }

        if ($data['expires_at'] !== null && $data['expires_at'] <= time()) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * Deletes cache keys matching a glob-style pattern matched against the original key string.
     *
     * Supported wildcards:
     *   - `key*`   — keys starting with "key"
     *   - `*key`   — keys ending with "key"
     *   - `*key*`  — keys containing "key"
     *   - `*`      — all keys
     *
     * The `*` wildcard may only appear at the start and/or end of the pattern.
     *
     * @throws InvalidCacheKeyException If the pattern is invalid.
     */
    public function deleteByKeyPattern(string $keyPattern): bool
    {
        if (preg_match('/^[a-zA-Z0-9*_\-.:\/\\\\]+$/', $keyPattern) === 0) {
            throw new InvalidCacheKeyException(
                sprintf('Key pattern "%s" is invalid.', $keyPattern)
            );
        }

        if (str_contains(trim($keyPattern, '*'), '*')) {
            throw new InvalidCacheKeyException(
                sprintf('Key pattern "%s" is invalid. "*" may only appear at the start and/or end of the pattern.', $keyPattern)
            );
        }

        $files  = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: [];
        $result = true;

        foreach ($files as $file) {
            $data = $this->readFromPath($file);
            if ($data === null || !isset($data['key'])) {
                continue;
            }

            if ($this->matchesPattern($data['key'], $keyPattern)) {
                $result = $result && $this->delete($data['key']);
            }
        }

        return $result;
    }

    /**
     * FileSystemCacheAdapter accepts scalar, array, and object values.
     * Overrides base setMultiple() which enforces scalar-only.
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = self::DEFAULT_TTL): bool
    {
        $result = true;

        foreach ($values as $key => $value) {
            if (is_string($key) === false) {
                throw new InvalidCacheKeyException();
            }

            $result = $result && $this->set($key, $value, $ttl);
        }

        return $result;
    }


    /** Maps a cache key to its cache file path using an MD5 hash. */
    private function getFilePath(string $key): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . md5($key) . '.cache';
    }

    /**
     * Reads and deserializes the cache file for $key.
     *
     * @return array{key: string, value: mixed, expires_at: int|null}|null Null if the file is missing or corrupt.
     */
    private function read(string $key): ?array
    {
        return $this->readFromPath($this->getFilePath($key));
    }

    /**
     * Reads and deserializes a cache file by its absolute path.
     *
     * @return array{key: string, value: mixed, expires_at: int|null}|null Null if the file is missing or corrupt.
     */
    private function readFromPath(string $filePath): ?array
    {
        $raw = file_get_contents($filePath);

        if ($raw === false) {
            return null;
        }

        $data = unserialize($raw);

        return is_array($data) ? $data : null;
    }

    /** Returns true if $key matches the glob-style $pattern. Supports: `prefix*`, `*suffix`, `*contains*`, `*`. */
    private function matchesPattern(string $key, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if (str_starts_with($pattern, '*') && str_ends_with($pattern, '*')) {
            return str_contains($key, substr($pattern, 1, -1));
        }

        if (str_starts_with($pattern, '*')) {
            return str_ends_with($key, substr($pattern, 1));
        }

        if (str_ends_with($pattern, '*')) {
            return str_starts_with($key, substr($pattern, 0, -1));
        }

        return $key === $pattern;
    }

}
