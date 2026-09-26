<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Exception;

use Throwable;

/**
 * Thrown when a cache value is not scalar (e.g. an array or object passed to an adapter that requires scalars).
 *
 * {@link \PHP_SF\Cache\Adapter\FileSystemCacheAdapter} accepts non-scalar values and never throws this.
 * Extends {@link InvalidCacheArgumentException} to satisfy the PSR-16 contract.
 */
class CacheValueException extends InvalidCacheArgumentException
{
    public function __construct(string $message = '', int $code = 0, Throwable|null $previous = null)
    {
        if (empty($message)) {
            $message = 'The value must be a scalar!';
        }

        parent::__construct($message, $code, $previous);
    }

}
