<?php

declare(strict_types=1);

namespace XtScript\Http;

final class NativeCookieStore implements CookieStoreInterface
{
    public function __construct(private readonly string $prefix = 'xts_')
    {
    }

    public function get(string $namespace, string $name, ?string $default = null): ?string
    {
        $physical = $this->physicalName($namespace, $name);
        $value = $_COOKIE[$physical] ?? null;
        return is_scalar($value) ? (string) $value : $default;
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
        if (headers_sent() || !$this->validPath($path) || !$this->validSameSite($sameSite)
            || ($sameSite === 'None' && !$secure)) {
            return false;
        }

        $physical = $this->physicalName($namespace, $name);
        try {
            $ok = setcookie($physical, $value, [
                'expires' => $expiresAt,
                'path' => $path,
                'secure' => $secure,
                'httponly' => $httpOnly,
                'samesite' => $sameSite,
            ]);
        } catch (\Throwable) {
            return false;
        }

        if ($ok) {
            $_COOKIE[$physical] = $value;
        }

        return $ok;
    }

    public function delete(string $namespace, string $name, string $path = '/'): bool
    {
        $physical = $this->physicalName($namespace, $name);
        if (headers_sent() || !$this->validPath($path)) {
            return false;
        }

        try {
            $ok = setcookie($physical, '', [
                'expires' => time() - 86400,
                'path' => $path,
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } catch (\Throwable) {
            return false;
        }

        if ($ok) {
            unset($_COOKIE[$physical]);
        }

        return $ok;
    }

    private function physicalName(string $namespace, string $name): string
    {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,80}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid cookie name.');
        }

        return $this->prefix . substr(hash('sha256', $namespace), 0, 16) . '_' . $name;
    }

    private function validPath(string $path): bool
    {
        return $path !== ''
            && $path[0] === '/'
            && strlen($path) <= 1024
            && preg_match('/[\x00-\x1F\x7F;,]/', $path) !== 1;
    }

    private function validSameSite(string $sameSite): bool
    {
        return in_array($sameSite, ['Strict', 'Lax', 'None'], true);
    }
}
