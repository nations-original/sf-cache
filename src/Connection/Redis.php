<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Connection;

use PHP_SF\Cache\Exception\InvalidConfigurationException;
use Predis\Client;
use Predis\Pipeline\Pipeline;
use Throwable;

/**
 * Lazy Redis connection singleton.
 *
 * Configuration priority (highest → lowest):
 *   1. {@link configure()} — explicit call, e.g. from a DI container or bootstrap file
 *   2. $_ENV / putenv() values: REDIS_CACHE_URL, SERVER_PREFIX, APP_ENV
 *   3. Built-in defaults: redis://localhost:6379/0, no prefix
 *
 * If the server is unreachable, {@link getClient()} and {@link getPipeline()} throw
 * {@link InvalidConfigurationException}. Use {@link isAvailable()} to check connectivity first.
 */
final class Redis
{
    /** @var Client|null Predis client instance; null until first connect or after {@link configure()}. */
    private static ?Client $client = null;

    /** @var Pipeline|null Predis pipeline; null until first connect or after {@link configure()}. */
    private static ?Pipeline $pipeline = null;

    /** Explicit URL set via {@link configure()}; empty string means fall back to env/defaults. */
    private static string $url = '';

    /** Explicit key prefix set via {@link configure()}; empty string means resolve from env. */
    private static string $prefix = '';

    /** Cached connection error to avoid retrying a failed connect on every {@link getClient()} call. */
    private static ?InvalidConfigurationException $connectionError = null;


    /**
     * Explicitly configure the connection. Resets any existing connection and cached errors.
     */
    public static function configure(string $url, string $prefix = ''): void
    {
        self::$url            = $url;
        self::$prefix         = $prefix;
        self::$client         = null;
        self::$pipeline       = null;
        self::$connectionError = null;
    }


    /**
     * @throws InvalidConfigurationException If the Redis server is not reachable.
     */
    public static function getClient(): Client
    {
        if (self::$client === null && self::$connectionError === null) {
            self::connect();
        }

        if (self::$connectionError !== null) {
            throw self::$connectionError;
        }

        return self::$client;
    }

    /**
     * @throws InvalidConfigurationException If the Redis server is not reachable.
     */
    public static function getPipeline(): Pipeline
    {
        if (self::$client === null && self::$connectionError === null) {
            self::connect();
        }

        if (self::$connectionError !== null) {
            throw self::$connectionError;
        }

        return self::$pipeline;
    }

    /**
     * Returns the active key prefix.
     *
     * If {@link configure()} provided one, that value is returned unchanged.
     * Otherwise builds "{SERVER_PREFIX}:{APP_ENV}:" from env, or '' if either is unset.
     *
     * @internal Used by RedisCacheAdapter; not part of the public API.
     */
    public static function getPrefix(): string
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
     * Checks TCP connectivity without throwing. Safe to call in test setUp() or health checks.
     */
    public static function isAvailable(): bool
    {
        $url  = self::$url !== '' ? self::$url : ($_ENV['REDIS_CACHE_URL'] ?? 'redis://localhost:6379/0');
        $host = parse_url($url, PHP_URL_HOST) ?: 'localhost';
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: 6379);

        $sock = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($sock === false) {
            return false;
        }

        fclose($sock);
        return true;
    }


    /**
     * Attempts to connect to Redis. On failure, stores the error in {@link $connectionError}
     * so subsequent {@link getClient()} calls throw immediately without retrying.
     */
    private static function connect(): void
    {
        $url  = self::$url !== '' ? self::$url : ($_ENV['REDIS_CACHE_URL'] ?? 'redis://localhost:6379/0');
        $host = parse_url($url, PHP_URL_HOST) ?: 'localhost';
        $port = parse_url($url, PHP_URL_PORT) ?: 6379;
        $db   = (int)ltrim(parse_url($url, PHP_URL_PATH) ?: '0', '/');

        try {
            $client = new Client(
                parameters: [ 'host' => $host, 'port' => $port ],
                options:    [ 'prefix' => self::getPrefix() ]
            );

            $client->select($db);

            self::$client   = $client;
            self::$pipeline = $client->pipeline();
        } catch (Throwable $e) {
            self::$connectionError = new InvalidConfigurationException(
                sprintf(
                    'Redis connection failed (%s:%d): %s. Call Redis::configure() or set REDIS_CACHE_URL in $_ENV.',
                    $host,
                    $port,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }

    /**
     * Allows Symfony (and other DI containers) to wire this as a proper service.
     *
     * When $url is provided the constructor delegates to {@link configure()}, which sets up
     * the static connection state. Declare this class as a service with `arguments:` in
     * services.yaml and inject it into {@link \PHP_SF\Cache\Adapter\RedisCacheAdapter} so the
     * container initialises it before the adapter is used.
     *
     * Non-DI callers can continue to use {@link configure()} or rely on env-var defaults.
     */
    public function __construct(string $url = '', string $prefix = '')
    {
        if ($url !== '') {
            self::configure($url, $prefix);
        }
    }

}
