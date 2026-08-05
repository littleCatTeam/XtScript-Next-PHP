<?php

declare(strict_types=1);

namespace XtScript\Loader;

use InvalidArgumentException;
use XtScript\Contract\LoaderInterface;
use XtScript\Exception\TemplateNotFoundException;
use XtScript\TemplateReference;
use XtScript\TemplateSource;

final class FilesystemLoader implements LoaderInterface
{
    /** @var list<string> */
    private array $roots;

    /**
     * @param string|list<string> $roots
     */
    public function __construct(string|array $roots, private readonly bool $allowAbsolutePaths = true)
    {
        $roots = is_array($roots) ? $roots : [$roots];
        if ($roots === []) {
            throw new InvalidArgumentException('At least one template root is required.');
        }

        $this->roots = [];
        foreach ($roots as $root) {
            $resolved = realpath($root);
            if ($resolved === false || !is_dir($resolved)) {
                throw new InvalidArgumentException(sprintf('Template root "%s" does not exist.', $root));
            }
            $this->roots[] = rtrim($resolved, DIRECTORY_SEPARATOR);
        }
    }

    public function exists(string $name, ?string $from = null): bool
    {
        try {
            $this->resolvePath($name, $from);
            return true;
        } catch (TemplateNotFoundException) {
            return false;
        }
    }

    public function load(string $name, ?string $from = null): TemplateSource
    {
        $path = $this->resolvePath($name, $from);
        $code = @file_get_contents($path);
        if ($code === false) {
            throw new TemplateNotFoundException($name);
        }

        return new TemplateSource($this->logicalName($path), $code, $path);
    }

    private function resolvePath(string $name, ?string $from): string
    {
        TemplateReference::assertLocal($name);
        $name = trim($name);
        if ($name === '' || str_contains($name, "\0")) {
            throw new TemplateNotFoundException($name);
        }

        $candidates = [];
        if ($this->isAbsolute($name)) {
            if (!$this->allowAbsolutePaths) {
                throw new TemplateNotFoundException($name);
            }
            $candidates[] = $name;
        } else {
            if ($from !== null) {
                $fromPath = $this->pathForLogicalName($from);
                if ($fromPath !== null) {
                    $candidates[] = dirname($fromPath) . DIRECTORY_SEPARATOR . $name;
                }
            }
            foreach ($this->roots as $root) {
                $candidates[] = $root . DIRECTORY_SEPARATOR . $name;
            }
        }

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved === false || !is_file($resolved) || !$this->isWithinRoots($resolved)) {
                continue;
            }

            return $resolved;
        }

        throw new TemplateNotFoundException($name);
    }

    private function pathForLogicalName(string $name): ?string
    {
        foreach ($this->roots as $root) {
            $candidate = realpath($root . DIRECTORY_SEPARATOR . ltrim($name, '/\\'));
            if ($candidate !== false && $this->isWithinRoots($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isWithinRoots(string $path): bool
    {
        foreach ($this->roots as $root) {
            if ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function logicalName(string $path): string
    {
        foreach ($this->roots as $root) {
            if ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                return str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR));
            }
        }

        return $path;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }
}
