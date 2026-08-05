<?php

declare(strict_types=1);

namespace XtScript;

use XtScript\Exception\TemplateNotFoundException;

/**
 * Validates template references before they reach a loader.
 *
 * Local/logical names are always allowed. Legacy domain-qualified names such as "site.example/path" are optional and can
 * be disabled with EngineOptions. When enabled, the reference is passed unchanged
 * to the single configured LoaderInterface; domain is metadata in the logical name,
 * not a loader selection mechanism. URL schemes, protocol-relative URLs and UNC
 * network paths are never treated as template references by core.
 */
final class TemplateReference
{
    public static function assertAllowed(string $name, bool $allowDomainReferences): void
    {
        $value = self::normalized($name);
        if (self::isDomainQualified($value) && !$allowDomainReferences) {
            throw new TemplateNotFoundException($name);
        }
    }

    public static function assertLocal(string $name): void
    {
        $value = self::normalized($name);
        if (self::isDomainQualified($value)) {
            throw new TemplateNotFoundException($name);
        }
    }

    /** @return array{domain:string,path:string}|null */
    public static function splitDomainQualified(string $name): ?array
    {
        $value = self::normalized($name);
        if (!self::isDomainQualified($value)) {
            return null;
        }

        $slash = strcspn($value, '/\\');
        $domain = strtolower(substr($value, 0, $slash));
        $path = ltrim(substr($value, $slash + 1), '/\\');
        if ($path === '') {
            throw new TemplateNotFoundException($name);
        }

        return ['domain' => $domain, 'path' => $path];
    }

    public static function isDomainQualified(string $name): bool
    {
        return preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}\.)+[A-Za-z]{2,63}[\\/]~D', trim($name)) === 1;
    }

    private static function normalized(string $name): string
    {
        $value = trim($name);
        if ($value === '' || str_contains($value, "\0") || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new TemplateNotFoundException($name);
        }

        $first = $value[0];
        $isAsciiLetter = ($first >= 'A' && $first <= 'Z') || ($first >= 'a' && $first <= 'z');
        $isWindowsDrive = strlen($value) >= 3
            && $isAsciiLetter
            && $value[1] === ':'
            && ($value[2] === '/' || $value[2] === '\\');
        if (!$isWindowsDrive && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $value) === 1) {
            throw new TemplateNotFoundException($name);
        }

        if (str_starts_with($value, '//') || str_starts_with($value, '\\\\')) {
            throw new TemplateNotFoundException($name);
        }

        return $value;
    }

    private function __construct()
    {
    }
}
