<?php

declare(strict_types=1);

namespace XtScript\Contract;

use XtScript\Plugin\BlockTagDefinition;
use XtScript\Plugin\FunctionDefinition;
use XtScript\Plugin\TagDefinition;
use XtScript\Plugin\XtTagDefinition;

/**
 * Complete XtScript plugin contract.
 *
 * A plugin may contribute any combination of language capabilities. Use
 * PluginTrait when only a subset is needed.
 */
interface PluginInterface
{
    public function getName(): string;

    /** @return iterable<FunctionDefinition> */
    public function getFunctions(): iterable;

    /** @return iterable<FilterInterface> */
    public function getFilters(): iterable;

    /** @return iterable<TestInterface> */
    public function getTests(): iterable;

    /** @return iterable<TagDefinition> */
    public function getTags(): iterable;

    /** @return iterable<BlockTagDefinition> */
    public function getBlockTags(): iterable;

    /** @return iterable<XtTagDefinition> */
    public function getXtTags(): iterable;

    /** @return array<string, mixed> */
    public function getGlobals(): array;
}
