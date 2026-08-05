<?php

declare(strict_types=1);

namespace XtScript\Ast;

use XtScript\TemplateSource;

final readonly class UserFunction
{
    /**
     * @param array<string, string> $parameters
     * @param list<Instruction> $body
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public array $body,
        public TemplateSource $template,
        public int $line,
        public string $namespace = '',
    ) {
    }
}
