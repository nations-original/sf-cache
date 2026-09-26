<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Exception;

use Throwable;

/**
 * Thrown when a cache key is invalid — not a string, or contains illegal characters.
 *
 * Extends {@link InvalidCacheArgumentException} to satisfy the PSR-16 contract.
 */
final class InvalidCacheKeyException extends InvalidCacheArgumentException
{
    public function __construct(string $message = '', int $code = 0, Throwable|null $previous = null)
    {
        if (empty($message)) {
            $message = 'Keys must be strings!';
        }

        parent::__construct($message, $code, $previous);
    }

}
