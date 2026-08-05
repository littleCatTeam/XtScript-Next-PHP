# Examples

All examples are runnable directly with PHP 8.2+ and do not require Composer when executed from this source tree; they fall back to `tests/bootstrap.php`.

| Path | Demonstrates |
|---|---|
| `01-basic/` | Engine + ArrayLoader + variables + filters |
| `02-legacy/` | Legacy XtScript commands, parser wrappers, function/call, foreach, goto |
| `03-expressions/` | Pipelines, tests, `??`, ternary, arrays/maps, dotted access, membership |
| `04-functions-imports/` | Function libraries, lexical namespaces and nested imports |
| `05-layouts/` | `extends`, `block`, `parent` |
| `06-components/` | Components, default slot and named slots |
| `07-control-flow/` | foreach metadata, if, switch |
| `08-scopes-output/` | capture, with, apply, autoescape, verbatim |
| `09-cache-stacks/` | fragment cache, once, push/prepend/stack |
| `10-plugin/` | Complete PluginInterface: function/filter/test/tag/block/prefixed-tag/global |
| `11-cookie/` | CookiePlugin with ArrayCookieStore |
| `12-security/` | AllowListSecurityPolicy |
| `13-runtime/` | Auto backend, compiled cache, profiler |
| `14-xt-tags/` | User-provided prefixed handlers with a custom prefix such as `<cms:...>` |
| `15-loaders/` | ArrayLoader and FilesystemLoader |
| `16-domain-references/` | Optional legacy `domain/path` references handled by one application loader, plus the disable switch |
| `17-modern-tooling/` | strict variables, typed context contract, dependency graph and AOT PHP-file backend |
| `18-assets/` | HTML/CSS/JS beautify filters plus conservative CSS/JS minification |
| `19-regex/` | PCRE2 literals, `matches`, named captures, replace, split and count |
| `xtgem-compatibility/` | Optional historical XtGem `<xt:...>` compatibility sample using the default `xt` prefix |

Run one example:

```bash
php examples/03-expressions/example.php
```

Run all smoke examples from the repository root:

```bash
for f in examples/[0-9][0-9]-*/example.php examples/xtgem-compatibility/example.php; do php "$f" >/dev/null || exit 1; done
```
