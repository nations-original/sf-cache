<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Exception;

use Throwable;

/**
 * Thrown when an operation is not supported by the current cache backend.
 *
 * Currently used by {@link \PHP_SF\Cache\Adapter\MemcachedCacheAdapter::deleteByKeyPattern()},
 * which cannot be implemented because Memcached has no reliable key enumeration API.
 */
final class UnsupportedPlatformException extends InvalidCacheArgumentException
{
    public function __construct(string $message = '', int $code = 0, Throwable|null $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

}
