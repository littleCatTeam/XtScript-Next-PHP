<?php

declare(strict_types=1);

namespace XtScript\Plugin;

use XtScript\Contract\PluginInterface;
use XtScript\Http\CookieStoreInterface;
use XtScript\Http\NativeCookieStore;

final readonly class CookiePlugin implements PluginInterface
{
    use PluginTrait;

    public function __construct(
        private CookieStoreInterface $store = new NativeCookieStore(),
        private string $namespace = 'xtscript',
    )
    {
    }

    public function getName(): string
    {
        return 'cookie';
    }

    public function getFunctions(): iterable
    {
        yield new FunctionDefinition('cookie::get', function (FunctionContext $context, array $args): string {
            $name = $this->stringArg($args, '$name');
            $default = $this->nullableStringArg($args, '$default');
            return $this->store->get($this->namespace($context), $name, $default) ?? '';
        });

        yield new FunctionDefinition('cookie::set', function (FunctionContext $context, array $args): string {
            $name = $this->stringArg($args, '$name');
            $value = $this->stringArg($args, '$val');
            $seconds = $this->intArg($args, '$expire', 0, -315_360_000, 315_360_000);
            $expiresAt = $seconds === 0 ? 0 : time() + $seconds;
            $path = $this->stringArg($args, '$path', '/');
            $secure = $this->boolArg($args, '$secure', true);
            $httpOnly = $this->boolArg($args, '$http_only', true);
            $sameSite = ucfirst(strtolower($this->stringArg($args, '$same_site', 'Lax')));

            $this->store->set($this->namespace($context), $name, $value, $expiresAt, $path, $secure, $httpOnly, $sameSite);
            return '';
        });

        yield new FunctionDefinition('cookie::delete', function (FunctionContext $context, array $args): string {
            $name = $this->stringArg($args, '$name');
            $path = $this->stringArg($args, '$path', '/');
            $this->store->delete($this->namespace($context), $name, $path);
            return '';
        });
    }

    private function namespace(FunctionContext $context): string
    {
        $namespace = trim($this->namespace);
        if ($namespace === '') {
            throw new \InvalidArgumentException('Cookie namespace cannot be empty.');
        }

        return $namespace;
    }

    /** @param array<string, mixed> $args */
    private function stringArg(array $args, string $name, ?string $default = null): string
    {
        $value = $args[$name] ?? $default;
        if ($value === null || is_array($value) || is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException(sprintf('Cookie argument "%s" must be scalar.', $name));
        }

        return (string) $value;
    }

    /** @param array<string, mixed> $args */
    private function nullableStringArg(array $args, string $name): ?string
    {
        if (!array_key_exists($name, $args)) {
            return null;
        }
        return $this->stringArg($args, $name);
    }

    /** @param array<string, mixed> $args */
    private function intArg(array $args, string $name, int $default, int $min, int $max): int
    {
        $value = $args[$name] ?? $default;
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException(sprintf('Cookie argument "%s" must be an integer.', $name));
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('Cookie argument "%s" is out of range.', $name));
        }
        return $value;
    }

    /** @param array<string, mixed> $args */
    private function boolArg(array $args, string $name, bool $default): bool
    {
        $value = $args[$name] ?? $default;
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower((string) $value), ['', '0', 'false', 'off', 'no'], true);
    }
}
