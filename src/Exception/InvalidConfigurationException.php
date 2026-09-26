<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Exception;

use Psr\SimpleCache\CacheException;
use RuntimeException;
use Throwable;

/**
 * Thrown when a cache adapter is used but the required backend is not available or not configured.
 *
 * Examples:
 *   - Redis server not reachable
 *   - ext-apcu not loaded / not enabled
 *   - ext-memcached not installed
 *   - FileSystem cache directory could not be created
 *
 * This is distinct from {@link InvalidCacheArgumentException}, which is for PSR-16 argument violations.
 */
final class InvalidConfigurationException extends RuntimeException implements CacheException
{
    public function __construct(string $message = '', int $code = 0, Throwable|null $previous = null)
    {
        if (empty($message)) {
            $message = 'Cache adapter is not configured or the backend is not available.';
        }

        parent::__construct($message, $code, $previous);
    }

}
