# Loaders

Every template read performed by the core goes through `LoaderInterface`:

```php
interface LoaderInterface
{
    public function exists(string $name, ?string $from = null): bool;
    public function load(string $name, ?string $from = null): TemplateSource;
}
```

`TemplateSource` carries the template name, code and source identity/location.

## Template reference forms

Local/logical names are always valid when supported by the configured loader:

```text
main
partial.html
helpers.tpl
views/card.template
```

Legacy domain-qualified names are also supported when both conditions are true:

1. `EngineOptions::allowDomainTemplateReferences` is enabled (default: `true`).
2. The single configured loader knows how to resolve that logical site/domain reference.

```text
shared.example/header
cdn.example/widgets/card
```

Domain-qualified references are logical site namespaces. Core does **not** automatically perform network requests for them.

The following URL/network forms are still rejected as template references:

```text
https://example.com/template
http://example.com/template
file:///tmp/template
//example.com/template
```

If a host wants `shared.example/header` to come from a database, object storage, an internal API, or another service, the application loader interprets the `domain/path` reference and performs that lookup. Core does not map domains to loaders.

## Disable domain-qualified references

```php
$options = new EngineOptions(
    allowDomainTemplateReferences: false,
);

$engine = new Engine($loader, $options);
```

With this option disabled, `render`, `include`, `import`, `extends`, components and `FunctionContext::load()` reject `site.example/path` before loader access.

This is a global kill switch. The reference is rejected before the configured loader receives it.

## No required file extension

The engine never appends or requires `.xt`. All of these are normal logical template names:

```text
main
partial.html
helpers.tpl
views/card.template
```

The selected loader decides how logical names map to stored templates.

## ArrayLoader

Useful for tests, generated templates and in-memory sources:

```php
$loader = new ArrayLoader([
    'page' => 'include partial.html',
    'partial.html' => 'print Hello $name',
]);
$loader->set('helpers.tpl', 'function helper\nreturn OK\nendfunction');
$loader->remove('helpers.tpl');
```

Relative include/import/component lookups are normalized by the loader.

`ArrayLoader` itself accepts local/logical names only. For multi-site/domain storage, implement one application `LoaderInterface` that understands the complete logical reference.

## FilesystemLoader

```php
$loader = new FilesystemLoader([
    __DIR__ . '/templates',
    __DIR__ . '/shared',
]);
```

Features:

- accepts one root or multiple roots
- canonicalizes configured roots
- resolves candidate files with `realpath`
- prevents resolved templates from escaping configured roots
- handles relative loading with the caller template as `from`
- supports files with any extension or no extension
- can disable absolute local path requests with `allowAbsolutePaths: false`
- does not interpret URL or domain-qualified names itself

Filesystem path authorization should still be designed at the application/tenant level by choosing appropriate loader roots.

## Domain-qualified references and one loader

Domain is not a loader. The Engine owns one `LoaderInterface`. With domain references enabled, a name such as:

```text
shared.example/pages/main
```

is passed to that same loader. A custom loader may use `TemplateReference::splitDomainQualified()` to separate the site identifier and logical path, then query the application's virtual filesystem/storage service.

Relative loads preserve the caller name through the existing `$from` argument. If `shared.example/pages/main` includes `helper`, the application loader can resolve it as `shared.example/pages/helper`. This matches the original XtScript architecture more closely: one filesystem service resolves both local and site-qualified paths.

The loader may be backed by local files, a database, Redis/object storage, or an internal API. If it performs network I/O internally, the host is responsible for timeout, TLS, redirect and SSRF policy. Core does not ship an unrestricted HTTP template loader.

## Custom loader

Implement `LoaderInterface` for database, Redis, object storage, an internal HTTP API, encrypted storage, tenant virtual filesystems, etc. The loader is the correct place for template-storage authorization.

`source` and compatibility `file_get_contents` core functions also use the configured loader instead of reading arbitrary host filesystem paths.
