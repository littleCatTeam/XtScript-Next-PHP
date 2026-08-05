<?php

declare(strict_types=1);

namespace XtScript;

use InvalidArgumentException;
use XtScript\Exception\XtScriptException;

final class Context
{
    /** @var list<array<string, mixed>> */
    private array $scopes = [];

    /** @var list<array<string, int>> */
    private array $valueBytes = [];

    private int $totalVariables = 0;
    private int $totalBytes = 0;

    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        array $variables = [],
        private readonly int $maxVariables = PHP_INT_MAX,
        private readonly int $maxBytes = PHP_INT_MAX,
        private readonly int $maxValueBytes = PHP_INT_MAX,
    ) {
        $this->pushScope($this->normalizeVariables($variables));
    }

    /** @param array<string, mixed> $variables */
    public function push(array $variables = []): void
    {
        $this->pushScope($this->normalizeVariables($variables));
    }

    public function pop(): void
    {
        if (count($this->scopes) === 1) {
            throw new InvalidArgumentException('The root context scope cannot be removed.');
        }

        $sizes = array_pop($this->valueBytes) ?? [];
        array_pop($this->scopes);
        $this->totalVariables -= count($sizes);
        $this->totalBytes -= array_sum($sizes);
    }

    public function has(string $name): bool
    {
        $name = self::normalizeName($name);
        for ($index = count($this->scopes) - 1; $index >= 0; --$index) {
            $found = false;
            $this->resolveFromScope($this->scopes[$index], $name, $found);
            if ($found) {
                return true;
            }
        }

        return false;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $name = self::normalizeName($name);
        for ($index = count($this->scopes) - 1; $index >= 0; --$index) {
            $found = false;
            $value = $this->resolveFromScope($this->scopes[$index], $name, $found);
            if ($found) {
                return $value;
            }
        }

        return $default;
    }

    public function set(string $name, mixed $value): void
    {
        $name = self::normalizeName($name);
        $scopeIndex = array_key_last($this->scopes);
        $newSize = $this->estimateValueBytes($value);
        if ($newSize > $this->maxValueBytes) {
            throw new XtScriptException(sprintf('Context value "%s" exceeds the per-value memory limit.', $name));
        }

        $exists = array_key_exists($name, $this->scopes[$scopeIndex]);
        $oldSize = $this->valueBytes[$scopeIndex][$name] ?? 0;
        $nextVariables = $this->totalVariables + ($exists ? 0 : 1);
        $nextBytes = $this->totalBytes - $oldSize + $newSize + ($exists ? 0 : strlen($name));
        if ($nextVariables > $this->maxVariables) {
            throw new XtScriptException('Context variable count limit exceeded.');
        }
        if ($nextBytes > $this->maxBytes) {
            throw new XtScriptException('Context memory limit exceeded.');
        }

        if (!$exists) {
            ++$this->totalVariables;
            $this->totalBytes += strlen($name);
        }
        $this->totalBytes += $newSize - $oldSize;
        $this->scopes[$scopeIndex][$name] = $value;
        $this->valueBytes[$scopeIndex][$name] = $newSize;
    }

    public function delete(string $name): void
    {
        $name = self::normalizeName($name);
        for ($index = count($this->scopes) - 1; $index >= 0; --$index) {
            if (array_key_exists($name, $this->scopes[$index])) {
                $this->totalBytes -= ($this->valueBytes[$index][$name] ?? 0) + strlen($name);
                --$this->totalVariables;
                unset($this->scopes[$index][$name], $this->valueBytes[$index][$name]);
                return;
            }
        }
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $result = [];
        foreach ($this->scopes as $scope) {
            $result = array_replace($result, $scope);
        }

        return $result;
    }

    /** @param array<string, mixed> $variables */
    public function fork(array $variables = [], bool $inherit = true): self
    {
        $context = new self(
            $inherit ? $this->all() : [],
            $this->maxVariables,
            $this->maxBytes,
            $this->maxValueBytes,
        );
        if ($variables !== []) {
            $context->push($variables);
        }

        return $context;
    }

    /** @param array<string, mixed> $scope */
    private function resolveFromScope(array $scope, string $name, bool &$found): mixed
    {
        if (array_key_exists($name, $scope)) {
            $found = true;
            return $scope[$name];
        }

        if (!str_contains($name, '.')) {
            $found = false;
            return null;
        }

        $segments = explode('.', $name);
        $root = array_shift($segments);
        if ($root === null || !array_key_exists($root, $scope)) {
            $found = false;
            return null;
        }

        $value = $scope[$root];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $found = false;
                return null;
            }
            $value = $value[$segment];
        }

        $found = true;
        return $value;
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Context variable name cannot be empty.');
        }

        return str_starts_with($name, '$') ? $name : '$' . $name;
    }

    /** @param array<string, mixed> $variables */
    private function pushScope(array $variables): void
    {
        $scope = [];
        $sizes = [];
        $scopeBytes = 0;
        foreach ($variables as $name => $value) {
            $size = $this->estimateValueBytes($value);
            if ($size > $this->maxValueBytes) {
                throw new XtScriptException(sprintf('Context value "%s" exceeds the per-value memory limit.', $name));
            }
            $scope[$name] = $value;
            $sizes[$name] = $size;
            $scopeBytes += strlen($name) + $size;
        }

        if ($this->totalVariables + count($scope) > $this->maxVariables) {
            throw new XtScriptException('Context variable count limit exceeded.');
        }
        if ($this->totalBytes + $scopeBytes > $this->maxBytes) {
            throw new XtScriptException('Context memory limit exceeded.');
        }

        $this->scopes[] = $scope;
        $this->valueBytes[] = $sizes;
        $this->totalVariables += count($scope);
        $this->totalBytes += $scopeBytes;
    }

    /** @param array<string, mixed> $variables @return array<string, mixed> */
    private function normalizeVariables(array $variables): array
    {
        $normalized = [];
        foreach ($variables as $name => $value) {
            $normalized[self::normalizeName((string) $name)] = $value;
        }
        return $normalized;
    }

    private function estimateValueBytes(mixed $value, int $depth = 0): int
    {
        if ($depth > 16) {
            return $this->maxValueBytes === PHP_INT_MAX ? 1_048_576 : $this->maxValueBytes + 1;
        }
        if ($value === null || is_bool($value)) {
            return 1;
        }
        if (is_int($value) || is_float($value)) {
            return 16;
        }
        if (is_string($value)) {
            return strlen($value);
        }
        if (is_array($value)) {
            $bytes = 0;
            foreach ($value as $key => $item) {
                $bytes += is_string($key) ? strlen($key) : 8;
                $bytes += $this->estimateValueBytes($item, $depth + 1);
                if ($bytes > $this->maxValueBytes) {
                    return $bytes;
                }
            }
            return $bytes;
        }
        if ($value instanceof \Stringable) {
            return strlen((string) $value);
        }

        // Objects/resources can only originate from the trusted host context;
        // XtScript itself cannot instantiate them. Charge a fixed conservative
        // cost without traversing arbitrary object graphs or invoking methods.
        return 256;
    }
}
