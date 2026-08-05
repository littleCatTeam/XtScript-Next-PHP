<?php

declare(strict_types=1);

namespace XtScript\Profiler;

use XtScript\Contract\ProfilerInterface;

final class ArrayProfiler implements ProfilerInterface
{
    /** @var list<array{event:string,seconds:float,metadata:array<string, scalar|null>}> */
    private array $events = [];

    public function record(string $event, float $seconds, array $metadata = []): void
    {
        $this->events[] = ['event' => $event, 'seconds' => $seconds, 'metadata' => $metadata];
    }

    /** @return list<array{event:string,seconds:float,metadata:array<string, scalar|null>}> */
    public function events(): array
    {
        return $this->events;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
