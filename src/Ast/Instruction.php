<?php

declare(strict_types=1);

namespace XtScript\Ast;

final readonly class Instruction
{
    /**
     * @param array<string, mixed> $arguments
     * @param list<Instruction> $body
     * @param list<Instruction> $alternate
     */
    public function __construct(
        public InstructionType $type,
        public int $line,
        public array $arguments = [],
        public array $body = [],
        public array $alternate = [],
    ) {
    }
}
