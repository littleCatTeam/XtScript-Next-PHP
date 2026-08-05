<?php

declare(strict_types=1);

namespace XtScript\Exception;

final class SyntaxErrorException extends XtScriptException
{
    public function __construct(
        string $message,
        public readonly ?string $template = null,
        public readonly ?int $templateLine = null,
    ) {
        $location = $template !== null
            ? sprintf(' in "%s"%s', $template, $templateLine !== null ? sprintf(' on line %d', $templateLine) : '')
            : '';

        parent::__construct($message . $location);
    }
}
