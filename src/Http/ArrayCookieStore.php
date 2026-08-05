<?php

declare(strict_types=1);

namespace XtScript\Http;

final class ArrayCookieStore implements CookieStoreInterface
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function get(string $namespace, string $name, ?string $default = null): ?string
    {
        return $this->cookies[$this->key($namespace, $name)] ?? $default;
    }

    public function set(
        string $namespace,
        string $name,
        string $value,
        int $expiresAt = 0,
        string $path = '/',
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): bool {
        $this->cookies[$this->key($namespace, $name)] = $value;
        return true;
    }

    public function delete(string $namespace, string $name, string $path = '/'): bool
    {
        unset($this->cookies[$this->key($namespace, $name)]);
        return true;
    }

    private function key(string $namespace, string $name): string
    {
        return hash('sha256', $namespace) . ':' . $name;
    }
}
