# Plugins

`PluginInterface` is the **single extension mechanism**. There is no `ExtensionInterface`.

```php
interface PluginInterface
{
    public function getName(): string;
    public function getFunctions(): iterable;
    public function getFilters(): iterable;
    public function getTests(): iterable;
    public function getTags(): iterable;
    public function getBlockTags(): iterable;
    public function getXtTags(): iterable;
    public function getGlobals(): array;
}
```

`PluginTrait` supplies empty defaults for every capability except `getName()`.

## FunctionDefinition

```php
new FunctionDefinition(
    'app::hello',
    static function (FunctionContext $context, array $args): string {
        return 'Hello ' . ($args['$name'] ?? 'guest');
    },
);
```

Function names allow ordinary names and one `namespace::name` separator for host/plugin functions. User-defined imported XtScript functions use lexical `@` namespaces.

`FunctionContext` exposes:

- `variable(string $name, mixed $default = null)`
- `load(string $name): TemplateSource`
- `elapsedSeconds(): float`

## FilterDefinition

```php
new FilterDefinition(
    'bracket',
    static fn (Context $context, mixed $value, array $arguments, bool $defined): string => '[' . $value . ']',
);
```

The `$defined` flag allows filters such as `default` to distinguish missing variables from explicitly provided empty values.

## TestDefinition

```php
new TestDefinition(
    'positive',
    static fn (Context $context, mixed $value, array $arguments, bool $defined): bool => $defined && is_numeric($value) && $value > 0,
);
```

## Statement TagDefinition

```php
new TagDefinition(
    'bang',
    static fn (FunctionContext $context, string $arguments): string => strtoupper(trim($arguments)) . '!',
);
```

Core parser-managed tags have null handlers. Host statement tags provide a handler.

## BlockTagDefinition

```php
new BlockTagDefinition(
    'feature',
    'endfeature',
    static function (FunctionContext $context, string $arguments, Closure $renderBody): string {
        return $enabled ? $renderBody() : '';
    },
);
```

The body callback is lazy, so a plugin can choose whether/how often to render it. Names/end-tags are checked for conflicts with existing parser boundaries.

## Globals

A plugin returns associative values from `getGlobals()`. Host render variables override globals for that render.

## Complete plugin

See `examples/10-plugin/example.php`, which registers a function, filter, test, statement tag, block tag, optional prefixed plugin tag and global from one plugin.

## CorePlugin

`CorePlugin` is registered automatically and owns core functions, filters, tests and parser-managed tag declarations.

## CookiePlugin

Optional cookie capability:

```php
$store = new ArrayCookieStore();
$engine->addPlugin(new CookiePlugin($store, 'site-id'));
```

Functions:

- `cookie::get` — `$name`, optional `$default`
- `cookie::set` — `$name`, `$val`, optional `$expire`, `$path`, `$secure`, `$http_only`, `$same_site`
- `cookie::delete` — `$name`, optional `$path`

Physical cookie storage is namespaced. In production `NativeCookieStore` uses PHP cookies; tests/examples can use `ArrayCookieStore`.

## Trust boundary

Plugins are trusted PHP host code. The template security policy controls which registered capabilities a template may request; it cannot make a malicious PHP plugin safe.
