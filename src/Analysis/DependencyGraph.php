<?php

declare(strict_types=1);

namespace XtScript\Analysis;

final readonly class DependencyGraph
{
    /** @param array<string, list<string>> $edges */
    public function __construct(private array $edges)
    {
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->edges;
    }

    /** @return list<string> */
    public function dependenciesOf(string $template): array
    {
        return $this->edges[$template] ?? [];
    }

    /** @return list<string> */
    public function templates(): array
    {
        return array_keys($this->edges);
    }

    /** @return list<string> */
    public function transitiveDependenciesOf(string $template): array
    {
        $seen = [];
        $visit = function (string $name) use (&$visit, &$seen): void {
            foreach ($this->edges[$name] ?? [] as $dependency) {
                if (isset($seen[$dependency])) continue;
                $seen[$dependency] = true;
                $visit($dependency);
            }
        };
        $visit($template);
        unset($seen[$template]);
        return array_keys($seen);
    }
}
