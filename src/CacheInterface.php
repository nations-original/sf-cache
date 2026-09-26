<?php

declare(strict_types=1);

namespace PHP_SF\Cache;

use Psr\SimpleCache\CacheInterface as PsrCacheInterface;

/**
 * Extends PSR-16 SimpleCache interface with pattern-based deletion.
 *
 * Implementing {@link PsrCacheInterface} makes any adapter usable directly with:
 *   - Symfony via {@link \Symfony\Component\Cache\Psr16Cache}
 *   - Any PSR-16-aware container or framework
 *   - Legacy projects using the helper functions (ca(), rca(), aca(), mca())
 */
interface CacheInterface extends PsrCacheInterface
{
    /**
     * Deletes cache keys matching a glob-style pattern.
     *
     * Supported wildcards:
     *   - `key*`   — keys starting with "key"
     *   - `*key`   — keys ending with "key"
     *   - `*key*`  — keys containing "key"
     *   - `*`      — all keys
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If the pattern is invalid.
     */
    public function deleteByKeyPattern(string $keyPattern): bool;

}
