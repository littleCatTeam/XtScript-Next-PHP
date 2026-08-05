# Architecture

## Render pipeline

```text
Template name/source
      |
      v
LoaderInterface / renderString
      |
      v
Parser -> Program / Instruction tree
      |
      +--> bounded L1 compiled-program cache
      +--> optional L2 CompiledTemplateCacheInterface
      |
      v
ExecutionBackend
      +--> conservative PhpEvalCompiler closure when eligible
      +--> optional PhpFileCompiler AOT file + require/OPcache
      `--> Evaluator fallback
             |
             +-- Context/scopes/globals
             +-- functions/filters/tests/tags/block-tags
             +-- imports/inheritance/components/caches/stacks
             `-- RuntimeState budgets
      |
      v
optional post-render XT tag dispatcher
      |
      v
string output
```

## Public data/runtime objects

### `TemplateSource`

Represents loaded code plus template/source identity. Loaders return it and `FunctionContext::load()` exposes it to trusted plugin functions.

### `Markup`

Stringable wrapper for content explicitly considered safe from another escaping pass. The `raw`/`escape` filters and captured/component content can produce safe markup semantics.

### `Program`, `Instruction`, `InstructionType`, `UserFunction`

Typed internal compiled representation. Public because cache interfaces store `Program`, but applications should normally treat AST objects as immutable compiler data rather than manually constructing programs.

### `RuntimeState`

Per-render internal state carrying budgets, stacks, once keys, inheritance/function/import state and execution counters. It is intentionally not shared globally between renders.

## Parser and evaluator

`Parser` converts source into typed instructions and owns grammar validation. `ExpressionEvaluator` handles expression/filter/test syntax. `Evaluator` executes all supported instructions and is the compatibility reference backend.

## PHP compiler

`PhpEvalCompiler` supports a conservative hot subset. `PhpFileCompiler` persists the exact same emitted closure source to immutable content-hashed PHP files for cross-Engine/OPcache reuse. `PhpCompiledRuntime` supplies trusted runtime helpers. Unsupported programs retain identical functionality through evaluator fallback.

## Plugin registries

Engine setup builds maps for functions, filters, tests, statement tags, block tags, XT tags and globals. Registrations reject collisions. Render hot paths therefore do not scan directories or discover plugin PHP dynamically.


## Analysis/tooling layer

`DependencyAnalyzer` walks compiled instructions without rendering and produces a `DependencyGraph`. `TemplateContract` optionally validates host context before render. `bin/xtscript` exposes lint/deps/inspect/warmup/asset-formatting workflows.
