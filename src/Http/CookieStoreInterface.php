<?php

declare(strict_types=1);

namespace XtScript\Http;

interface CookieStoreInterface
{
    public function get(string $namespace, string $name, ?string $default = null): ?string;

    public function set(
        string $namespace,
        string $name,
        string $value,
        int $expiresAt = 0,
        string $path = '/',
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): bool;

    public function delete(string $namespace, string $name, string $path = '/'): bool;
}
