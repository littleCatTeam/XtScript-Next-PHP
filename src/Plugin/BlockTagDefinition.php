<?php

declare(strict_types=1);

namespace XtScript\Plugin;

use Closure;
use InvalidArgumentException;

final readonly class BlockTagDefinition
{
    /** @var Closure(FunctionContext, string, Closure(): string): mixed */
    public Closure $handler;

    /**
     * @param callable(FunctionContext, string, Closure(): string): mixed $handler
     */
    public function __construct(
        public string $name,
        public string $endTag,
        callable $handler,
    ) {
        foreach ([$name, $endTag] as $tag) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $tag) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid XtScript block tag name "%s".', $tag));
            }
        }
        if (strcasecmp($name, $endTag) === 0) {
            throw new InvalidArgumentException('A block tag and its end tag must be different.');
        }

        $this->handler = Closure::fromCallable($handler);
    }
}
