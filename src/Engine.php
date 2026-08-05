<?php

declare(strict_types=1);

namespace XtScript;

use Closure;
use WeakMap;
use XtScript\Ast\Program;
use XtScript\Ast\UserFunction;
use XtScript\Analysis\DependencyAnalyzer;
use XtScript\Analysis\DependencyGraph;
use XtScript\Compiler\PhpEvalCompiler;
use XtScript\Compiler\PhpFileCompiler;
use XtScript\Contract\CompiledTemplateCacheInterface;
use XtScript\Contract\FilterInterface;
use XtScript\Contract\FragmentCacheInterface;
use XtScript\Contract\LoaderInterface;
use XtScript\Contract\PluginInterface;
use XtScript\Contract\ProfilerInterface;
use XtScript\Contract\SecurityPolicyInterface;
use XtScript\Contract\TestInterface;
use XtScript\Contract\TemplateContractInterface;
use XtScript\Exception\PluginException;
use XtScript\Exception\SecurityException;
use XtScript\Exception\SyntaxErrorException;
use XtScript\Parser\Evaluator;
use XtScript\Parser\ExpressionEvaluator;
use XtScript\Parser\Parser;
use XtScript\Plugin\BlockTagDefinition;
use XtScript\Plugin\CorePlugin;
use XtScript\Plugin\FunctionDefinition;
use XtScript\Plugin\TagDefinition;
use XtScript\Plugin\XtTagDefinition;
use XtScript\Runtime\XtTagRenderer;
use XtScript\Runtime\PhpCompiledRuntime;
use XtScript\Runtime\RuntimeState;

final class Engine
{
    private const COMPILED_CACHE_SCHEMA = 'xtscript-program-v2';

    /** @var array<string, PluginInterface> */
    private array $plugins = [];

    /** @var array<string, FunctionDefinition> */
    private array $functions = [];

    /** @var array<string, TagDefinition> */
    private array $tags = [];

    /** @var array<string, BlockTagDefinition> */
    private array $blockTags = [];

    /** @var array<string, FilterInterface> */
    private array $filters = [];

    /** @var array<string, TestInterface> */
    private array $tests = [];

    /** @var array<string, mixed> */
    private array $globals = [];

    /** @var array<string, XtTagDefinition> */
    private array $xtTags = [];

    private ?XtTagDefinition $xtTagFallback = null;

    private ?XtTagRenderer $xtTagRenderer = null;

    /** @var array<string, Program> */
    private array $compiled = [];

    /** @var list<string> */
    private array $compiledOrder = [];

    /** @var WeakMap<Program, Closure|false> */
    private WeakMap $phpCompiled;

    private readonly PhpEvalCompiler $phpCompiler;
    private readonly PhpFileCompiler $phpFileCompiler;

    /**
     * @param iterable<PluginInterface> $plugins
     * @param array<string, mixed> $globals
     */
    public function __construct(
        private readonly LoaderInterface $loader,
        private readonly EngineOptions $options = new EngineOptions(),
        private readonly Parser $parser = new Parser(),
        iterable $plugins = [],
        array $globals = [],
        private readonly ?FragmentCacheInterface $fragmentCache = null,
        private readonly ?SecurityPolicyInterface $securityPolicy = null,
        private readonly ?CompiledTemplateCacheInterface $compiledTemplateCache = null,
        private readonly ?ProfilerInterface $profiler = null,
    ) {
        $this->phpCompiled = new WeakMap();
        $this->phpCompiler = new PhpEvalCompiler();
        $this->phpFileCompiler = new PhpFileCompiler($this->phpCompiler);
        $this->addPlugin(new CorePlugin());
        foreach ($plugins as $plugin) {
            $this->addPlugin($plugin);
        }
        foreach ($globals as $name => $value) {
            $this->addGlobal((string) $name, $value);
        }
    }

    public function addPlugin(PluginInterface $plugin): void
    {
        $pluginName = strtolower(trim($plugin->getName()));
        if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $pluginName) !== 1 || isset($this->plugins[$pluginName])) {
            throw new PluginException(sprintf('Plugin "%s" is invalid or already registered.', $plugin->getName()));
        }

        $newFunctions = [];
        foreach ($plugin->getFunctions() as $definition) {
            if (!$definition instanceof FunctionDefinition) {
                throw new PluginException(sprintf('Plugin "%s" returned an invalid function definition.', $pluginName));
            }
            if (isset($this->functions[$definition->name]) || isset($newFunctions[$definition->name])) {
                throw new PluginException(sprintf('Function "%s" is already registered.', $definition->name));
            }
            $newFunctions[$definition->name] = $definition;
        }

        $newTags = [];
        foreach ($plugin->getTags() as $definition) {
            if (!$definition instanceof TagDefinition) {
                throw new PluginException(sprintf('Plugin "%s" returned an invalid tag definition.', $pluginName));
            }
            $name = strtolower($definition->name);
            $conflictsWithBlockEnd = false;
            foreach ($this->blockTags as $blockDefinition) {
                if (strcasecmp($blockDefinition->endTag, $name) === 0) {
                    $conflictsWithBlockEnd = true;
                    break;
                }
            }
            if (isset($this->tags[$name]) || isset($this->blockTags[$name]) || isset($newTags[$name]) || $conflictsWithBlockEnd) {
                throw new PluginException(sprintf('Tag "%s" is already registered or reserved by a block tag.', $definition->name));
            }
            $newTags[$name] = $definition;
        }

        $newFilters = [];
        foreach ($plugin->getFilters() as $definition) {
            if (!$definition instanceof FilterInterface) {
                throw new PluginException(sprintf('Plugin "%s" returned an invalid filter definition.', $pluginName));
            }
            $name = strtolower($definition->getName());
            if (isset($this->filters[$name]) || isset($newFilters[$name])) {
                throw new PluginException(sprintf('Filter "%s" is already registered.', $definition->getName()));
            }
            $newFilters[$name] = $definition;
        }

        $newTests = [];
        foreach ($plugin->getTests() as $definition) {
            if (!$definition instanceof TestInterface) {
                throw new PluginException(sprintf('Plugin "%s" returned an invalid test definition.', $pluginName));
            }
            $name = strtolower($definition->getName());
            if (isset($this->tests[$name]) || isset($newTests[$name])) {
                throw new PluginException(sprintf('Test "%s" is already registered.', $definition->getName()));
            }
            $newTests[$name] = $definition;
        }

        $newXtTags = [];
        $newXtFallback = null;
        foreach ($plugin->getXtTags() as $definition) {
            if (!$definition instanceof XtTagDefinition) {
                throw new PluginException(sprintf('Plugin "%s" returned an invalid XT tag definition.', $pluginName));
            }
            $name = strtolower($definition->name);
            if ($name === '*') {
                if ($this->xtTagFallback !== null || $newXtFallback !== null) {
                    throw new PluginException('An XT wildcard tag handler is already registered.');
                }
                $newXtFallback = $definition;
                continue;
            }
            if (isset($this->xtTags[$name]) || isset($newXtTags[$name])) {
                throw new PluginException(sprintf('XT tag "%s" is already registered.', $definition->name));
            }
            $newXtTags[$name] = $definition;
        }

        $newGlobals = [];
        foreach ($plugin->getGlobals() as $name => $value) {
            $normalized = self::normalizeGlobalName((string) $name);
            if (array_key_exists($normalized, $this->globals) || array_key_exists($normalized, $newGlobals)) {
                throw new PluginException(sprintf('Global "%s" is already registered.', $name));
            }
            $newGlobals[$normalized] = $value;
        }

        $newBlockTags = [];
        foreach ($plugin->getBlockTags() as $definition) {
            if (!$definition instanceof BlockTagDefinition) {
                throw new PluginException(sprintf('Plugin "%s" returned an invalid block tag definition.', $pluginName));
            }
            $name = strtolower($definition->name);
            $endTag = strtolower($definition->endTag);
            if (isset($this->tags[$name]) || isset($newTags[$name]) || isset($this->blockTags[$name]) || isset($newBlockTags[$name])) {
                throw new PluginException(sprintf('Block tag "%s" is already registered.', $definition->name));
            }
            if (isset($this->tags[$endTag]) || isset($newTags[$endTag]) || isset($this->blockTags[$endTag]) || isset($newBlockTags[$endTag])) {
                throw new PluginException(sprintf('Block end tag "%s" conflicts with another registered tag.', $definition->endTag));
            }
            foreach ($this->blockTags + $newBlockTags as $existing) {
                if (strcasecmp($existing->endTag, $endTag) === 0
                    || strcasecmp($existing->name, $endTag) === 0
                    || strcasecmp($existing->endTag, $name) === 0) {
                    throw new PluginException(sprintf('Block tag "%s" conflicts with another registered block boundary.', $definition->name));
                }
            }
            $newBlockTags[$name] = $definition;
        }

        $this->plugins[$pluginName] = $plugin;
        $this->functions += $newFunctions;
        $this->tags += $newTags;
        $this->blockTags += $newBlockTags;
        $this->filters += $newFilters;
        $this->tests += $newTests;
        $this->xtTags += $newXtTags;
        if ($newXtFallback !== null) {
            $this->xtTagFallback = $newXtFallback;
        }
        $this->globals += $newGlobals;
        if ($newXtTags !== [] || $newXtFallback !== null) {
            $this->xtTagRenderer = null;
        }
        $this->clearCache();
    }

    public function addFunction(FunctionDefinition $definition): void
    {
        if (isset($this->functions[$definition->name])) {
            throw new PluginException(sprintf('Function "%s" is already registered.', $definition->name));
        }
        $this->functions[$definition->name] = $definition;
    }

    public function addFilter(FilterInterface $definition): void
    {
        $name = strtolower($definition->getName());
        if (isset($this->filters[$name])) {
            throw new PluginException(sprintf('Filter "%s" is already registered.', $definition->getName()));
        }
        $this->filters[$name] = $definition;
    }

    public function addTest(TestInterface $definition): void
    {
        $name = strtolower($definition->getName());
        if (isset($this->tests[$name])) {
            throw new PluginException(sprintf('Test "%s" is already registered.', $definition->getName()));
        }
        $this->tests[$name] = $definition;
    }

    public function addTag(TagDefinition $definition): void
    {
        $name = strtolower($definition->name);
        $reservedEnd = false;
        foreach ($this->blockTags as $blockDefinition) {
            if (strcasecmp($blockDefinition->endTag, $name) === 0) {
                $reservedEnd = true;
                break;
            }
        }
        if (isset($this->tags[$name]) || isset($this->blockTags[$name]) || $reservedEnd) {
            throw new PluginException(sprintf('Tag "%s" is already registered or reserved by a block tag.', $definition->name));
        }
        $this->tags[$name] = $definition;
    }

    public function addBlockTag(BlockTagDefinition $definition): void
    {
        $name = strtolower($definition->name);
        $endTag = strtolower($definition->endTag);
        if (isset($this->tags[$name]) || isset($this->blockTags[$name])
            || isset($this->tags[$endTag]) || isset($this->blockTags[$endTag])) {
            throw new PluginException(sprintf('Block tag "%s" conflicts with an existing tag.', $definition->name));
        }
        foreach ($this->blockTags as $existing) {
            if (strcasecmp($existing->endTag, $definition->endTag) === 0
                || strcasecmp($existing->name, $definition->endTag) === 0
                || strcasecmp($existing->endTag, $definition->name) === 0) {
                throw new PluginException(sprintf('Block tag "%s" conflicts with another registered block boundary.', $definition->name));
            }
        }
        $this->blockTags[$name] = $definition;
        $this->clearCache();
    }

    public function addXtTag(XtTagDefinition $definition): void
    {
        $name = strtolower($definition->name);
        if ($name === '*') {
            if ($this->xtTagFallback !== null) {
                throw new PluginException('An XT wildcard tag handler is already registered.');
            }
            $this->xtTagFallback = $definition;
            $this->xtTagRenderer = null;
            return;
        }
        if (isset($this->xtTags[$name])) {
            throw new PluginException(sprintf('XT tag "%s" is already registered.', $definition->name));
        }
        $this->xtTags[$name] = $definition;
        $this->xtTagRenderer = null;
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $name = self::normalizeGlobalName($name);
        if (array_key_exists($name, $this->globals)) {
            throw new PluginException(sprintf('Global "%s" is already registered.', $name));
        }
        $this->globals[$name] = $value;
    }

    /** @param array<string, mixed> $variables */
    public function render(string $name, array $variables = []): string
    {
        TemplateReference::assertAllowed($name, $this->options->allowDomainTemplateReferences);
        if ($this->securityPolicy !== null && !$this->securityPolicy->allowsTemplate($name, null)) {
            throw new SecurityException(sprintf('Template "%s" is not allowed by the security policy.', $name));
        }
        $source = $this->loader->load($name);
        return $this->renderSource($source, $this->createContext($variables));
    }

    /** @param array<string, mixed> $variables */
    public function renderWithContract(string $name, TemplateContractInterface $contract, array $variables = []): string
    {
        return $this->render($name, $contract->validate($variables));
    }

    public function compileTemplate(string $name): Program
    {
        TemplateReference::assertAllowed($name, $this->options->allowDomainTemplateReferences);
        if ($this->securityPolicy !== null && !$this->securityPolicy->allowsTemplate($name, null)) {
            throw new SecurityException(sprintf('Template "%s" is not allowed by the security policy.', $name));
        }
        return $this->compile($this->loader->load($name));
    }

    public function dependencies(string $name, bool $recursive = true): DependencyGraph
    {
        return (new DependencyAnalyzer(
            $this->loader,
            fn (TemplateSource $source): Program => $this->compile($source),
            $this->options->allowDomainTemplateReferences,
        ))->analyze($name, $recursive);
    }

    /** @param iterable<string> $templates */
    public function warmup(iterable $templates): void
    {
        foreach ($templates as $name) {
            TemplateReference::assertAllowed($name, $this->options->allowDomainTemplateReferences);
            $source = $this->loader->load($name);
            $program = $this->compile($source);
            if ($this->options->phpFileCacheDirectory !== null) {
                $this->phpFileCompiler->compile($program, $this->options->phpFileCacheDirectory);
            }
        }
    }

    /** @param array<string, mixed> $variables */
    public function renderString(string $source, array $variables = [], string $name = '__string__'): string
    {
        TemplateReference::assertAllowed($name, $this->options->allowDomainTemplateReferences);
        return $this->renderSource(new TemplateSource($name, $source, 'string://' . $name), $this->createContext($variables));
    }

    public function clearCache(): void
    {
        $this->compiled = [];
        $this->compiledOrder = [];
        $this->phpCompiled = new WeakMap();
    }

    /** @return list<string> */
    public function pluginNames(): array
    {
        return array_keys($this->plugins);
    }

    /** @return list<string> */
    public function functionNames(): array
    {
        return array_keys($this->functions);
    }

    /** @return list<string> */
    public function tagNames(): array
    {
        return array_keys($this->tags);
    }

    /** @return list<string> */
    public function blockTagNames(): array
    {
        return array_keys($this->blockTags);
    }

    /** @return list<string> */
    public function filterNames(): array
    {
        return array_keys($this->filters);
    }

    /** @return list<string> */
    public function testNames(): array
    {
        return array_keys($this->tests);
    }

    /** @return list<string> */
    public function xtTagNames(): array
    {
        $names = array_keys($this->xtTags);
        if ($this->xtTagFallback !== null) {
            $names[] = '*';
        }
        return $names;
    }

    /** @return list<string> */
    public function globalNames(): array
    {
        return array_keys($this->globals);
    }

    /** @param array<string, mixed> $variables */
    private function createContext(array $variables): Context
    {
        // Per-render variables intentionally override plugin/host globals.
        return new Context(
            array_replace($this->globals, $variables),
            $this->options->maxContextVariables,
            $this->options->maxContextBytes,
            $this->options->maxContextValueBytes,
        );
    }

    private function renderSource(TemplateSource $source, Context $context): string
    {
        $renderStarted = $this->profiler !== null ? microtime(true) : 0.0;
        $program = $this->compile($source);
        $state = new RuntimeState($this->options);
        $expressions = new ExpressionEvaluator(
            filters: $this->filters,
            tests: $this->tests,
            securityPolicy: $this->securityPolicy,
            cacheSize: $this->options->expressionCacheSize,
            collectionLimit: $this->options->maxLoopIterations,
            strictVariables: $this->options->strictVariables,
        );

        $backend = 'evaluator';
        $result = null;
        if ($this->options->executionBackend !== ExecutionBackend::Evaluator && $this->securityPolicy === null) {
            if (isset($this->phpCompiled[$program])) {
                $compiled = $this->phpCompiled[$program];
            } else {
                $compiled = $this->compilePhpFastPath($program) ?? false;
                $this->phpCompiled[$program] = $compiled;
            }
            if ($compiled instanceof Closure) {
                $backend = $this->preferredPhpBackendName();
                $result = $compiled($context, $state, new PhpCompiledRuntime($expressions, $source, $this->options));
            }
        }

        if ($result === null) {
            /** @var array<string, UserFunction> $userFunctions */
            $userFunctions = [];
            $evaluator = new Evaluator(
                $this->loader,
                $this->options,
                $expressions,
                $this->functions,
                $this->tags,
                $this->blockTags,
                fn (TemplateSource $template): Program => $this->compile($template),
                $this->fragmentCache,
                $this->securityPolicy,
            );
            $result = $evaluator->evaluate($program, $source, $context, $state, $userFunctions);
        }

        $pluginTagMarker = '<' . $this->options->pluginTagPrefix . ':';
        if (($this->xtTags !== [] || $this->xtTagFallback !== null) && stripos($result, $pluginTagMarker) !== false) {
            $renderer = $this->xtTagRenderer ??= new XtTagRenderer(
                $this->xtTags,
                $this->xtTagFallback,
                $this->securityPolicy,
                $this->options->pluginTagPrefix,
            );
            $result = $renderer->render(
                $result,
                new \XtScript\Plugin\FunctionContext($context, $source, $this->loader, $state, $this->options),
                $state,
                $this->options->maxOutputBytes,
            );
        }

        if ($this->profiler !== null) {
            $this->profiler->record('render', microtime(true) - $renderStarted, [
                'template' => $source->name,
                'output_bytes' => strlen($result),
                'backend' => $backend,
            ]);
        }
        return $result;
    }

    private function compilePhpFastPath(Program $program): ?Closure
    {
        if ($this->options->executionBackend === ExecutionBackend::PhpFile
            || ($this->options->executionBackend === ExecutionBackend::Auto && $this->options->phpFileCacheDirectory !== null)) {
            if ($this->options->phpFileCacheDirectory === null) {
                throw new SyntaxErrorException('PhpFile execution backend requires phpFileCacheDirectory.');
            }
            return $this->phpFileCompiler->compile($program, $this->options->phpFileCacheDirectory);
        }

        return $this->phpCompiler->compile($program);
    }

    private function preferredPhpBackendName(): string
    {
        return ($this->options->executionBackend === ExecutionBackend::PhpFile
            || ($this->options->executionBackend === ExecutionBackend::Auto && $this->options->phpFileCacheDirectory !== null))
            ? 'php_file'
            : 'php_eval';
    }

    private function compile(TemplateSource $source): Program
    {
        if (strlen($source->code) > $this->options->maxSourceBytes) {
            throw new SyntaxErrorException('Template source size limit exceeded.', $source->name);
        }

        $blockSignature = '';
        if ($this->blockTags !== []) {
            $pairs = [];
            foreach ($this->blockTags as $name => $definition) {
                $pairs[] = strtolower($name) . ':' . strtolower($definition->endTag);
            }
            sort($pairs, SORT_STRING);
            $blockSignature = implode(',', $pairs);
        }
        $cacheKey = hash('sha256', self::COMPILED_CACHE_SCHEMA . "\0" . $blockSignature . "\0" . $source->name . "\0" . $source->code);
        if ($this->options->cacheCompiledTemplates && isset($this->compiled[$cacheKey])) {
            $this->profiler?->record('compile_cache_hit', 0.0, ['template' => $source->name, 'level' => 'l1']);
            return $this->compiled[$cacheKey];
        }
        if ($this->options->cacheCompiledTemplates && $this->compiledTemplateCache !== null) {
            $cached = $this->compiledTemplateCache->get($cacheKey);
            if ($cached !== null) {
                $this->rememberCompiled($cacheKey, $cached);
                $this->profiler?->record('compile_cache_hit', 0.0, ['template' => $source->name, 'level' => 'l2']);
                return $cached;
            }
        }

        // Parser carries a cursor while compiling; clone it so one Engine can
        // be safely reused by Fiber/coroutine-style request handlers.
        $compileStarted = $this->profiler !== null ? microtime(true) : 0.0;
        $parser = clone $this->parser;
        $parser->configureBlockTags(array_map(
            static fn (BlockTagDefinition $definition): string => strtolower($definition->endTag),
            $this->blockTags,
        ));
        $program = $parser->parse($source->code, $source->name);
        if ($this->profiler !== null) {
            $this->profiler->record('compile', microtime(true) - $compileStarted, ['template' => $source->name]);
        }
        if (!$this->options->cacheCompiledTemplates) {
            return $program;
        }

        $this->rememberCompiled($cacheKey, $program);
        $this->compiledTemplateCache?->set($cacheKey, $program);

        return $program;
    }

    private function rememberCompiled(string $cacheKey, Program $program): void
    {
        if (isset($this->compiled[$cacheKey])) {
            return;
        }
        $this->compiled[$cacheKey] = $program;
        $this->compiledOrder[] = $cacheKey;
        while (count($this->compiledOrder) > $this->options->compiledCacheSize) {
            $oldest = array_shift($this->compiledOrder);
            if ($oldest !== null) {
                unset($this->compiled[$oldest]);
            }
        }
    }

    private static function normalizeGlobalName(string $name): string
    {
        $name = ltrim(trim($name), '$');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new PluginException(sprintf('Invalid global name "%s".', $name));
        }

        return $name;
    }
}
