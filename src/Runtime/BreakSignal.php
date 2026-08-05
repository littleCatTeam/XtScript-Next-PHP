<?php

declare(strict_types=1);

namespace XtScript\Runtime;

use RuntimeException;

final class BreakSignal extends RuntimeException
{
    public function __construct(public readonly string $output = '')
    {
        parent::__construct('break');
    }
}
