<?php

declare(strict_types=1);

namespace XtScript\Contract;

interface TemplateContractInterface
{
    /** @param array<string, mixed> $variables @return array<string, mixed> */
    public function validate(array $variables): array;
}
