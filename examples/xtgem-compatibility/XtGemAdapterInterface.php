<?php

declare(strict_types=1);

namespace XtScript\Examples\XtGemCompatibility;

use XtScript\Plugin\FunctionContext;

/**
 * Application-owned implementation boundary for historical XtGem <xt:...> calls.
 *
 * The engine parses and dispatches tags; your adapter supplies the actual service
 * behavior (filesystem, counters, blog/forum APIs, request metadata, etc.).
 */
interface XtGemAdapterInterface
{
    /** @param array<string, string> $attributes */
    public function render(
        string $name,
        array $attributes,
        ?string $body,
        string $raw,
        FunctionContext $context,
    ): mixed;
}
