<?php

declare(strict_types=1);

namespace XtScript\Plugin;

use Closure;
use InvalidArgumentException;
use XtScript\Context;
use XtScript\Contract\FilterInterface;

final readonly class FilterDefinition implements FilterInterface
{
    /** @var Closure(Context, mixed, list<mixed>, bool): mixed */
    public Closure $handler;

    /**
     * @param callable(Context, mixed, list<mixed>, bool): mixed $handler
     *
     * The last argument reports whether the input expression was defined.
     * It allows filters such as `default` to distinguish an undefined value
     * from an explicitly supplied empty value.
     */
    public function __construct(
        public string $name,
        callable $handler,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid XtScript filter name "%s".', $name));
        }

        $this->handler = Closure::fromCallable($handler);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function apply(Context $context, mixed $value, array $arguments = [], bool $defined = true): mixed
    {
        return ($this->handler)($context, $value, $arguments, $defined);
    }
}
