<?php

declare(strict_types=1);

namespace XtScript\Cache;

use XtScript\Contract\FragmentCacheInterface;

final class ArrayFragmentCache implements FragmentCacheInterface
{
    /** @var array<string, array{value:string,expiresAt:float}> */
    private array $items = [];

    public function get(string $key): ?string
    {
        $item = $this->items[$key] ?? null;
        if ($item === null) {
            return null;
        }
        if ($item['expiresAt'] <= microtime(true)) {
            unset($this->items[$key]);
            return null;
        }

        return $item['value'];
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            unset($this->items[$key]);
            return;
        }

        $this->items[$key] = [
            'value' => $value,
            'expiresAt' => microtime(true) + $ttlSeconds,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function clear(): void
    {
        $this->items = [];
    }
}
