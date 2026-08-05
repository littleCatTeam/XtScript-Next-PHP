<?php

declare(strict_types=1);

namespace XtScript\Analysis;

use Closure;
use XtScript\Ast\Instruction;
use XtScript\Ast\InstructionType;
use XtScript\Ast\Program;
use XtScript\Contract\LoaderInterface;
use XtScript\TemplateReference;
use XtScript\TemplateSource;

final class DependencyAnalyzer
{
    /** @var Closure(TemplateSource): Program */
    private Closure $compiler;

    /** @param callable(TemplateSource): Program $compiler */
    public function __construct(
        private readonly LoaderInterface $loader,
        callable $compiler,
        private readonly bool $allowDomainTemplateReferences = true,
    ) {
        $this->compiler = Closure::fromCallable($compiler);
    }

    public function analyze(string $name, bool $recursive = true): DependencyGraph
    {
        TemplateReference::assertAllowed($name, $this->allowDomainTemplateReferences);
        $root = $this->loader->load($name);
        $edges = [];
        $visited = [];
        $this->walk($root, $recursive, $edges, $visited);
        return new DependencyGraph($edges);
    }

    /** @param array<string,list<string>> $edges @param array<string,true> $visited */
    private function walk(TemplateSource $source, bool $recursive, array &$edges, array &$visited): void
    {
        $identity = $source->origin . "\0" . $source->name;
        if (isset($visited[$identity])) return;
        $visited[$identity] = true;

        $program = ($this->compiler)($source);
        $references = $this->collect($program->instructions);
        $resolved = [];
        foreach ($references as $reference) {
            TemplateReference::assertAllowed($reference, $this->allowDomainTemplateReferences);
            $dependency = $this->loader->load($reference, $source->name);
            if (!in_array($dependency->name, $resolved, true)) {
                $resolved[] = $dependency->name;
            }
            if ($recursive) {
                $this->walk($dependency, true, $edges, $visited);
            }
        }
        $edges[$source->name] = $resolved;
    }

    /** @param list<Instruction> $instructions @return list<string> */
    private function collect(array $instructions): array
    {
        $references = [];
        foreach ($instructions as $instruction) {
            switch ($instruction->type) {
                case InstructionType::Include:
                    foreach (($instruction->arguments['templates'] ?? []) as $template) {
                        $references[] = (string) $template;
                    }
                    break;
                case InstructionType::Extends:
                case InstructionType::Component:
                case InstructionType::Import:
                    $references[] = (string) ($instruction->arguments['template'] ?? '');
                    break;
                default:
                    break;
            }
            array_push($references, ...$this->collect($instruction->body), ...$this->collect($instruction->alternate));
            foreach (($instruction->arguments['branches'] ?? []) as $branch) {
                array_push($references, ...$this->collect($branch['body'] ?? []));
            }
            foreach (($instruction->arguments['cases'] ?? []) as $case) {
                array_push($references, ...$this->collect($case['body'] ?? []));
            }
            foreach (($instruction->arguments['slots'] ?? []) as $slot) {
                array_push($references, ...$this->collect($slot));
            }
        }
        return array_values(array_filter($references, static fn (string $value): bool => $value !== ''));
    }
}
