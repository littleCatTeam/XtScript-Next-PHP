<?php

declare(strict_types=1);

namespace XtScript\Cache;

use XtScript\Ast\Program;
use XtScript\Contract\CompiledTemplateCacheInterface;

final class ArrayCompiledTemplateCache implements CompiledTemplateCacheInterface
{
    /** @var array<string, Program> */
    private array $entries = [];

    public function get(string $key): ?Program
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, Program $program): void
    {
        $this->entries[$key] = $program;
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
