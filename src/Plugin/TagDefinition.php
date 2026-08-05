<?php

declare(strict_types=1);

namespace XtScript\Plugin;

use Closure;
use InvalidArgumentException;

final readonly class TagDefinition
{
    /** @var Closure(FunctionContext, string): mixed|null */
    public ?Closure $handler;

    /**
     * A null handler marks a parser-managed core tag such as if/foreach.
     * Custom statement tags provide a handler and may return output.
     *
     * @param callable(FunctionContext, string): mixed|null $handler
     */
    public function __construct(
        public string $name,
        ?callable $handler = null,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid XtScript tag name "%s".', $name));
        }

        $this->handler = $handler === null ? null : Closure::fromCallable($handler);
    }
}
