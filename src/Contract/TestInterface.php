<?php

declare(strict_types=1);

namespace XtScript\Contract;

use XtScript\Context;

interface TestInterface
{
    public function getName(): string;

    /** @param list<mixed> $arguments */
    public function matches(Context $context, mixed $value, array $arguments = [], bool $defined = true): bool;
}
