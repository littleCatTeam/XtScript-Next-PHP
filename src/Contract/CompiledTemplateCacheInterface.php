<?php

declare(strict_types=1);

namespace XtScript\Contract;

use XtScript\Ast\Program;

/**
 * Optional L2 cache for compiled template programs.
 *
 * Implementations may persist Program objects directly or serialize them into
 * APCu, Redis, a database, or another application-owned cache backend.
 */
interface CompiledTemplateCacheInterface
{
    public function get(string $key): ?Program;

    public function set(string $key, Program $program): void;
}
