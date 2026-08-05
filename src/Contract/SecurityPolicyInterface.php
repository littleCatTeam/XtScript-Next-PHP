<?php

declare(strict_types=1);

namespace XtScript\Contract;

interface SecurityPolicyInterface
{
    public function allowsFunction(string $name): bool;

    public function allowsFilter(string $name): bool;

    public function allowsTest(string $name): bool;

    public function allowsTag(string $name): bool;

    public function allowsTemplate(string $name, ?string $from = null): bool;
}
