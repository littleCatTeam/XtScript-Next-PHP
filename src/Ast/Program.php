<?php

declare(strict_types=1);

namespace XtScript\Ast;

final readonly class Program
{
    /** @param list<Instruction> $instructions */
    public function __construct(public array $instructions)
    {
    }
}
