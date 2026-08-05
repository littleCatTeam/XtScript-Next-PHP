<?php

declare(strict_types=1);

namespace XtScript\Plugin;

use Closure;
use InvalidArgumentException;
use XtScript\Context;
use XtScript\Contract\TestInterface;

final readonly class TestDefinition implements TestInterface
{
    /** @var Closure(Context, mixed, list<mixed>, bool): bool */
    public Closure $handler;

    /**
     * @param callable(Context, mixed, list<mixed>, bool): bool $handler
     *
     * The last argument reports whether the tested expression was defined.
     */
    public function __construct(
        public string $name,
        callable $handler,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid XtScript test name "%s".', $name));
        }

        $this->handler = Closure::fromCallable($handler);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function matches(Context $context, mixed $value, array $arguments = [], bool $defined = true): bool
    {
        return ($this->handler)($context, $value, $arguments, $defined);
    }
}
