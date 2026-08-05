<?php

declare(strict_types=1);

namespace XtScript\Runtime;

use RuntimeException;

final class ReturnSignal extends RuntimeException
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $output = '',
    ) {
        parent::__construct('XtScript function returned.');
    }
}
