<?php

declare(strict_types=1);

namespace XtScript\Plugin;

use Closure;
use InvalidArgumentException;

/**
 * Runtime definition for an optional prefixed plugin tag (default: <xt:name ... />).
 *
 * Prefixed tags are not part of the XtScript core language. They are post-render
 * plugin hooks and are completely inert until a plugin registers them.
 */
final readonly class XtTagDefinition
{
    /** @var Closure(FunctionContext, string, array<string, string>, ?string, string): mixed */
    public Closure $handler;

    /**
     * Use "*" as a fallback handler for any otherwise-unregistered tag under the configured prefix.
     *
     * @param callable(FunctionContext, string, array<string, string>, ?string, string): mixed $handler
     */
    public function __construct(
        public string $name,
        callable $handler,
    ) {
        if ($name !== '*' && preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid plugin tag name "%s".', $name));
        }

        $this->handler = Closure::fromCallable($handler);
    }
}
