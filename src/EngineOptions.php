<?php

declare(strict_types=1);

namespace XtScript;

use InvalidArgumentException;

final readonly class EngineOptions
{
    public function __construct(
        public bool $autoEscape = true,
        public EscapeStrategy $escapeStrategy = EscapeStrategy::Html,
        public bool $strictVariables = false,
        public bool $cacheCompiledTemplates = true,
        public ExecutionBackend $executionBackend = ExecutionBackend::Auto,
        public int $compiledCacheSize = 256,
        public int $expressionCacheSize = 256,
        public int $maxInstructions = 100_000,
        public int $maxOutputBytes = 4_194_304,
        public int $maxCaptureBytes = 1_048_576,
        public int $maxIncludeDepth = 32,
        public int $maxFunctionDepth = 32,
        public int $maxLoopIterations = 100_000,
        public int $maxSourceBytes = 1_048_576,
        public int $maxContextVariables = 2_048,
        public int $maxContextBytes = 16_777_216,
        public int $maxContextValueBytes = 1_048_576,
        public int $maxFragmentCacheKeyBytes = 512,
        public int $maxFragmentCacheTtlSeconds = 86_400,
        public int $maxOnceKeys = 1_024,
        public int $maxStacks = 128,
        public int $maxStackBytes = 1_048_576,
        public float $timeoutSeconds = 4.0,
        public bool $allowDomainTemplateReferences = true,
        public string $pluginTagPrefix = 'xt',
        public ?string $phpFileCacheDirectory = null,
    ) {
        foreach ([
            'compiledCacheSize' => $compiledCacheSize,
            'expressionCacheSize' => $expressionCacheSize,
            'maxInstructions' => $maxInstructions,
            'maxOutputBytes' => $maxOutputBytes,
            'maxCaptureBytes' => $maxCaptureBytes,
            'maxIncludeDepth' => $maxIncludeDepth,
            'maxFunctionDepth' => $maxFunctionDepth,
            'maxLoopIterations' => $maxLoopIterations,
            'maxSourceBytes' => $maxSourceBytes,
            'maxContextVariables' => $maxContextVariables,
            'maxContextBytes' => $maxContextBytes,
            'maxContextValueBytes' => $maxContextValueBytes,
            'maxFragmentCacheKeyBytes' => $maxFragmentCacheKeyBytes,
            'maxFragmentCacheTtlSeconds' => $maxFragmentCacheTtlSeconds,
            'maxOnceKeys' => $maxOnceKeys,
            'maxStacks' => $maxStacks,
            'maxStackBytes' => $maxStackBytes,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException(sprintf('%s must be greater than zero.', $name));
            }
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/D', $pluginTagPrefix) !== 1) {
            throw new InvalidArgumentException('pluginTagPrefix must start with a letter and contain only letters, digits, underscore, dot, or hyphen.');
        }

        if ($timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('timeoutSeconds must be greater than zero.');
        }
    }
}
