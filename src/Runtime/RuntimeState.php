<?php

declare(strict_types=1);

namespace XtScript\Runtime;

use XtScript\EngineOptions;
use XtScript\EscapeStrategy;
use XtScript\Exception\XtScriptException;

final class RuntimeState
{
    private int $instructions = 0;
    private int $outputBytes = 0;
    private int $includeDepth = 0;
    private int $functionDepth = 0;
    private int $loopIterations = 0;
    private int $captureDepth = 0;

    /** @var list<string> */
    private array $functionNamespaces = [];

    /** @var list<int> */
    private array $captureBytes = [];

    /** @var array<string, true> */
    private array $onceKeys = [];
    private readonly float $startedAt;

    /** @var list<EscapeStrategy> */
    private array $autoEscapeStack = [];

    /** @var array<string, list<string>> */
    private array $namedStacks = [];
    private int $namedStackBytes = 0;

    public function __construct(private readonly EngineOptions $options)
    {
        $this->startedAt = microtime(true);
        $this->autoEscapeStack[] = $this->options->autoEscape ? $this->options->escapeStrategy : EscapeStrategy::None;
    }

    public function tick(int $amount = 1): void
    {
        $this->instructions += $amount;
        if ($this->instructions > $this->options->maxInstructions) {
            throw new XtScriptException('Execution instruction limit exceeded.');
        }

        if ((microtime(true) - $this->startedAt) > $this->options->timeoutSeconds) {
            throw new XtScriptException('Execution timeout exceeded.');
        }
    }

    public function addOutput(string $output): void
    {
        if ($this->captureDepth > 0) {
            $index = array_key_last($this->captureBytes);
            if ($index !== null) {
                $this->captureBytes[$index] += strlen($output);
                if ($this->captureBytes[$index] > $this->options->maxCaptureBytes) {
                    throw new XtScriptException('Captured output size limit exceeded.');
                }
            }
            return;
        }

        $this->outputBytes += strlen($output);
        if ($this->outputBytes > $this->options->maxOutputBytes) {
            throw new XtScriptException('Output size limit exceeded.');
        }
    }

    public function enterInclude(): void
    {
        if (++$this->includeDepth > $this->options->maxIncludeDepth) {
            --$this->includeDepth;
            throw new XtScriptException('Maximum include depth exceeded.');
        }
    }

    public function leaveInclude(): void
    {
        $this->includeDepth = max(0, $this->includeDepth - 1);
    }

    public function enterFunction(): void
    {
        if (++$this->functionDepth > $this->options->maxFunctionDepth) {
            --$this->functionDepth;
            throw new XtScriptException('Maximum function depth exceeded.');
        }
    }

    public function leaveFunction(): void
    {
        $this->functionDepth = max(0, $this->functionDepth - 1);
    }

    public function pushFunctionNamespace(string $namespace): void
    {
        $this->functionNamespaces[] = $namespace;
    }

    public function popFunctionNamespace(): void
    {
        array_pop($this->functionNamespaces);
    }

    public function currentFunctionNamespace(): string
    {
        $index = array_key_last($this->functionNamespaces);
        return $index !== null ? $this->functionNamespaces[$index] : '';
    }


    public function enterCapture(): void
    {
        ++$this->captureDepth;
        $this->captureBytes[] = 0;
    }

    public function leaveCapture(): void
    {
        if ($this->captureDepth > 0) {
            --$this->captureDepth;
            array_pop($this->captureBytes);
        }
    }

    public function remainingLoopIterations(): int
    {
        return max(0, $this->options->maxLoopIterations - $this->loopIterations);
    }

    public function pushEscapeStrategy(EscapeStrategy $strategy): void
    {
        $this->autoEscapeStack[] = $strategy;
    }

    public function popAutoEscape(): void
    {
        if (count($this->autoEscapeStack) > 1) {
            array_pop($this->autoEscapeStack);
        }
    }

    public function escapeStrategy(): EscapeStrategy
    {
        $index = array_key_last($this->autoEscapeStack);
        return $index !== null ? $this->autoEscapeStack[$index] : ($this->options->autoEscape ? $this->options->escapeStrategy : EscapeStrategy::None);
    }

    public function autoEscapeEnabled(): bool
    {
        return $this->escapeStrategy() !== EscapeStrategy::None;
    }

    public function once(string $key): bool
    {
        if (isset($this->onceKeys[$key])) {
            return false;
        }
        if (count($this->onceKeys) >= $this->options->maxOnceKeys) {
            throw new XtScriptException('Once-key limit exceeded.');
        }
        $this->onceKeys[$key] = true;
        return true;
    }

    public function pushStack(string $name, string $content, bool $prepend = false): void
    {
        if (!array_key_exists($name, $this->namedStacks)) {
            if (count($this->namedStacks) >= $this->options->maxStacks) {
                throw new XtScriptException('Named stack count limit exceeded.');
            }
            $this->namedStacks[$name] = [];
        }

        $this->namedStackBytes += strlen($content);
        if ($this->namedStackBytes > $this->options->maxStackBytes) {
            throw new XtScriptException('Named stack byte limit exceeded.');
        }

        if ($prepend) {
            array_unshift($this->namedStacks[$name], $content);
            return;
        }
        $this->namedStacks[$name][] = $content;
    }

    public function stack(string $name): string
    {
        return implode('', $this->namedStacks[$name] ?? []);
    }

    public function hasStack(string $name): bool
    {
        return isset($this->namedStacks[$name]) && $this->namedStacks[$name] !== [];
    }


    public function elapsedSeconds(): float
    {
        return max(0.0, microtime(true) - $this->startedAt);
    }

    public function loop(): void
    {
        if (++$this->loopIterations > $this->options->maxLoopIterations) {
            throw new XtScriptException('Loop iteration limit exceeded.');
        }

        $this->tick();
    }
}
