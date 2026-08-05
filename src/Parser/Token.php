<?php

declare(strict_types=1);

namespace XtScript\Parser;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public mixed $literal = null,
        public int $offset = 0,
    ) {
    }
}
