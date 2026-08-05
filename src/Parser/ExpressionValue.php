<?php

declare(strict_types=1);

namespace XtScript\Parser;

final readonly class ExpressionValue
{
    public function __construct(
        public mixed $value,
        public bool $defined = true,
    ) {
    }

    public static function defined(mixed $value): self
    {
        return new self($value, true);
    }

    public static function undefined(): self
    {
        return new self('', false);
    }
}
