# Caching, profiler and execution backends

## L1 compiled-program cache

When `cacheCompiledTemplates` is enabled, each `Engine` keeps a bounded in-memory cache of parsed `Program` objects. `compiledCacheSize` sets its maximum size. Source changes invalidate by source identity/hash during compilation.

`Engine::clearCache()` clears this L1 cache and cached PHP closures.

## L2 compiled-program cache

`CompiledTemplateCacheInterface` allows reuse across Engine instances/requests:

```php
interface CompiledTemplateCacheInterface
{
    public function get(string $key): ?Program;
    public function set(string $key, Program $program): void;
}
```

Built-in `ArrayCompiledTemplateCache` is intended for examples/tests. A host can implement APCu/Redis/database/trusted filesystem storage. Cache keys include compiler schema, source identity/hash and custom parser-significant registration data.

## Fragment cache

`FragmentCacheInterface` stores rendered strings:

```php
interface FragmentCacheInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $value, int $ttlSeconds): void;
    public function delete(string $key): void;
    public function clear(): void;
}
```

Built-in `ArrayFragmentCache` enforces TTL in memory. Template syntax:

```text
cache "key";60
    print fragment
endcache
```

Fragment key length and TTL are bounded by `EngineOptions`.

## Profiler

`ProfilerInterface::record(string $event, float $seconds, array $metadata = [])` is called only when a profiler is configured. `ArrayProfiler` records events in memory and exposes `events()` / `clear()`.

Current engine events include compile/render timing and cache/backend metadata. Profiling is opt-in to avoid timing overhead when unused.

## ExecutionBackend

- `ExecutionBackend::Auto` — default; use compiled PHP closure when eligible, otherwise evaluator
- `ExecutionBackend::Evaluator` — always portable evaluator; no `eval()` path
- `ExecutionBackend::PhpEval` — compile eligible programs to an in-memory PHP closure with `eval()`
- `ExecutionBackend::PhpFile` — persist the same generated closure to a PHP file and load it with `require`, allowing OPcache reuse across Engine instances/workers

A non-null `SecurityPolicyInterface` always forces evaluator execution.

## PHP fast path safety boundary

`PhpEvalCompiler` generates PHP from a validated typed `Program`. `PhpFileCompiler` uses exactly the same emitted closure source, so the two PHP backends differ only in loading/caching strategy. It emits engine-controlled syntax and quoted/exported literals rather than concatenating raw template source as executable PHP. Only a conservative instruction subset is compiled; complex features fall back.

## Performance design

- compile source once into typed instructions
- bounded L1/L2 program caches
- cached PHP closures for eligible programs
- bounded per-render expression-token cache
- plugin registries built during engine setup
- no automatic plugin filesystem discovery on render hot path
- prefixed-tag post-render pass skipped when no registry exists or output has no configured `<prefix:` marker
- shared per-render limits instead of new unlimited counters in nested operations

Use `benchmarks/render.php` and `benchmarks/backends.php` on the target production PHP build/hardware rather than relying on development-machine numbers.


## AOT warmup

`Engine::warmup()` and `vendor/bin/xtscript warmup` parse templates ahead of traffic. With `phpFileCacheDirectory` configured, PHP-eligible programs are written atomically as generated `.php` files. Production deployments can place this directory on local fast storage and let OPcache cache those files.

Dependency analysis is available through `Engine::dependencies()`; see [Typed contracts and dependency analysis](contracts-analysis.md).
