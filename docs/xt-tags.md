# Optional prefixed plugin tags

Prefixed plugin tags are **not** built-in XtScript services. Core only exposes a generic post-render plugin hook through `XtTagDefinition` / `Engine::addXtTag()`.

The default prefix is `xt`, so existing compatibility plugins can use `<xt:name ...>`. Applications may change the prefix globally per Engine:

```php
$options = new EngineOptions(pluginTagPrefix: 'cms');
$engine = new Engine($loader, $options, plugins: [$plugin]);
```

The same registered definitions are then invoked as `<cms:name ...>`. With that Engine, `<xt:name ...>` is ordinary literal output.

`pluginTagPrefix` must start with an ASCII letter and may contain letters, digits, `_`, `.`, and `-`.

## Specific tag

```php
new XtTagDefinition(
    'hello',
    static fn (
        FunctionContext $context,
        string $name,
        array $attributes,
        ?string $body,
        string $raw,
    ): string => 'Hello ' . ($attributes['name'] ?? 'guest'),
);
```

With the default prefix:

```html
<xt:hello name="Cat" />
<xt:hello name="Cat">
<xt:panel mode="compact">body</xt:panel>
```

With `pluginTagPrefix: 'cms'`:

```html
<cms:hello name="Cat" />
<cms:hello name="Cat">
<cms:panel mode="compact">body</cms:panel>
```

Attributes support common quoted, unquoted, and boolean forms. Names and the configured prefix are matched case-insensitively.

## Wildcard

Register `*` to receive otherwise-unregistered names under the active prefix:

```php
new XtTagDefinition('*', $handler);
```

A specific handler wins over the wildcard.

## Processing model

Prefixed tags are processed **after normal XtScript rendering**. This preserves historical patterns where an XtScript variable contains tag-shaped text and later prints it.

Nested/recursive expansion shares instruction/time/output budgets. Recursion that never stabilizes is stopped by normal resource limits.

When no tag definitions are registered, no post-render tag pass occurs. When definitions exist but output does not contain the configured `<prefix:` marker, the renderer also skips the regex pass.

## Security policy

Policy identity follows the configured prefix. Default:

```php
new AllowListSecurityPolicy(tags: ['print', 'xt:url']);
```

Custom prefix:

```php
$options = new EngineOptions(pluginTagPrefix: 'cms');
$policy = new AllowListSecurityPolicy(tags: ['print', 'cms:url']);
```

Wildcard registration does not bypass policy checks.

## XtGem compatibility example

`examples/xtgem-compatibility/` intentionally uses the default `xt` prefix and contains an adapter-based **example only**. Actual file/blog/forum/auth/etc. behavior belongs to the host adapter and is not part of core.
