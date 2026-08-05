<?php

declare(strict_types=1);

namespace XtScript;

final readonly class TemplateSource
{
    public function __construct(
        public string $name,
        public string $code,
        public ?string $origin = null,
    ) {
    }
}
