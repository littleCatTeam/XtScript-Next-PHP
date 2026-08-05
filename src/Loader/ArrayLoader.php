<?php

declare(strict_types=1);

namespace XtScript\Loader;

use XtScript\Contract\LoaderInterface;
use XtScript\Exception\TemplateNotFoundException;
use XtScript\TemplateReference;
use XtScript\TemplateSource;

final class ArrayLoader implements LoaderInterface
{
    /** @var array<string, string> */
    private array $templates;

    /** @param array<string, string> $templates */
    public function __construct(array $templates = [])
    {
        $this->templates = $templates;
    }

    public function set(string $name, string $source): void
    {
        $this->templates[$this->normalize($name)] = $source;
    }

    public function remove(string $name): void
    {
        unset($this->templates[$this->normalize($name)]);
    }

    public function exists(string $name, ?string $from = null): bool
    {
        return array_key_exists($this->resolve($name, $from), $this->templates);
    }

    public function load(string $name, ?string $from = null): TemplateSource
    {
        $resolved = $this->resolve($name, $from);
        if (!array_key_exists($resolved, $this->templates)) {
            throw new TemplateNotFoundException($name);
        }

        return new TemplateSource($resolved, $this->templates[$resolved], 'array://' . $resolved);
    }

    private function resolve(string $name, ?string $from): string
    {
        TemplateReference::assertLocal($name);
        $name = $this->normalize($name);
        if ($from === null || str_starts_with($name, '/')) {
            return ltrim($name, '/');
        }

        $directory = dirname($this->normalize($from));
        return $this->normalize(($directory === '.' ? '' : $directory . '/') . $name);
    }

    private function normalize(string $name): string
    {
        $segments = [];
        foreach (preg_split('~[\\\\/]+~', trim($name)) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
