# Escaping strategies

Ordinary `print` output is escaped by default. `print_raw` and the `raw` filter remain explicit opt-outs.

`EngineOptions::escapeStrategy` sets the default strategy when `autoEscape` is enabled. The default is `EscapeStrategy::Html`.

Available strategies:

| Strategy | Intended context |
|---|---|
| `html` | ordinary HTML text |
| `html_attr` | quoted HTML attributes |
| `js` | JavaScript string content |
| `css` | CSS string content |
| `url` | URL component via `rawurlencode` |
| `none` | no escaping |

Scoped template syntax:

```text
autoescape html_attr
print $title
endautoescape

autoescape js
print $value
endautoescape
```

The `escape` / `e` filters accept a strategy:

```text
print $value | escape("html_attr")
print $value | e("js")
```

This is explicit context-aware escaping. The engine does not guess arbitrary HTML/JavaScript parser state, so templates remain deterministic and legacy syntax is unaffected.
