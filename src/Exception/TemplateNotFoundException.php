<?php

declare(strict_types=1);

namespace XtScript\Exception;

final class TemplateNotFoundException extends XtScriptException
{
    public function __construct(public readonly string $template)
    {
        parent::__construct(sprintf('Template "%s" was not found.', $template));
    }
}
