# Engine API

## Constructor

```php
new Engine(
    LoaderInterface $loader,
    EngineOptions $options = new EngineOptions(),
    Parser $parser = new Parser(),
    iterable $plugins = [],
    array $globals = [],
    ?FragmentCacheInterface $fragmentCache = null,
    ?SecurityPolicyInterface $securityPolicy = null,
    ?CompiledTemplateCacheInterface $compiledTemplateCache = null,
    ?ProfilerInterface $profiler = null,
)
```

The engine automatically registers `CorePlugin` before host plugins.

## Rendering

### `render(string $name, array $variables = []): string`

Loads a template through the single configured `LoaderInterface`, creates a per-render context, compiles/caches the program and renders it. Local/logical names are always supported. When `EngineOptions::allowDomainTemplateReferences` is enabled, legacy names such as `site.example/path` are passed unchanged to that same loader. Domain is reference metadata, not a loader selection mechanism. Built-in local loaders reject domain-qualified names; application loaders may resolve them through a site filesystem/database/API/storage layer. URL schemes such as `https://` are not template references. Template names may use any extension or no extension.

### `renderString(string $source, array $variables = [], string $name = '__string__'): string`

Renders source supplied directly by the host. The synthetic template name participates in diagnostics/profiling.


### `renderWithContract(string $name, TemplateContractInterface $contract, array $variables = []): string`

Validates host variables against an optional typed contract before normal rendering. Legacy `render()` remains unchanged.

### `compileTemplate(string $name): Program`

Parses/loads one template and returns its immutable compiled `Program` without rendering it. Used by tooling and the CLI.

### `dependencies(string $name, bool $recursive = true): DependencyGraph`

Builds static dependency edges for `include`, `import`, `extends`, and `component` through the same single loader.

### `warmup(iterable $templates): void`

Precompiles templates. If `phpFileCacheDirectory` is configured, eligible PHP fast-path programs are persisted as AOT PHP files.

### `clearCache(): void`

Clears the in-process compiled-program cache and PHP-closure fast-path cache. It does not clear an external `CompiledTemplateCacheInterface` because that storage is owned by its adapter.

## Direct registrations

- `addPlugin(PluginInterface $plugin): void`
- `addFunction(FunctionDefinition $definition): void`
- `addFilter(FilterInterface $definition): void`
- `addTest(TestInterface $definition): void`
- `addTag(TagDefinition $definition): void`
- `addBlockTag(BlockTagDefinition $definition): void`
- `addXtTag(XtTagDefinition $definition): void`
- `addGlobal(string $name, mixed $value): void`

Duplicate or invalid names throw `PluginException`. `addXtTag()` is a historical API name for the generic prefixed-tag hook; the actual markup prefix comes from `EngineOptions::pluginTagPrefix`.

## Registry inspection

All return `list<string>`:

- `pluginNames()`
- `functionNames()`
- `tagNames()`
- `blockTagNames()`
- `filterNames()`
- `testNames()`
- `xtTagNames()`
- `globalNames()`

`xtTagNames()` includes `*` when a wildcard prefixed-tag handler is installed. The method name is retained for package API compatibility; registered names are independent of the configured prefix.

## Context

`Context` provides bounded variable scopes:

- `push(array $variables = [])`
- `pop()`
- `has(string $name)`
- `get(string $name, mixed $default = null)`
- `set(string $name, mixed $value)`
- `delete(string $name)`
- `all()`
- `fork(array $variables = [], bool $inherit = true)`

Normal template variables passed to `render()` override host/plugin globals for that render only.
