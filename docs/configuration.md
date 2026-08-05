# Engine options and limits

`EngineOptions` is readonly. Defaults are intentionally bounded.

| Option | Default | Meaning |
|---|---:|---|
| `autoEscape` | `true` | enable ordinary `print` escaping |
| `escapeStrategy` | `Html` | default escape strategy: HTML/attribute/JS/CSS/URL |
| `strictVariables` | `false` | throw when an undefined variable is used directly |
| `cacheCompiledTemplates` | `true` | enable in-process compiled-program caching |
| `allowDomainTemplateReferences` | `true` | allow legacy `site.example/path` references to reach the single configured loader |
| `pluginTagPrefix` | `xt` | prefix used by optional post-render plugin tags such as `<xt:name>` |
| `executionBackend` | `Auto` | choose Auto/Evaluator/PhpEval/PhpFile |
| `phpFileCacheDirectory` | `null` | directory for AOT generated PHP files; when set, Auto prefers PhpFile |
| `compiledCacheSize` | `256` | maximum L1 compiled programs |
| `expressionCacheSize` | `256` | maximum per-render expression token entries |
| `maxInstructions` | `100000` | shared instruction budget |
| `maxOutputBytes` | `4194304` | final output budget |
| `maxCaptureBytes` | `1048576` | capture/apply/slot style temporary output budget |
| `maxIncludeDepth` | `32` | include/inheritance/component/import depth guard |
| `maxFunctionDepth` | `32` | user-function recursion depth |
| `maxLoopIterations` | `100000` | aggregate loop/materialization limit |
| `maxSourceBytes` | `1048576` | maximum template source size |
| `maxContextVariables` | `2048` | maximum context variable count |
| `maxContextBytes` | `16777216` | approximate total context-size budget |
| `maxContextValueBytes` | `1048576` | approximate per-value context budget |
| `maxFragmentCacheKeyBytes` | `512` | fragment-cache key length limit |
| `maxFragmentCacheTtlSeconds` | `86400` | fragment-cache TTL ceiling |
| `maxOnceKeys` | `1024` | unique `once` keys per render |
| `maxStacks` | `128` | named stack count |
| `maxStackBytes` | `1048576` | accumulated named-stack bytes |
| `timeoutSeconds` | `4.0` | wall-clock render deadline |

All integer limits and `timeoutSeconds` must be positive. `allowDomainTemplateReferences: false` disables domain-qualified references globally before loader access. `pluginTagPrefix` must start with a letter and contain only letters, digits, `_`, `.`, or `-`.

## Execution backends

```php
new EngineOptions(executionBackend: ExecutionBackend::Auto);
new EngineOptions(executionBackend: ExecutionBackend::Evaluator);
new EngineOptions(executionBackend: ExecutionBackend::PhpEval);
new EngineOptions(executionBackend: ExecutionBackend::PhpFile, phpFileCacheDirectory: __DIR__ . '/cache');
```

`Auto` is the default. A configured security policy always uses the evaluator. Unsupported programs also fall back to the evaluator. `Auto` keeps the zero-configuration PHP-eval fast path unless `phpFileCacheDirectory` is configured, in which case eligible programs use persisted PHP files.


## Plugin tag prefix

```php
new EngineOptions(pluginTagPrefix: 'cms'); // <cms:name ...>
```

The default remains `xt` for compatibility. Changing the prefix does not register any service; it only changes the post-render dispatch marker for `XtTagDefinition`.
