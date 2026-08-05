<?php

declare(strict_types=1);

namespace XtScript\Security;

use XtScript\Contract\SecurityPolicyInterface;

/**
 * Simple exact-name allow-list policy.
 *
 * A null list means unrestricted for that category; an empty list denies all.
 * Applications needing path/prefix/tenant-aware rules can implement the
 * SecurityPolicyInterface directly.
 */
final readonly class AllowListSecurityPolicy implements SecurityPolicyInterface
{
    /** @var array<string, true>|null */
    private ?array $functions;
    /** @var array<string, true>|null */
    private ?array $filters;
    /** @var array<string, true>|null */
    private ?array $tests;
    /** @var array<string, true>|null */
    private ?array $tags;
    /** @var array<string, true>|null */
    private ?array $templates;

    /**
     * @param list<string>|null $functions
     * @param list<string>|null $filters
     * @param list<string>|null $tests
     * @param list<string>|null $tags
     * @param list<string>|null $templates
     */
    public function __construct(
        ?array $functions = null,
        ?array $filters = null,
        ?array $tests = null,
        ?array $tags = null,
        ?array $templates = null,
    ) {
        $this->functions = self::normalize($functions);
        $this->filters = self::normalize($filters);
        $this->tests = self::normalize($tests);
        $this->tags = self::normalize($tags);
        $this->templates = self::normalize($templates, false);
    }

    public function allowsFunction(string $name): bool
    {
        return self::allows($this->functions, $name);
    }

    public function allowsFilter(string $name): bool
    {
        return self::allows($this->filters, $name);
    }

    public function allowsTest(string $name): bool
    {
        return self::allows($this->tests, $name);
    }

    public function allowsTag(string $name): bool
    {
        return self::allows($this->tags, $name);
    }

    public function allowsTemplate(string $name, ?string $from = null): bool
    {
        if ($this->templates === null) {
            return true;
        }
        return isset($this->templates[$name]);
    }

    /** @param list<string>|null $values @return array<string, true>|null */
    private static function normalize(?array $values, bool $lowercase = true): ?array
    {
        if ($values === null) {
            return null;
        }
        $normalized = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $normalized[$lowercase ? strtolower($value) : $value] = true;
        }
        return $normalized;
    }

    /** @param array<string, true>|null $allowed */
    private static function allows(?array $allowed, string $name): bool
    {
        return $allowed === null || isset($allowed[strtolower($name)]);
    }
}
