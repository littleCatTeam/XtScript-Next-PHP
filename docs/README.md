# XtScript Engine documentation

This directory documents the **core package as implemented in `src/`**. Historical XtGem `<xt:...>` services are not core functions; only the generic plugin hook is documented here.

## Guides

- [Getting started](getting-started.md)
- [Architecture](architecture.md)
- [Engine API](engine-api.md)
- [Engine options and limits](configuration.md)
- [XtScript syntax](syntax.md)
- [Core system functions](core-functions.md)
- [Core filters](filters.md)
- [Core tests](tests.md)
- [Loaders](loaders.md)
- [Plugins](plugins.md)
- [Optional configurable prefixed plugin tags (`<xt:...>` by default)](xt-tags.md)
- [Caching, profiler and execution backends](caching-performance.md)
- [Escaping strategies](escaping.md)
- [HTML/CSS/JS formatting and minification](formatting-assets.md)
- [Regular expressions / PCRE2](regex.md)
- [Typed contracts and dependency analysis](contracts-analysis.md)
- [CLI](cli.md)
- [Security model](security.md)
- [Exceptions](exceptions.md)
- [Legacy compatibility](compatibility.md)

## Core surface summary

The built-in `CorePlugin` currently registers:

- **84** system functions
- **36** filters
- **16** tests
- parser-managed statement/control tags listed in [syntax.md](syntax.md)
- no built-in `<xt:...>` service tags
- no built-in globals

`CookiePlugin` is optional and documented in [plugins.md](plugins.md).

The documentation audit (`php tests/docs.php`) verifies that every currently registered core function/filter/test/tag name appears somewhere in `/docs`.
