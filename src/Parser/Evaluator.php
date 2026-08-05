<?php

declare(strict_types=1);

namespace XtScript\Parser;

use Closure;
use Throwable;
use Traversable;
use XtScript\Ast\Instruction;
use XtScript\Ast\InstructionType;
use XtScript\Ast\Program;
use XtScript\Ast\UserFunction;
use XtScript\Context;
use XtScript\Contract\FragmentCacheInterface;
use XtScript\Contract\LoaderInterface;
use XtScript\Contract\SecurityPolicyInterface;
use XtScript\EngineOptions;
use XtScript\EscapeStrategy;
use XtScript\Escaper;
use XtScript\Formatter\CodeFormatter;
use XtScript\Markup;
use XtScript\Exception\PluginException;
use XtScript\Exception\SecurityException;
use XtScript\Exception\SyntaxErrorException;
use XtScript\Exception\XtScriptException;
use XtScript\Plugin\BlockTagDefinition;
use XtScript\Plugin\FunctionContext;
use XtScript\Plugin\FunctionDefinition;
use XtScript\Plugin\TagDefinition;
use XtScript\Runtime\BreakSignal;
use XtScript\Runtime\ContinueSignal;
use XtScript\Runtime\ReturnSignal;
use XtScript\Runtime\RuntimeState;
use XtScript\TemplateReference;
use XtScript\TemplateSource;

final class Evaluator
{
    /** @var Closure(TemplateSource): Program */
    private Closure $compiler;

    /** @var array<string, list<array{body:list<Instruction>,template:TemplateSource}>> */
    private array $blockChains = [];

    /** @var list<array{name:string,index:int,chain:list<array{body:list<Instruction>,template:TemplateSource}>}> */
    private array $blockStack = [];

    /**
     * @param array<string, FunctionDefinition> $functions
     * @param array<string, TagDefinition> $tags
     * @param array<string, BlockTagDefinition> $blockTags
     * @param callable(TemplateSource): Program $compiler
     */
    public function __construct(
        private readonly LoaderInterface $loader,
        private readonly EngineOptions $options,
        private readonly ExpressionEvaluator $expressions,
        private readonly array $functions,
        private readonly array $tags,
        private readonly array $blockTags,
        callable $compiler,
        private readonly ?FragmentCacheInterface $fragmentCache = null,
        private readonly ?SecurityPolicyInterface $securityPolicy = null,
    ) {
        $this->compiler = Closure::fromCallable($compiler);
    }

    /** @param array<string, UserFunction> $userFunctions */
    public function evaluate(
        Program $program,
        TemplateSource $template,
        Context $context,
        RuntimeState $state,
        array &$userFunctions = [],
    ): string {
        $previousChains = $this->blockChains;
        $previousStack = $this->blockStack;
        $this->blockChains = [];
        $this->blockStack = [];

        try {
            [$rootProgram, $rootTemplate, $chains, $deferredFunctions] = $this->resolveInheritance($program, $template, $state);
            $this->blockChains = $chains;
            foreach ($deferredFunctions as [$instruction, $owner]) {
                $this->registerUserFunction($instruction, $owner, $userFunctions);
            }

            return $this->evaluateBlock($rootProgram->instructions, $rootTemplate, $context, $state, $userFunctions, false);
        } catch (ReturnSignal) {
            throw new SyntaxErrorException('return may only be used inside a function.', $template->name);
        } catch (BreakSignal) {
            throw new SyntaxErrorException('break may only be used inside foreach/for or switch.', $template->name);
        } catch (ContinueSignal) {
            throw new SyntaxErrorException('continue may only be used inside foreach/for.', $template->name);
        } finally {
            $this->blockChains = $previousChains;
            $this->blockStack = $previousStack;
        }
    }

    /**
     * @return array{0:Program,1:TemplateSource,2:array<string,list<array{body:list<Instruction>,template:TemplateSource}>>,3:list<array{0:Instruction,1:TemplateSource}>}
     */
    private function resolveInheritance(Program $program, TemplateSource $template, RuntimeState $state): array
    {
        $currentProgram = $program;
        $currentTemplate = $template;
        $chains = [];
        $deferredFunctions = [];
        $seen = [];
        $inheritanceDepth = 0;

        while (true) {
            $identity = $currentTemplate->origin . "\0" . $currentTemplate->name;
            if (isset($seen[$identity])) {
                throw new SyntaxErrorException('Circular template inheritance detected.', $currentTemplate->name);
            }
            $seen[$identity] = true;

            $extends = null;
            foreach ($currentProgram->instructions as $instruction) {
                if ($instruction->type === InstructionType::Extends) {
                    if ($extends !== null) {
                        throw $this->syntax('A template may only contain one extends statement.', $currentTemplate, $instruction->line);
                    }
                    $extends = $instruction;
                    continue;
                }
                if ($instruction->type === InstructionType::Block) {
                    $name = (string) $instruction->arguments['name'];
                    $chains[$name] ??= [];
                    $chains[$name][] = ['body' => $instruction->body, 'template' => $currentTemplate];
                    continue;
                }
                if ($extends !== null && $instruction->type === InstructionType::Function) {
                    $deferredFunctions[] = [$instruction, $currentTemplate];
                }
            }

            if ($extends === null) {
                return [$currentProgram, $currentTemplate, $chains, $deferredFunctions];
            }

            if (++$inheritanceDepth > $this->options->maxIncludeDepth) {
                throw new XtScriptException('Maximum template inheritance depth exceeded.');
            }
            $parentName = (string) $extends->arguments['template'];
            $state->enterInclude();
            try {
                $this->assertTemplateAllowed($parentName, $currentTemplate->name);
                $currentTemplate = $this->loader->load($parentName, $currentTemplate->name);
                $currentProgram = ($this->compiler)($currentTemplate);
            } finally {
                $state->leaveInclude();
            }
        }
    }

    /**
     * @param list<Instruction> $instructions
     * @param array<string, UserFunction> $userFunctions
     */
    private function evaluateBlock(
        array $instructions,
        TemplateSource $template,
        Context $context,
        RuntimeState $state,
        array &$userFunctions,
        bool $allowReturn = false,
    ): string {
        $labels = [];
        foreach ($instructions as $index => $instruction) {
            if ($instruction->type === InstructionType::Label) {
                $label = (string) $instruction->arguments['name'];
                if (array_key_exists($label, $labels)) {
                    throw $this->syntax(sprintf('Duplicate label "%s".', $label), $template, $instruction->line);
                }
                $labels[$label] = $index;
            }
        }

        $output = '';
        $count = count($instructions);
        for ($pc = 0; $pc < $count; ++$pc) {
            $state->tick();
            $instruction = $instructions[$pc];
            $this->assertInstructionAllowed($instruction, $template);

            try {
                switch ($instruction->type) {
                    case InstructionType::Text:
                        $chunk = (string) ($instruction->arguments['text'] ?? '');
                        $state->addOutput($chunk);
                        $output .= $chunk;
                        break;

                    case InstructionType::Assign:
                        $context->set(
                            (string) $instruction->arguments['name'],
                            $this->evaluateValue((string) $instruction->arguments['expression'], $template, $context, $state, $userFunctions),
                        );
                        break;

                    case InstructionType::Delete:
                        $context->delete((string) $instruction->arguments['name']);
                        break;

                    case InstructionType::Get:
                        // Input arrays are normalized by Context, so legacy `get foo`
                        // is intentionally a no-op that preserves `$foo`.
                        break;

                    case InstructionType::GetOrDefault:
                        $name = (string) $instruction->arguments['name'];
                        $current = $context->get($name, null);
                        if (!ExpressionEvaluator::truthy($current)) {
                            $context->set($name, $this->evaluateValue((string) $instruction->arguments['default'], $template, $context, $state, $userFunctions));
                        }
                        break;

                    case InstructionType::Print:
                        $chunk = $this->renderPrint((string) $instruction->arguments['expression'], $template, $context, $state, $userFunctions, false);
                        $state->addOutput($chunk);
                        $output .= $chunk;
                        break;

                    case InstructionType::PrintRaw:
                        $chunk = $this->renderPrint((string) $instruction->arguments['expression'], $template, $context, $state, $userFunctions, true);
                        $state->addOutput($chunk);
                        $output .= $chunk;
                        break;

                    case InstructionType::Return:
                        if (!$allowReturn) {
                            throw $this->syntax('return may only be used inside a function.', $template, $instruction->line);
                        }
                        throw new ReturnSignal($this->evaluateValue((string) $instruction->arguments['expression'], $template, $context, $state, $userFunctions));

                    case InstructionType::Call:
                        $value = $this->invokeCall((string) $instruction->arguments['call'], $template, $context, $state, $userFunctions);
                        $chunk = $this->stringify($value);
                        $state->addOutput($chunk);
                        $output .= $chunk;
                        break;

                    case InstructionType::Include:
                        foreach ($instruction->arguments['templates'] as $name) {
                            $state->enterInclude();
                            try {
                                $includeName = (string) $name;
                                $this->assertTemplateAllowed($includeName, $template->name);
                                $included = $this->loader->load($includeName, $template->name);
                                $program = ($this->compiler)($included);
                                $output .= $this->evaluateNestedTemplate($program, $included, $context, $state, $userFunctions);
                            } finally {
                                $state->leaveInclude();
                            }
                        }
                        break;

                    case InstructionType::Extends:
                        // Resolved before the root template starts rendering.
                        break;

                    case InstructionType::Block:
                        $name = (string) $instruction->arguments['name'];
                        $chain = $this->blockChains[$name] ?? [['body' => $instruction->body, 'template' => $template]];
                        $output .= $this->evaluateBlockChain($name, $chain, 0, $context, $state, $userFunctions);
                        break;

                    case InstructionType::Parent:
                        $frame = $this->blockStack[array_key_last($this->blockStack)] ?? null;
                        if ($frame === null) {
                            throw $this->syntax('parent may only be used inside an overridden block.', $template, $instruction->line);
                        }
                        $activeTemplate = $frame['chain'][$frame['index']]['template'];
                        if ($activeTemplate->name !== $template->name) {
                            throw $this->syntax('parent may not be called from an included/component template.', $template, $instruction->line);
                        }
                        $parentIndex = $frame['index'] + 1;
                        if (!isset($frame['chain'][$parentIndex])) {
                            throw $this->syntax(sprintf('Block "%s" has no parent implementation.', $frame['name']), $template, $instruction->line);
                        }
                        $output .= $this->evaluateBlockChain($frame['name'], $frame['chain'], $parentIndex, $context, $state, $userFunctions);
                        break;

                    case InstructionType::If:
                        $matched = false;
                        foreach ($instruction->arguments['branches'] as $branch) {
                            if (ExpressionEvaluator::truthy($this->expressions->evaluate((string) $branch['condition'], $context))) {
                                $output .= $this->evaluateBlock($branch['body'], $template, $context, $state, $userFunctions, $allowReturn);
                                $matched = true;
                                break;
                            }
                        }
                        if (!$matched && $instruction->alternate !== []) {
                            $output .= $this->evaluateBlock($instruction->alternate, $template, $context, $state, $userFunctions, $allowReturn);
                        }
                        break;

                    case InstructionType::Foreach:
                        $iterable = $this->evaluateValue((string) $instruction->arguments['expression'], $template, $context, $state, $userFunctions);
                        $items = $this->materializeIterable($iterable, $state, $template, $instruction->line);
                        if ($items === []) {
                            if ($instruction->alternate !== []) {
                                $output .= $this->evaluateBlock($instruction->alternate, $template, $context, $state, $userFunctions, $allowReturn);
                            }
                            break;
                        }

                        $length = count($items);
                        $parentLoop = $context->get('$loop', null);
                        $offset = 0;
                        foreach ($items as $key => $value) {
                            $state->loop();
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
                            $scope = [
                                (string) $instruction->arguments['value'] => $value,
                                '$loop' => $loop,
                            ];
                            if ($instruction->arguments['key'] !== null) {
                                $scope[(string) $instruction->arguments['key']] = $key;
                            }
                            $context->push($scope);
                            try {
                                try {
                                    $output .= $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                                } catch (ContinueSignal $signal) {
                                    $output .= $signal->output;
                                    // Continue only the current loop.
                                } catch (BreakSignal $signal) {
                                    $output .= $signal->output;
                                    break;
                                }
                            } finally {
                                $context->pop();
                            }
                            ++$offset;
                        }
                        break;

                    case InstructionType::Switch:
                        $subject = $this->evaluateValue((string) $instruction->arguments['expression'], $template, $context, $state, $userFunctions);
                        $matched = false;
                        foreach ($instruction->arguments['cases'] as $case) {
                            $caseValue = $this->evaluateValue((string) $case['expression'], $template, $context, $state, $userFunctions);
                            if ($subject == $caseValue) {
                                try {
                                    $output .= $this->evaluateBlock($case['body'], $template, $context, $state, $userFunctions, $allowReturn);
                                } catch (BreakSignal $signal) {
                                    $output .= $signal->output;
                                    // PHP/Blade-like break terminates the switch.
                                }
                                $matched = true;
                                break;
                            }
                        }
                        if (!$matched && $instruction->alternate !== []) {
                            try {
                                $output .= $this->evaluateBlock($instruction->alternate, $template, $context, $state, $userFunctions, $allowReturn);
                            } catch (BreakSignal $signal) {
                                $output .= $signal->output;
                                // Break terminates the switch default branch.
                            }
                        }
                        break;

                    case InstructionType::Break:
                        throw new BreakSignal();

                    case InstructionType::Continue:
                        throw new ContinueSignal();

                    case InstructionType::Component:
                        $output .= $this->renderComponent($instruction, $template, $context, $state, $userFunctions);
                        break;

                    case InstructionType::Capture:
                        $state->enterCapture();
                        try {
                            $captured = $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                        } finally {
                            $state->leaveCapture();
                        }
                        $context->set((string) $instruction->arguments['name'], new Markup($captured));
                        break;

                    case InstructionType::Cache:
                        if ($this->fragmentCache === null) {
                            $output .= $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                            break;
                        }
                        $cacheKeyValue = $this->evaluateValue((string) $instruction->arguments['key'], $template, $context, $state, $userFunctions);
                        $cacheKey = $this->stringify($cacheKeyValue);
                        if ($cacheKey === '' || strlen($cacheKey) > $this->options->maxFragmentCacheKeyBytes) {
                            throw $this->syntax('Fragment cache key is empty or exceeds the configured limit.', $template, $instruction->line);
                        }
                        $ttl = (int) $this->evaluateValue((string) $instruction->arguments['ttl'], $template, $context, $state, $userFunctions);
                        $ttl = max(1, min($this->options->maxFragmentCacheTtlSeconds, $ttl));
                        $storageKey = hash('sha256', $template->origin . "\0" . $template->name . "\0" . $cacheKey);
                        $cached = $this->fragmentCache->get($storageKey);
                        if ($cached !== null) {
                            $state->addOutput($cached);
                            $output .= $cached;
                            break;
                        }

                        $state->enterCapture();
                        try {
                            $fragment = $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                        } finally {
                            $state->leaveCapture();
                        }
                        $this->fragmentCache->set($storageKey, $fragment, $ttl);
                        $state->addOutput($fragment);
                        $output .= $fragment;
                        break;

                    case InstructionType::With:
                        $scope = [];
                        foreach ($this->parseArguments((string) $instruction->arguments['arguments']) as $name => $expression) {
                            $scope[$name] = $this->evaluateValue($expression, $template, $context, $state, $userFunctions);
                        }
                        $context->push($scope);
                        try {
                            $output .= $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                        } finally {
                            $context->pop();
                        }
                        break;

                    case InstructionType::Do:
                        $this->evaluateValue((string) $instruction->arguments['expression'], $template, $context, $state, $userFunctions);
                        break;

                    case InstructionType::Once:
                        $expression = trim((string) $instruction->arguments['arguments']);
                        $key = $expression === ''
                            ? $template->origin . ':' . $template->name . ':' . $instruction->line
                            : $this->stringify($this->evaluateValue($expression, $template, $context, $state, $userFunctions));
                        if ($key === '') {
                            throw $this->syntax('once key cannot be empty.', $template, $instruction->line);
                        }
                        if ($state->once(hash('sha256', $key))) {
                            $output .= $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                        }
                        break;

                    case InstructionType::Apply:
                        $state->enterCapture();
                        try {
                            $captured = $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                        } finally {
                            $state->leaveCapture();
                        }
                        $context->push(['$__xt_apply' => new Markup($captured)]);
                        try {
                            $applied = $this->expressions->evaluate('$__xt_apply | ' . (string) $instruction->arguments['arguments'], $context);
                        } finally {
                            $context->pop();
                        }
                        $chunk = $this->stringify($applied);
                        $state->addOutput($chunk);
                        $output .= $chunk;
                        break;

                    case InstructionType::AutoEscape:
                        $state->pushEscapeStrategy(EscapeStrategy::fromTemplateValue((string) $instruction->arguments['strategy']));
                        try {
                            $output .= $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                        } finally {
                            $state->popAutoEscape();
                        }
                        break;

                    case InstructionType::Beautify:
                    case InstructionType::Minify:
                        $state->enterCapture();
                        try {
                            $captured = $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                        } finally {
                            $state->leaveCapture();
                        }
                        $language = (string) $instruction->arguments['language'];
                        $formatted = match ([$instruction->type, $language]) {
                            [InstructionType::Beautify, 'html'] => CodeFormatter::beautifyHtml($captured),
                            [InstructionType::Beautify, 'css'] => CodeFormatter::beautifyCss($captured),
                            [InstructionType::Beautify, 'js'] => CodeFormatter::beautifyJs($captured),
                            [InstructionType::Minify, 'css'] => CodeFormatter::minifyCss($captured),
                            [InstructionType::Minify, 'js'] => CodeFormatter::minifyJs($captured),
                            default => throw $this->syntax('Unsupported formatter block language.', $template, $instruction->line),
                        };
                        $state->addOutput($formatted);
                        $output .= $formatted;
                        break;

                    case InstructionType::Import:
                        $importName = (string) $instruction->arguments['template'];
                        $this->assertTemplateAllowed($importName, $template->name);
                        $state->enterInclude();
                        try {
                            $imported = $this->loader->load($importName, $template->name);
                            $importedProgram = ($this->compiler)($imported);
                            $importStack = [$template->name => true];
                            $this->registerImportedFunctions(
                                $importedProgram,
                                $imported,
                                $instruction->arguments['namespace'] !== null ? (string) $instruction->arguments['namespace'] : null,
                                $userFunctions,
                                $state,
                                $importStack,
                            );
                        } finally {
                            $state->leaveInclude();
                        }
                        break;

                    case InstructionType::Verbatim:
                        $chunk = (string) $instruction->arguments['text'];
                        $state->addOutput($chunk);
                        $output .= $chunk;
                        break;

                    case InstructionType::Push:
                    case InstructionType::Prepend:
                        $state->enterCapture();
                        try {
                            $captured = $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                        } finally {
                            $state->leaveCapture();
                        }
                        $state->pushStack(
                            (string) $instruction->arguments['name'],
                            $captured,
                            $instruction->type === InstructionType::Prepend,
                        );
                        break;

                    case InstructionType::Stack:
                        $chunk = $state->stack((string) $instruction->arguments['name']);
                        $state->addOutput($chunk);
                        $output .= $chunk;
                        break;

                    case InstructionType::Function:
                        $this->registerUserFunction($instruction, $template, $userFunctions);
                        break;

                    case InstructionType::Label:
                        break;

                    case InstructionType::Goto:
                        $label = (string) $instruction->arguments['label'];
                        if (!array_key_exists($label, $labels)) {
                            throw $this->syntax(sprintf('Undefined label "%s".', $label), $template, $instruction->line);
                        }
                        $pc = $labels[$label];
                        break;

                    case InstructionType::CustomBlockTag:
                        $name = (string) $instruction->arguments['name'];
                        $definition = $this->blockTags[$name] ?? null;
                        if ($definition === null) {
                            throw $this->syntax(sprintf('Unknown block tag "%s".', $name), $template, $instruction->line);
                        }
                        $renderBody = function () use ($instruction, $template, $context, $state, &$userFunctions, $allowReturn): string {
                            $state->enterCapture();
                            try {
                                return $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, $allowReturn);
                            } finally {
                                $state->leaveCapture();
                            }
                        };
                        try {
                            $value = ($definition->handler)(
                                new FunctionContext($context, $template, $this->loader, $state, $this->options),
                                (string) $instruction->arguments['arguments'],
                                $renderBody,
                            );
                        } catch (BreakSignal|ContinueSignal|ReturnSignal|XtScriptException $exception) {
                            throw $exception;
                        } catch (Throwable $exception) {
                            throw new PluginException(sprintf('Block tag "%s" failed: %s', $name, $exception->getMessage()), previous: $exception);
                        }
                        $chunk = $this->stringify($value);
                        $state->addOutput($chunk);
                        $output .= $chunk;
                        break;

                    case InstructionType::CustomTag:
                        $name = (string) $instruction->arguments['name'];
                        $tag = $this->tags[$name] ?? null;
                        if ($tag === null || $tag->handler === null) {
                            throw $this->syntax(sprintf('Unknown tag "%s".', $name), $template, $instruction->line);
                        }
                        try {
                            $value = ($tag->handler)(new FunctionContext($context, $template, $this->loader, $state, $this->options), (string) $instruction->arguments['arguments']);
                        } catch (XtScriptException $exception) {
                            throw $exception;
                        } catch (Throwable $exception) {
                            throw new PluginException(sprintf('Tag "%s" failed: %s', $name, $exception->getMessage()), previous: $exception);
                        }
                        $chunk = $this->stringify($value);
                        $state->addOutput($chunk);
                        $output .= $chunk;
                        break;
                }
            } catch (ReturnSignal $signal) {
                throw new ReturnSignal($signal->value, $output . $signal->output);
            } catch (BreakSignal $signal) {
                throw new BreakSignal($output . $signal->output);
            } catch (ContinueSignal $signal) {
                throw new ContinueSignal($output . $signal->output);
            } catch (SyntaxErrorException $exception) {
                if ($exception->template !== null) {
                    throw $exception;
                }
                throw $this->syntax($exception->getMessage(), $template, $instruction->line);
            } catch (XtScriptException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw $this->syntax($exception->getMessage(), $template, $instruction->line);
            }
        }

        return $output;
    }

    /** @param array<string, UserFunction> $userFunctions */
    private function evaluateValue(
        string $expression,
        TemplateSource $template,
        Context $context,
        RuntimeState $state,
        array &$userFunctions,
    ): mixed {
        $expression = trim($expression);
        if ($expression === '') {
            return '';
        }
        if (str_starts_with(strtolower($expression), 'call ')) {
            return $this->invokeCall(substr($expression, 5), $template, $context, $state, $userFunctions);
        }
        if (ExpressionSyntax::isFormal($expression)) {
            return $this->expressions->evaluate($expression, $context);
        }

        return $this->interpolate($expression, $context, $state, false);
    }

    /** @param array<string, UserFunction> $userFunctions */
    private function renderPrint(
        string $expression,
        TemplateSource $template,
        Context $context,
        RuntimeState $state,
        array &$userFunctions,
        bool $raw,
    ): string {
        $expression = trim($expression);
        if ($expression === '') {
            return '';
        }
        if (str_starts_with(strtolower($expression), 'call ') || ExpressionSyntax::isFormal($expression)) {
            $value = $this->evaluateValue($expression, $template, $context, $state, $userFunctions);
            $string = $this->stringify($value);
            if ($value instanceof Markup) {
                return $string;
            }
            return !$raw && $state->autoEscapeEnabled() ? Escaper::escape($string, $state->escapeStrategy()) : $string;
        }

        return $this->interpolate($expression, $context, $state, !$raw && $state->autoEscapeEnabled());
    }

    /** @param array<string, UserFunction> $userFunctions */
    private function invokeCall(
        string $call,
        TemplateSource $template,
        Context $context,
        RuntimeState $state,
        array &$userFunctions,
    ): mixed {
        [$name, $argumentSource] = $this->splitCommand(trim($call));
        if ($name === '') {
            throw new SyntaxErrorException('call requires a function name.');
        }

        if ($this->securityPolicy !== null && !$this->securityPolicy->allowsFunction($name)) {
            throw new SecurityException(sprintf('Function "%s" is not allowed by the security policy.', $name));
        }

        $rawArguments = $this->parseArguments($argumentSource);
        $arguments = [];
        foreach ($rawArguments as $key => $value) {
            $arguments[$key] = $this->evaluateValue($value, $template, $context, $state, $userFunctions);
        }

        $userFunction = $this->resolveUserFunction($name, $state, $userFunctions);
        if ($userFunction !== null) {
            return $this->invokeUserFunction($userFunction, $arguments, $context, $state, $userFunctions);
        }

        $definition = $this->functions[$name] ?? null;
        if ($definition === null) {
            throw new SyntaxErrorException(sprintf('Undefined function "%s".', $name));
        }

        try {
            return ($definition->handler)(new FunctionContext($context, $template, $this->loader, $state, $this->options), $arguments);
        } catch (XtScriptException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PluginException(sprintf('Function "%s" failed: %s', $name, $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, UserFunction> $userFunctions
     */
    private function invokeUserFunction(
        UserFunction $function,
        array $arguments,
        Context $caller,
        RuntimeState $state,
        array &$userFunctions,
    ): mixed {
        $state->enterFunction();
        $state->pushFunctionNamespace($function->namespace);
        try {
            $scope = [];
            foreach ($function->parameters as $name => $defaultExpression) {
                $scope[$name] = array_key_exists($name, $arguments)
                    ? $arguments[$name]
                    : $this->evaluateValue($defaultExpression, $function->template, $caller, $state, $userFunctions);
            }
            foreach ($arguments as $name => $value) {
                if (!array_key_exists($name, $scope)) {
                    $scope[$name] = $value;
                }
            }

            $local = $caller->fork($scope, true);
            try {
                return $this->evaluateBlock($function->body, $function->template, $local, $state, $userFunctions, true);
            } catch (ReturnSignal $signal) {
                return $signal->output . $this->stringify($signal->value);
            }
        } finally {
            $state->popFunctionNamespace();
            $state->leaveFunction();
        }
    }

    /**
     * @param array<string, UserFunction> $userFunctions
     * @param array<string, true> $importStack
     */
    private function registerImportedFunctions(
        Program $program,
        TemplateSource $template,
        ?string $namespace,
        array &$userFunctions,
        RuntimeState $state,
        array &$importStack,
    ): void {
        if (isset($importStack[$template->name])) {
            throw new XtScriptException(sprintf('Circular function import detected for "%s".', $template->name));
        }

        $importStack[$template->name] = true;
        try {
            // Resolve a library's own imports first. Namespaces are lexical:
            // importing forms as forms and helpers.tpl as h inside forms
            // exposes helpers as forms@h@*.
            foreach ($program->instructions as $instruction) {
                if ($instruction->type !== InstructionType::Import) {
                    continue;
                }

                $importName = (string) $instruction->arguments['template'];
                $this->assertTemplateAllowed($importName, $template->name);
                $childNamespace = $instruction->arguments['namespace'] !== null
                    ? (string) $instruction->arguments['namespace']
                    : null;
                $resolvedNamespace = $this->combineFunctionNamespace($namespace, $childNamespace);

                $state->enterInclude();
                try {
                    $imported = $this->loader->load($importName, $template->name);
                    $importedProgram = ($this->compiler)($imported);
                    $this->registerImportedFunctions(
                        $importedProgram,
                        $imported,
                        $resolvedNamespace,
                        $userFunctions,
                        $state,
                        $importStack,
                    );
                } finally {
                    $state->leaveInclude();
                }
            }

            foreach ($program->instructions as $instruction) {
                if ($instruction->type !== InstructionType::Function) {
                    continue;
                }
                if ($namespace === null) {
                    $this->registerUserFunction($instruction, $template, $userFunctions);
                    continue;
                }

                $originalName = (string) $instruction->arguments['name'];
                $name = $namespace . '@' . ltrim($originalName, '@');
                if (isset($userFunctions[$name]) || isset($userFunctions['@' . $name])) {
                    throw $this->syntax(sprintf('Function "%s" is already defined.', $name), $template, $instruction->line);
                }
                $function = new UserFunction(
                    $name,
                    $instruction->arguments['parameters'],
                    $instruction->body,
                    $template,
                    $instruction->line,
                    $namespace,
                );
                $userFunctions[$name] = $function;
                $userFunctions['@' . $name] = $function;
            }
        } finally {
            unset($importStack[$template->name]);
        }
    }

    private function combineFunctionNamespace(?string $parent, ?string $child): ?string
    {
        if ($parent === null || $parent === '') {
            return $child;
        }
        if ($child === null || $child === '') {
            return $parent;
        }

        return $parent . '@' . $child;
    }

    /** @param array<string, UserFunction> $userFunctions */
    private function resolveUserFunction(string $name, RuntimeState $state, array $userFunctions): ?UserFunction
    {
        $normalized = ltrim($name, '@');
        $namespace = $state->currentFunctionNamespace();

        if ($namespace !== '') {
            $lexical = str_starts_with($normalized, $namespace . '@')
                ? $normalized
                : $namespace . '@' . $normalized;
            if (isset($userFunctions[$lexical])) {
                return $userFunctions[$lexical];
            }
            if (isset($userFunctions['@' . $lexical])) {
                return $userFunctions['@' . $lexical];
            }

            // A namespaced library does not inherit caller-owned user functions.
            // Dependencies must be imported explicitly so capability boundaries
            // remain deterministic and collision-free.
            return null;
        }

        if (isset($userFunctions[$name])) {
            return $userFunctions[$name];
        }
        if (isset($userFunctions[$normalized])) {
            return $userFunctions[$normalized];
        }
        if (isset($userFunctions['@' . $normalized])) {
            return $userFunctions['@' . $normalized];
        }

        return null;
    }

    /** @param array<string, UserFunction> $userFunctions */
    private function registerUserFunction(Instruction $instruction, TemplateSource $template, array &$userFunctions): void
    {
        $name = (string) $instruction->arguments['name'];
        if (isset($userFunctions[$name]) || isset($userFunctions['@' . $name])) {
            throw $this->syntax(sprintf('Function "%s" is already defined.', $name), $template, $instruction->line);
        }
        $function = new UserFunction($name, $instruction->arguments['parameters'], $instruction->body, $template, $instruction->line);
        $userFunctions[$name] = $function;
        $userFunctions['@' . $name] = $function;
    }

    /**
     * @param list<array{body:list<Instruction>,template:TemplateSource}> $chain
     * @param array<string, UserFunction> $userFunctions
     */
    private function evaluateBlockChain(
        string $name,
        array $chain,
        int $index,
        Context $context,
        RuntimeState $state,
        array &$userFunctions,
    ): string {
        $entry = $chain[$index] ?? null;
        if ($entry === null) {
            return '';
        }

        $this->blockStack[] = ['name' => $name, 'index' => $index, 'chain' => $chain];
        try {
            return $this->evaluateBlock($entry['body'], $entry['template'], $context, $state, $userFunctions, false);
        } finally {
            array_pop($this->blockStack);
        }
    }

    /** @param array<string, UserFunction> $userFunctions */
    private function evaluateNestedTemplate(
        Program $program,
        TemplateSource $template,
        Context $context,
        RuntimeState $state,
        array &$userFunctions,
    ): string {
        $nested = new self($this->loader, $this->options, $this->expressions, $this->functions, $this->tags, $this->blockTags, $this->compiler, $this->fragmentCache, $this->securityPolicy);
        return $nested->evaluate($program, $template, $context, $state, $userFunctions);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function materializeIterable(mixed $iterable, RuntimeState $state, TemplateSource $template, int $line): array
    {
        $remaining = $state->remainingLoopIterations();
        if (is_array($iterable)) {
            if (count($iterable) > $remaining) {
                throw $this->syntax('foreach/for would exceed the loop iteration limit.', $template, $line);
            }
            return $iterable;
        }
        if (!$iterable instanceof Traversable) {
            throw $this->syntax('foreach/for expression must evaluate to an array or Traversable.', $template, $line);
        }

        $items = [];
        foreach ($iterable as $key => $value) {
            if (count($items) >= $remaining) {
                throw $this->syntax('foreach/for would exceed the loop iteration limit.', $template, $line);
            }
            $items[$key] = $value;
        }
        return $items;
    }

    /** @param array<string, UserFunction> $userFunctions */
    private function renderComponent(
        Instruction $instruction,
        TemplateSource $template,
        Context $context,
        RuntimeState $state,
        array &$userFunctions,
    ): string {
        $arguments = [];
        foreach ($this->parseArguments((string) $instruction->arguments['arguments']) as $name => $expression) {
            $arguments[$name] = $this->evaluateValue($expression, $template, $context, $state, $userFunctions);
        }

        $state->enterCapture();
        try {
            $defaultSlot = $this->evaluateBlock($instruction->body, $template, $context, $state, $userFunctions, false);
            $slots = [];
            foreach ($instruction->arguments['slots'] as $name => $slotBody) {
                $slots[$name] = new Markup($this->evaluateBlock($slotBody, $template, $context, $state, $userFunctions, false));
            }
        } finally {
            $state->leaveCapture();
        }

        $arguments['$slot'] = new Markup($defaultSlot);
        $arguments['$slots'] = $slots;
        $componentContext = $context->fork($arguments, true);

        $state->enterInclude();
        try {
            $componentName = (string) $instruction->arguments['template'];
            $this->assertTemplateAllowed($componentName, $template->name);
            $component = $this->loader->load($componentName, $template->name);
            $program = ($this->compiler)($component);
            return $this->evaluateNestedTemplate($program, $component, $componentContext, $state, $userFunctions);
        } finally {
            $state->leaveInclude();
        }
    }

    /** @return array<string, string> */
    private function parseArguments(string $source): array
    {
        $arguments = [];
        foreach ($this->splitDelimited($source, ';') as $index => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $key = trim($pair[0]);
                if ($key === '') {
                    throw new SyntaxErrorException('Function argument name cannot be empty.');
                }
                $arguments[$key] = trim($pair[1]);
            } else {
                $arguments[(string) $index] = $part;
            }
        }
        return $arguments;
    }

    /** @return list<string> */
    private function splitDelimited(string $source, string $delimiter): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;
        $depth = 0;
        $escaped = false;

        foreach (str_split($source) as $char) {
            if ($escaped) {
                $buffer .= $char;
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $buffer .= $char;
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '(') {
                ++$depth;
                $buffer .= $char;
                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
                $buffer .= $char;
                continue;
            }
            if ($char === $delimiter && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $parts[] = $buffer;
        return $parts;
    }

    private function interpolate(string $value, Context $context, RuntimeState $state, bool $escape): string
    {
        $placeholder = "\0XT_DOLLAR\0";
        $value = str_replace('\\$', $placeholder, $value);
        $value = preg_replace_callback('/\$[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*/', function (array $match) use ($context, $state, $escape): string {
            if ($this->options->strictVariables && !$context->has($match[0])) {
                throw new SyntaxErrorException(sprintf('Undefined variable "%s".', $match[0]));
            }
            $resolved = $context->get($match[0], '');
            $string = $this->stringify($resolved);
            if ($escape && !$resolved instanceof Markup) {
                return Escaper::escape($string, $state->escapeStrategy());
            }
            return $string;
        }, $value) ?? $value;
        return str_replace($placeholder, '$', $value);
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value === true => '1',
            $value === false => '',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => throw new SyntaxErrorException(sprintf('Cannot print value of type %s.', get_debug_type($value))),
        };
    }


    /** @return array{0:string,1:string} */
    private function splitCommand(string $source): array
    {
        $parts = preg_split('/\s+/', trim($source), 2) ?: [];
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function assertInstructionAllowed(Instruction $instruction, TemplateSource $template): void
    {
        if ($this->securityPolicy === null || $instruction->type === InstructionType::Text) {
            return;
        }

        $name = match ($instruction->type) {
            InstructionType::Assign => 'assign',
            InstructionType::Delete => 'delete',
            InstructionType::Get => 'get',
            InstructionType::GetOrDefault => 'get_or_default',
            InstructionType::Print => 'print',
            InstructionType::PrintRaw => 'print_raw',
            InstructionType::Return => 'return',
            InstructionType::Call => 'call',
            InstructionType::Include => 'include',
            InstructionType::Extends => 'extends',
            InstructionType::Block => 'block',
            InstructionType::Parent => 'parent',
            InstructionType::Component => 'component',
            InstructionType::Capture => 'capture',
            InstructionType::Cache => 'cache',
            InstructionType::With => 'with',
            InstructionType::Do => 'do',
            InstructionType::Once => 'once',
            InstructionType::Apply => 'apply',
            InstructionType::AutoEscape => 'autoescape',
            InstructionType::Beautify => 'beautify',
            InstructionType::Minify => 'minify',
            InstructionType::Import => 'import',
            InstructionType::Verbatim => 'verbatim',
            InstructionType::Push => 'push',
            InstructionType::Prepend => 'prepend',
            InstructionType::Stack => 'stack',
            InstructionType::If => 'if',
            InstructionType::Foreach => 'foreach',
            InstructionType::Switch => 'switch',
            InstructionType::Break => 'break',
            InstructionType::Continue => 'continue',
            InstructionType::Function => 'function',
            InstructionType::Label => 'label',
            InstructionType::Goto => 'goto',
            InstructionType::CustomTag, InstructionType::CustomBlockTag => (string) $instruction->arguments['name'],
            InstructionType::Text => '',
        };
        if ($name !== '' && !$this->securityPolicy->allowsTag($name)) {
            throw new SecurityException(sprintf('Tag "%s" is not allowed by the security policy in template "%s".', $name, $template->name));
        }
    }

    private function assertTemplateAllowed(string $name, ?string $from): void
    {
        TemplateReference::assertAllowed($name, $this->options->allowDomainTemplateReferences);
        if ($from !== null) {
            TemplateReference::assertAllowed($from, $this->options->allowDomainTemplateReferences);
        }
        if ($this->securityPolicy !== null && !$this->securityPolicy->allowsTemplate($name, $from)) {
            throw new SecurityException(sprintf('Template "%s" is not allowed by the security policy.', $name));
        }
    }

    private function syntax(string $message, TemplateSource $template, int $line): SyntaxErrorException
    {
        return new SyntaxErrorException($message, $template->name, $line);
    }
}
