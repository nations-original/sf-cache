<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Exception;

use Error;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;

/**
 * Base PSR-16 exception for invalid cache arguments.
 *
 * Extends {@link Error} (rather than {@link \Exception}) so it propagates as an unchecked error,
 * and implements {@link InvalidArgumentException} to satisfy the PSR-16 contract.
 */
class InvalidCacheArgumentException extends Error implements InvalidArgumentException
{
    public function __construct(string $message = '', int $code = 0, Throwable|null $previous = null)
    {
        if (empty($message)) {
            $message = 'Cache has an invalid argument!';
        }

        parent::__construct($message, $code, $previous);
    }

}
