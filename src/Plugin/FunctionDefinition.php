<?php

declare(strict_types=1);

namespace XtScript\Plugin;

use Closure;
use InvalidArgumentException;

final readonly class FunctionDefinition
{
    /** @var Closure(FunctionContext, array<string, mixed>): mixed */
    public Closure $handler;

    /**
     * @param callable(FunctionContext, array<string, mixed>): mixed $handler
     */
    public function __construct(
        public string $name,
        callable $handler,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:::[A-Za-z_][A-Za-z0-9_]*)?$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid XtScript function name "%s".', $name));
        }

        $this->handler = Closure::fromCallable($handler);
    }
}
