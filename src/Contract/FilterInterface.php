<?php

declare(strict_types=1);

namespace XtScript\Contract;

use XtScript\Context;

interface FilterInterface
{
    public function getName(): string;

    /** @param list<mixed> $arguments */
    public function apply(Context $context, mixed $value, array $arguments = [], bool $defined = true): mixed;
}
