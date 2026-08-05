<?php

declare(strict_types=1);

namespace XtScript;

use Stringable;

/**
 * Marks already-safe rendered markup so auto-escaping does not escape it twice.
 *
 * Only trusted engine operations (for example the `raw` filter, captured
 * rendered output, or component slots) should create this value.
 */
final readonly class Markup implements Stringable
{
    public function __construct(public string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
