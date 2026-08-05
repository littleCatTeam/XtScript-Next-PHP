<?php

declare(strict_types=1);

namespace XtScript\Contract;

use XtScript\TemplateSource;

/**
 * Resolves logical template references.
 *
 * When EngineOptions allows legacy domain-qualified references, the same loader
 * may receive names such as "site.example/path". A domain is part of the logical
 * reference; core never maps a domain to a separate loader. Built-in local loaders
 * intentionally reject domain-qualified names, while application loaders may
 * resolve them through a site filesystem, database, API, or other storage service.
 */
interface LoaderInterface
{
    public function exists(string $name, ?string $from = null): bool;

    public function load(string $name, ?string $from = null): TemplateSource;
}
