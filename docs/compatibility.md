# Legacy compatibility

The compatibility baseline is the supplied original XtScript source, not the broader XtGem platform service layer.

## Preserved language concepts

- `assign` / `var`
- `get` / `get_or_default`
- `delete` / `del`
- `print` / `print_raw`
- `call`
- `if` / `elseif` / `else` / `endif`
- `foreach` / `endforeach`
- `function` / `endfunction` / `return`
- `include` for local/logical templates and optional legacy domain-qualified templates
- labels and `goto`
- historical `@`-style function naming behavior
- multiline compatibility handling
- parser wrapper `<!--parser:xtscript--> ... <!--/parser:xtscript-->`

Specific regressions include optional `substr` `$length` behavior and forward/backward `goto` handling.

## Additive modern features

Modern syntax such as filters/tests, `??`, ternary, arrays/maps, inheritance, components, capture, cache, imports/namespaces, stacks, etc. is additive and must not be required by legacy templates.

There is deliberately no `macro`; `function` is the sole user-defined reusable primitive and imports provide lexical `@` namespaces.

## Domain-qualified template references

The supplied hardened legacy source supported site/domain-qualified references such as `include site.example/file`. The rewrite preserves that capability without coupling core to a specific filesystem or network transport:

- `EngineOptions::allowDomainTemplateReferences` defaults to `true` for compatibility.
- the same configured `LoaderInterface` receives `site.example/path` unchanged; domain is part of the logical reference, not a loader.
- setting `allowDomainTemplateReferences: false` rejects domain-qualified references before loader access.
- an application loader may resolve the reference through a site filesystem, database, object storage, internal API, or another host-controlled transport.
- URL forms such as `https://site.example/file` are not treated as XtScript template references by core.

No `.xt` extension is required. A template may be named `main`, `partial.html`, `helpers.tpl`, or any other loader-supported name.

## XtGem `<xt:...>`

The original interpreter source did not implement the XtGem service backend. Therefore no XtGem service function is added to core.

Core only exposes an optional generic prefixed plugin hook. Its default prefix is `<xt:...>` for compatibility, and `EngineOptions::pluginTagPrefix` can change it, for example to `<cms:...>`. `examples/xtgem-compatibility/` demonstrates how a host **could** implement historical call shapes through adapters, without making XtGem a dependency or changing the language baseline.
