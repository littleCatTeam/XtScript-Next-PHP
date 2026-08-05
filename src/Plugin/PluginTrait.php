<?php

declare(strict_types=1);

namespace XtScript\Plugin;

/**
 * Empty defaults for optional PluginInterface capabilities.
 *
 * Classes may use this trait and override only the capabilities they expose.
 */
trait PluginTrait
{
    public function getFunctions(): iterable
    {
        return [];
    }

    public function getFilters(): iterable
    {
        return [];
    }

    public function getTests(): iterable
    {
        return [];
    }

    public function getTags(): iterable
    {
        return [];
    }

    public function getBlockTags(): iterable
    {
        return [];
    }

    public function getXtTags(): iterable
    {
        return [];
    }

    public function getGlobals(): array
    {
        return [];
    }
}
