<?php

declare(strict_types=1);

namespace XtScript\Contract;

/**
 * Storage abstraction for rendered fragment caching.
 *
 * Implementations may use APCu, Redis, a database, or any other backend.
 * Cache values are rendered strings only; no PHP objects are serialized by core.
 */
interface FragmentCacheInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;

    public function clear(): void;
}
