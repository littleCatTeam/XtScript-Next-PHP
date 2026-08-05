<?php

declare(strict_types=1);

namespace XtScript\Compiler;

use Closure;
use LogicException;
use XtScript\Ast\Instruction;
use XtScript\Ast\InstructionType;
use XtScript\Ast\Program;
use XtScript\Context;
use XtScript\Runtime\PhpCompiledRuntime;
use XtScript\Runtime\RuntimeState;

/**
 * Compiles a conservative, side-effect-free subset of XtScript's instruction
 * tree into a PHP closure. Unsupported programs return null and are expected
 * to fall back to the normal Evaluator.
 */
final class PhpEvalCompiler
{
    private int $temporary = 0;

    public function compile(Program $program): ?Closure
    {
        $source = $this->compileSource($program);
        if ($source === null) {
            return null;
        }

        /** @var mixed $compiled */
        $compiled = eval('return ' . $source . ';');
        if (!$compiled instanceof Closure) {
            throw new LogicException('PHP compiled template did not produce a Closure.');
        }
        return $compiled;
    }

    public function compileSource(Program $program): ?string
    {
        if (!$this->supportsBlock($program->instructions, 0)) {
            return null;
        }

        $this->temporary = 0;
        $body = $this->emitBlock($program->instructions, 0);
        return 'static function(\\XtScript\\Context $context, \\XtScript\\Runtime\\RuntimeState $state, \\XtScript\\Runtime\\PhpCompiledRuntime $runtime): string {'
            . '$output="";' . $body . 'return $output;}';
    }

    /** @param list<Instruction> $instructions */
    private function supportsBlock(array $instructions, int $loopDepth): bool
    {
        foreach ($instructions as $instruction) {
            switch ($instruction->type) {
                case InstructionType::Text:
                case InstructionType::Delete:
                case InstructionType::Get:
                    break;
                case InstructionType::Assign:
                    if ($this->containsCall((string) ($instruction->arguments['expression'] ?? ''))) {
                        return false;
                    }
                    break;
                case InstructionType::GetOrDefault:
                    if ($this->containsCall((string) ($instruction->arguments['default'] ?? ''))) {
                        return false;
                    }
                    break;
                case InstructionType::Print:
                case InstructionType::PrintRaw:
                    if ($this->containsCall((string) ($instruction->arguments['expression'] ?? ''))) {
                        return false;
                    }
                    break;
                case InstructionType::If:
                    foreach (($instruction->arguments['branches'] ?? []) as $branch) {
                        if (!$this->supportsBlock($branch['body'] ?? [], $loopDepth)) {
                            return false;
                        }
                    }
                    if (!$this->supportsBlock($instruction->alternate, $loopDepth)) {
                        return false;
                    }
                    break;
                case InstructionType::Foreach:
                    if ($this->containsCall((string) ($instruction->arguments['expression'] ?? ''))
                        || !$this->supportsBlock($instruction->body, $loopDepth + 1)
                        || !$this->supportsBlock($instruction->alternate, $loopDepth)) {
                        return false;
                    }
                    break;
                case InstructionType::Break:
                case InstructionType::Continue:
                    if ($loopDepth < 1) {
                        return false;
                    }
                    break;
                default:
                    return false;
            }
        }
        return true;
    }

    /** @param list<Instruction> $instructions */
    private function emitBlock(array $instructions, int $loopDepth): string
    {
        $code = '';
        foreach ($instructions as $instruction) {
            $line = $instruction->line;
            $code .= '$state->tick();';
            switch ($instruction->type) {
                case InstructionType::Text:
                    $chunk = var_export((string) ($instruction->arguments['text'] ?? ''), true);
                    $code .= '$__chunk=' . $chunk . ';$state->addOutput($__chunk);$output.=$__chunk;';
                    break;
                case InstructionType::Assign:
                    $code .= '$context->set(' . var_export((string) $instruction->arguments['name'], true) . ',$runtime->value('
                        . var_export((string) $instruction->arguments['expression'], true) . ',$context,' . $line . '));';
                    break;
                case InstructionType::Delete:
                    $code .= '$context->delete(' . var_export((string) $instruction->arguments['name'], true) . ');';
                    break;
                case InstructionType::Get:
                    break;
                case InstructionType::GetOrDefault:
                    $name = var_export((string) $instruction->arguments['name'], true);
                    $default = var_export((string) $instruction->arguments['default'], true);
                    $code .= '$__current=$context->get(' . $name . ',null);if(!\\XtScript\\Runtime\\PhpCompiledRuntime::truthy($__current)){$context->set('
                        . $name . ',$runtime->value(' . $default . ',$context,' . $line . '));}';
                    break;
                case InstructionType::Print:
                case InstructionType::PrintRaw:
                    $expression = var_export((string) $instruction->arguments['expression'], true);
                    $raw = $instruction->type === InstructionType::PrintRaw ? 'true' : 'false';
                    $code .= '$__chunk=$runtime->renderPrint(' . $expression . ',$context,$state,' . $raw . ',' . $line . ');$state->addOutput($__chunk);$output.=$__chunk;';
                    break;
                case InstructionType::If:
                    $first = true;
                    foreach ($instruction->arguments['branches'] as $branch) {
                        $condition = var_export((string) $branch['condition'], true);
                        $code .= ($first ? 'if' : 'elseif') . '(\\XtScript\\Runtime\\PhpCompiledRuntime::truthy($runtime->expression('
                            . $condition . ',$context,' . $line . '))){' . $this->emitBlock($branch['body'], $loopDepth) . '}';
                        $first = false;
                    }
                    if ($instruction->alternate !== []) {
                        $code .= 'else{' . $this->emitBlock($instruction->alternate, $loopDepth) . '}';
                    }
                    break;
                case InstructionType::Foreach:
                    $id = ++$this->temporary;
                    $items = '$__items' . $id;
                    $length = '$__length' . $id;
                    $offset = '$__offset' . $id;
                    $parent = '$__parentLoop' . $id;
                    $key = '$__key' . $id;
                    $value = '$__value' . $id;
                    $expression = var_export((string) $instruction->arguments['expression'], true);
                    $valueName = var_export((string) $instruction->arguments['value'], true);
                    $keyName = $instruction->arguments['key'] === null ? 'null' : var_export((string) $instruction->arguments['key'], true);
                    $code .= $items . '=$runtime->materializeIterable($runtime->value(' . $expression . ',$context,' . $line . '),$state,' . $line . ');';
                    $code .= 'if(' . $items . '===[]){' . $this->emitBlock($instruction->alternate, $loopDepth) . '}else{';
                    $code .= $length . '=count(' . $items . ');' . $offset . '=0;' . $parent . '=$context->get(\'$loop\',null);';
                    $code .= 'foreach(' . $items . ' as ' . $key . '=>' . $value . '){$state->loop();$context->push($runtime->loopScope('
                        . $key . ',' . $value . ',' . $valueName . ',' . $keyName . ',' . $offset . ',' . $length . ',' . $parent . '));try{';
                    $code .= $this->emitBlock($instruction->body, $loopDepth + 1);
                    $code .= '}finally{$context->pop();++' . $offset . ';}}}';
                    break;
                case InstructionType::Break:
                    $code .= 'break;';
                    break;
                case InstructionType::Continue:
                    $code .= 'continue;';
                    break;
                default:
                    throw new LogicException(sprintf('Unsupported instruction reached PHP emitter: %s.', $instruction->type->value));
            }
        }
        return $code;
    }

    private function containsCall(string $expression): bool
    {
        return str_starts_with(strtolower(trim($expression)), 'call ');
    }
}
