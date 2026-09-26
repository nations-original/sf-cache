<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Connection;

use PHP_SF\Cache\Exception\InvalidConfigurationException;

/**
 * Lazy Memcached connection singleton.
 *
 * Configuration priority (highest → lowest):
 *   1. {@link configure()} — explicit call, e.g. from a DI container or bootstrap file
 *   2. $_ENV / putenv() values: MEMCACHED_SERVER, MEMCACHED_PORT, SERVER_PREFIX, APP_ENV
 *   3. Built-in defaults: localhost:11211, no prefix
 *
 * {@link getInstance()} throws {@link InvalidConfigurationException} if ext-memcached is not loaded.
 * Use {@link isAvailable()} to check both extension presence and TCP connectivity.
 */
final class Memcached
{
    /** @var \Memcached|null Native Memcached instance; null until first connect or after {@link configure()}. */
    private static ?\Memcached $instance = null;

    /** Explicit server hostname set via {@link configure()}; empty string means fall back to env/defaults. */
    private static string $server = '';

    /** Explicit port set via {@link configure()}; 0 means fall back to env/defaults. */
    private static int $port = 0;

    /** Explicit key prefix set via {@link configure()}; empty string means resolve from env. */
    private static string $prefix = '';


    /**
     * Explicitly configure the connection. Resets any existing connection.
     */
    public static function configure(string $server, int $port, string $prefix = ''): void
    {
        self::$server   = $server;
        self::$port     = $port;
        self::$prefix   = $prefix;
        self::$instance = null;
    }


    /**
     * @throws InvalidConfigurationException If ext-memcached is not loaded.
     */
    public static function getInstance(): \Memcached
    {
        if (!extension_loaded('memcached')) {
            throw new InvalidConfigurationException(
                'ext-memcached is not loaded. Install and enable the PHP Memcached extension.'
            );
        }

        if (self::$instance === null) {
            self::connect();
        }

        return self::$instance;
    }

    /** Resolves the active key prefix from static config or env vars. */
    private static function getPrefix(): string
    {
        if (self::$prefix !== '') {
            return self::$prefix;
        }

        $serverPrefix = $_ENV['SERVER_PREFIX'] ?? '';
        $appEnv       = $_ENV['APP_ENV'] ?? '';

        return ($serverPrefix !== '' && $appEnv !== '')
            ? sprintf('%s:%s:', $serverPrefix, $appEnv)
            : '';
    }

    /**
     * Checks extension presence and TCP connectivity. Safe to call in test setUp() or health checks.
     */
    public static function isAvailable(): bool
    {
        if (!extension_loaded('memcached')) {
            return false;
        }

        $server = self::$server !== '' ? self::$server : ($_ENV['MEMCACHED_SERVER'] ?? 'localhost');
        $port   = self::$port   !== 0 ? self::$port : (int)($_ENV['MEMCACHED_PORT'] ?? 11211);

        $sock = @fsockopen($server, $port, $errno, $errstr, 1);

        if ($sock === false) {
            return false;
        }

        fclose($sock);
        return true;
    }


    /** Creates and configures the Memcached instance from static config or env vars. */
    private static function connect(): void
    {
        $server = self::$server !== '' ? self::$server : ($_ENV['MEMCACHED_SERVER'] ?? 'localhost');
        $port   = self::$port   !== 0 ? self::$port : (int)($_ENV['MEMCACHED_PORT'] ?? 11211);
        $prefix = self::getPrefix();

        self::$instance = new \Memcached();
        self::$instance->addServer($server, $port);

        if ($prefix !== '') {
            self::$instance->setOption(\Memcached::OPT_PREFIX_KEY, $prefix);
        }
    }

    /**
     * Allows Symfony (and other DI containers) to wire this as a proper service.
     *
     * When $server is provided the constructor delegates to {@link configure()}, which sets up
     * the static connection state. Declare this class as a service with `arguments:` in
     * services.yaml and inject it into {@link \PHP_SF\Cache\Adapter\MemcachedCacheAdapter} so
     * the container initialises it before the adapter is used.
     *
     * Non-DI callers can continue to use {@link configure()} or rely on env-var defaults.
     */
    public function __construct(string $server = '', int $port = 0, string $prefix = '')
    {
        if ($server !== '') {
            self::configure($server, $port, $prefix);
        }
    }

}
