<?php

declare(strict_types=1);

namespace XtScript\Contract;

interface ProfilerInterface
{
    /** @param array<string, scalar|null> $metadata */
    public function record(string $event, float $seconds, array $metadata = []): void;
}
