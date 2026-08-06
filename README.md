# XtScript Engine

**Modern, extensible XtScript-compatible template engine for PHP 8.2+**

`littlecat-team/xtscript-next` combines backward-compatible XtScript/XtGem syntax with a modular Composer architecture, default-context escaping, and a typed compilation pipeline. It draws design inspiration from Twig and Blade while preserving the practical template language that XtGem developers already know.

---

## Installation

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/littleCatTeam/XtScript-Next-PHP"
    }
  ],
  "require": {
    "littlecat-team/xtscript-next": "1.0.0"
  }
}
```

```bash
composer install
```

Zero dependencies beyond PHP 8.2. No extensions required.

---

## Quick start

```php
use XtScript\Engine;
use XtScript\Loader\FilesystemLoader;

$engine = new Engine(new FilesystemLoader(__DIR__ . '/templates'));

echo $engine->render('page', [
    'name'  => 'World',
    'items' => ['one', 'two', 'three'],
]);
```

**Template** (`templates/page`):

```text
print Hello $name

foreach $items as $item
    print - $item
endforeach
```

`print` auto-escapes HTML by default. Use `print_raw` or the `raw` filter when raw output is intended.

---

## Features

**Compatibility**
- Full backward compatibility with historical XtScript/XtGem syntax — `assign`, `print`, `if`/`foreach`, `include`, `call`, `goto`, legacy `{{ }}` multiline values, `@function` aliases, and `<!--parser:xtscript-->` wrappers.

**Modern template primitives**
- Template inheritance (`extends`/`block`/`parent`) & components (`component`/`slot`)
- Function imports with namespacing (`import`/`as`)
- Stacks (`push`/`prepend`/`stack`), `verbatim`, `capture`, `cache`, `once`, `with`, `do`, `apply`
- Lexical scopes (`autoescape`, `beautify`, `minify`)
- Custom block tags and configurable XT tag prefixes

**Expressions & filters**
- Rich expression engine: comparisons, logic, arithmetic, ternary, null-coalescing, `matches`/`not matches`, `in`/`not in`, array/map literals, dotted access
- Built-in string, math, collection, date, regex, and formatting filters
- Filter pipelines and tests (`is defined`, `is empty`, `is even`, …)

**Regex**
- Full PCRE2 patterns through `/pattern/modifiers`
- `matches`/`not matches` operators, capture groups, `replace`, `split`, `grep`, `quote`, `count`

**Safety by default**
- Default HTML escaping on `print`
- Plugable `SecurityPolicyInterface` (allow-list functions, filters, tags, templates)
- Source, instruction, time, output, context, loop, depth, capture, and stack budgets
- Canonical filesystem root enforcement — symlink and traversal protection

**Performance**
- Templates compile to typed `Program`/`Instruction` objects
- In-process L1 cache with source-change invalidation + optional persistent L2 cache
- Conservative PHP-eval fast path for eligible templates (~1.1× the portable evaluator)
- Optional AOT PHP-file backend (`ExecutionBackend::PhpFile`) with OPcache reuse
- Single plugin/filter/test/tag registry built at setup time — zero discovery on the hot path

**Extensibility**
- `PluginInterface` — register functions, filters, tests, tags, block tags, XT tags, and globals
- `LoaderInterface` — filesystem, array, database, Redis, API, or custom loaders
- Optional `ProfilerInterface`, `FragmentCacheInterface`, `CookieStoreInterface`

**Tooling**
- CLI: `vendor/bin/xtscript` — `lint`, `deps`, `inspect`, `compile`, `warmup`, `benchmark`, `beautify`, `minify`
- `Engine::dependencies()` exposes the static template dependency graph
- `TemplateContract` for typed host-context validation
- Context-selectable escaping strategies (HTML, JS, CSS, URL)

---

## Architecture

```text
Template source
    ↓
LoaderInterface — Filesystem / Array / DB / Redis / API / custom
    ↓
Parser → Program / typed Instructions → L1/L2 compiled-program cache
    ├── conservative PHP fast path (eligible programs only)
    │       └── generated closure cached per Program
    └── portable Evaluator fallback
            ├── Context / scopes / globals
            ├── RuntimeState / execution budgets
            ├── functions / filters / tests / tags / block tags / XT tags
            ├── inheritance / components / imports / stacks
            ├── optional fragment cache
            └── optional SecurityPolicyInterface
```

The PHP fast path emits code only from the validated typed instruction tree — raw template source is never concatenated as executable PHP. Unsupported programs automatically fall back to the evaluator. When a `SecurityPolicyInterface` is configured, the evaluator is always used.

---

## Syntax overview

| Category | Commands |
|---|---|
| Output | `print`, `print_raw` |
| Variables | `assign` / `var`, `get`, `get_or_default`, `delete` / `del` |
| Control flow | `if`/`elseif`/`else`/`endif`, `foreach`/`endforeach`, `for`/`endfor`, `switch`/`case`/`endswitch`, `break`, `continue` |
| Functions | `function`/`endfunction`, `call`, `return`, `import`/`as` |
| Templates | `include`, `extends`/`block`/`parent`, `component`/`slot` |
| Scopes | `autoescape`, `capture`, `cache`, `with`, `do`, `once`, `apply`, `beautify`, `minify`, `verbatim` |
| Stacks | `push`, `prepend`, `stack` |
| Legacy | `goto`/`@labels`, `<!--parser:xtscript-->`, `{{ multiline }}` |

See [`docs/syntax.md`](docs/syntax.md) for the full reference.

---

## Documentation

| Topic | Link |
|---|---|
| Getting started | [`docs/getting-started.md`](docs/getting-started.md) |
| Syntax reference | [`docs/syntax.md`](docs/syntax.md) |
| Core functions & filters | [`docs/core-functions.md`](docs/core-functions.md) |
| Plugins | [`docs/plugins.md`](docs/plugins.md) |
| Configuration | [`docs/configuration.md`](docs/configuration.md) |
| Security | [`docs/security.md`](docs/security.md) |
| CLI tools | [`docs/cli.md`](docs/cli.md) |
| Architecture | [`docs/architecture.md`](docs/architecture.md) |
| Regex | [`docs/regex.md`](docs/regex.md) |
| Escaping & formatting | [`docs/escaping.md`](docs/escaping.md), [`docs/formatting-assets.md`](docs/formatting-assets.md) |
| Tests | [`docs/tests.md`](docs/tests.md) |

Runnable examples are indexed in [`examples/README.md`](examples/README.md).

---

## Development

```bash
php -n tests/lint.php        # Syntax check (102 files)
php -n tests/run.php         # Unit tests (117 scenarios)
php -n tests/reflection.php  # Strict type audit
php -n tests/fuzz.php        # Randomized parser fuzzing (3000 inputs)
```

## PHAR package

Build a release PHAR from the repository:

```bash
php -d phar.readonly=0 build-releases.php 1.0.0
```

If your repository is tagged, `build-releases.php` can infer the version from the latest tag:

```bash
php -d phar.readonly=0 build-releases.php
```

This generates `xtscript-v1.0.0.phar` in the current directory.

You can also download `xtscript-v1.0.0.phar` directly from the GitHub release assets instead of building it locally.

Run the packaged PHAR directly:

```bash
php xtscript-v1.0.0.phar [command] [args]
```

Example commands:

```bash
php xtscript-v1.0.0.phar lint templates/
php xtscript-v1.0.0.phar deps templates/ page
php xtscript-v1.0.0.phar inspect templates/ page
php xtscript-v1.0.0.phar compile templates/ cache/
php xtscript-v1.0.0.phar warmup templates/ cache/
php xtscript-v1.0.0.phar benchmark templates/ page 100
```

If the file is executable, you can also run:

```bash
chmod +x xtscript-v1.0.0.phar
./xtscript-v1.0.0.phar lint templates/
```

For help, use:

```bash
php xtscript-v1.0.0.phar help
```

### Use the PHAR as a library

You can also require the PHAR from a PHP script without Composer:

```php
require 'xtscript-v1.0.0.phar';

use XtScript\Engine;
use XtScript\Loader\FilesystemLoader;

$engine = new Engine(new FilesystemLoader(__DIR__ . '/templates'));
echo $engine->render('page', ['name' => 'World']);
```

```bash
php benchmarks/render.php    # Cache behavior
php benchmarks/backends.php  # Evaluator vs PHP fast path
```

---

## Exceptions

All errors derive from `XtScript\Exception\XtScriptException`:

- `SyntaxErrorException`
- `TemplateNotFoundException`
- `PluginException`
- `SecurityException`

---

## License

MIT. Copyright © littleCatTeam.