<?php

declare(strict_types=1);

namespace XtScript\Plugin;

use XtScript\Context;
use XtScript\EngineOptions;
use XtScript\Contract\LoaderInterface;
use XtScript\Runtime\RuntimeState;
use XtScript\TemplateReference;
use XtScript\TemplateSource;

final readonly class FunctionContext
{
    public function __construct(
        public Context $context,
        public TemplateSource $template,
        private LoaderInterface $loader,
        private RuntimeState $runtime,
        private EngineOptions $options,
    ) {
    }

    public function variable(string $name, mixed $default = null): mixed
    {
        return $this->context->get($name, $default);
    }

    public function load(string $name): TemplateSource
    {
        TemplateReference::assertAllowed($name, $this->options->allowDomainTemplateReferences);
        TemplateReference::assertAllowed($this->template->name, $this->options->allowDomainTemplateReferences);
        return $this->loader->load($name, $this->template->name);
    }

    public function elapsedSeconds(): float
    {
        return $this->runtime->elapsedSeconds();
    }
}
