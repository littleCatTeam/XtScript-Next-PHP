<?php

declare(strict_types=1);

namespace XtScript\Runtime;

use RuntimeException;

final class ContinueSignal extends RuntimeException
{
    public function __construct(public readonly string $output = '')
    {
        parent::__construct('continue');
    }
}
