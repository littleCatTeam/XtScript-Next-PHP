<?php

declare(strict_types=1);

namespace XtScript\Runtime;

use Traversable;
use XtScript\Context;
use XtScript\Escaper;
use XtScript\EngineOptions;
use XtScript\Exception\SyntaxErrorException;
use XtScript\Markup;
use XtScript\Parser\ExpressionEvaluator;
use XtScript\Parser\ExpressionSyntax;
use XtScript\TemplateSource;

/**
 * Small runtime bridge used by code emitted by PhpEvalCompiler.
 *
 * It deliberately exposes only data/expression operations. PHP source emitted
 * by the compiler never contains raw template expressions as executable PHP.
 */
final class PhpCompiledRuntime
{
    public function __construct(
        private readonly ExpressionEvaluator $expressions,
        private readonly TemplateSource $template,
        private readonly EngineOptions $options,
    ) {
    }

    public function expression(string $expression, Context $context, int $line): mixed
    {
        try {
            return $this->expressions->evaluate($expression, $context);
        } catch (SyntaxErrorException $exception) {
            if ($exception->template !== null) {
                throw $exception;
            }
            throw new SyntaxErrorException($exception->getMessage(), $this->template->name, $line);
        }
    }

    public function value(string $expression, Context $context, int $line): mixed
    {
        $expression = trim($expression);
        if ($expression === '') {
            return '';
        }
        if (str_starts_with(strtolower($expression), 'call ')) {
            throw new SyntaxErrorException('Compiled fast path does not accept call expressions.', $this->template->name, $line);
        }
        if (ExpressionSyntax::isFormal($expression)) {
            return $this->expression($expression, $context, $line);
        }

        return $this->interpolate($expression, $context, null, false);
    }

    public function renderPrint(string $expression, Context $context, RuntimeState $state, bool $raw, int $line): string
    {
        $expression = trim($expression);
        if ($expression === '') {
            return '';
        }
        if (str_starts_with(strtolower($expression), 'call ')) {
            throw new SyntaxErrorException('Compiled fast path does not accept call expressions.', $this->template->name, $line);
        }
        if (ExpressionSyntax::isFormal($expression)) {
            $value = $this->expression($expression, $context, $line);
            $string = $this->stringify($value);
            if ($value instanceof Markup) {
                return $string;
            }
            return !$raw && $state->autoEscapeEnabled() ? Escaper::escape($string, $state->escapeStrategy()) : $string;
        }

        return $this->interpolate($expression, $context, $state, !$raw && $state->autoEscapeEnabled());
    }

    /** @return array<array-key, mixed> */
    public function materializeIterable(mixed $value, RuntimeState $state, int $line): array
    {
        $remaining = $state->remainingLoopIterations();
        if (is_array($value)) {
            if (count($value) > $remaining) {
                throw new SyntaxErrorException('foreach would exceed the loop iteration limit.', $this->template->name, $line);
            }
            return $value;
        }
        if (!$value instanceof Traversable) {
            throw new SyntaxErrorException('foreach expression must evaluate to an array or Traversable.', $this->template->name, $line);
        }

        $items = [];
        foreach ($value as $key => $item) {
            if (count($items) >= $remaining) {
                throw new SyntaxErrorException('foreach would exceed the loop iteration limit.', $this->template->name, $line);
            }
            $items[$key] = $item;
        }
        return $items;
    }

    /** @return array<string, mixed> */
    public function loopScope(
        mixed $key,
        mixed $value,
        string $valueName,
        ?string $keyName,
        int $offset,
        int $length,
        mixed $parentLoop,
    ): array {
        $loop = [
            'index' => $offset + 1,
            'index0' => $offset,
            'iteration' => $offset + 1,
            'revindex' => $length - $offset,
            'revindex0' => $length - $offset - 1,
            'remaining' => $length - $offset - 1,
            'first' => $offset === 0,
            'last' => $offset === $length - 1,
            'even' => (($offset + 1) % 2) === 0,
            'odd' => (($offset + 1) % 2) === 1,
            'length' => $length,
            'count' => $length,
            'depth' => is_array($parentLoop) ? ((int) ($parentLoop['depth'] ?? 0) + 1) : 1,
            'parent' => is_array($parentLoop) ? $parentLoop : null,
        ];
        $scope = [$valueName => $value, '$loop' => $loop];
        if ($keyName !== null) {
            $scope[$keyName] = $key;
        }
        return $scope;
    }

    public static function truthy(mixed $value): bool
    {
        return ExpressionEvaluator::truthy($value);
    }

    private function interpolate(string $value, Context $context, ?RuntimeState $state, bool $escape): string
    {
        $placeholder = "\0XT_DOLLAR\0";
        $value = str_replace('\\$', $placeholder, $value);
        $value = preg_replace_callback('/\$[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*/', function (array $match) use ($context, $state, $escape): string {
            if ($this->options->strictVariables && !$context->has($match[0])) {
                throw new SyntaxErrorException(sprintf('Undefined variable "%s".', $match[0]), $this->template->name);
            }
            $resolved = $context->get($match[0], '');
            $string = $this->stringify($resolved);
            return $escape && !$resolved instanceof Markup && $state !== null ? Escaper::escape($string, $state->escapeStrategy()) : $string;
        }, $value) ?? $value;
        return str_replace($placeholder, '$', $value);
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            $value === true => '1',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => throw new SyntaxErrorException(sprintf('Cannot print value of type %s.', get_debug_type($value)), $this->template->name),
        };
    }

}
