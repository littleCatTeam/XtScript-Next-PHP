# HTML, CSS and JavaScript formatting

`XtScript\Formatter\CodeFormatter` is dependency-free and works under `php -n`.

Core filters:

- `beautify_html`
- `beautify_css`
- `beautify_js`
- `minify_css`
- `minify_js`

Examples:

```text
print_raw $html | beautify_html
print_raw $css | beautify_css
print_raw $css | minify_css
print_raw $js | beautify_js
print_raw $js | minify_js
```

PHP API:

```php
use XtScript\Formatter\CodeFormatter;

$pretty = CodeFormatter::beautifyHtml($html);
$cssMin = CodeFormatter::minifyCss($css);
$jsMin = CodeFormatter::minifyJs($js);
```


## Formatter block tags

For multiple output statements, the same formatter can wrap a lexical output block:

```text
beautify html
    print_raw $html
endbeautify

beautify css
    print_raw $css
endbeautify

beautify js
    print_raw $js
endbeautify

minify css
    print_raw $css
endminify

minify js
    print_raw $js
endminify
```

The body is normal XtScript and is rendered first. The captured result is then formatted once and emitted. `beautify` accepts `html`, `css`, or `js`; `minify` accepts `css` or `js`. HTML minification is intentionally not provided by the core formatter because whitespace can be semantically significant in HTML.

For a single value, the filters remain the shorter form. Block tags are useful when several statements collectively generate one HTML/CSS/JS fragment.

## Safety and compatibility

The CSS formatter uses a string-aware scanner and does not require a CSS extension. The JavaScript minifier is intentionally conservative: it removes comments and unnecessary horizontal whitespace while preserving statement newlines to avoid changing automatic-semicolon-insertion behavior. It recognizes strings, template literals and common regular-expression literals.

For maximum compression of complex production JavaScript, applications can still run an AST minifier such as their existing build pipeline outside XtScript. The core formatter is designed for portability and predictable behavior, not aggressive source rewriting.
