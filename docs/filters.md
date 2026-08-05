# Core filters

Filters use pipeline syntax and can be chained:

```text
print $name | trim | upper
print $missing | default("Guest")
```

| Filter | Arguments | Behavior |
|---|---|---|
| `raw` | none | wrap value as trusted `Markup`; bypass ordinary `print` escaping |
| `escape` | optional strategy | escape using `html`, `html_attr`, `js`, `css`, or `url` and return safe `Markup` |
| `e` | optional strategy | alias of `escape` |
| `upper` | none | uppercase string |
| `lower` | none | lowercase string |
| `trim` | optional character mask | trim string |
| `capitalize` | none | lowercase then uppercase first character |
| `title` | none | lowercase then title-case words |
| `length` | none | length/count of supported value |
| `join` | optional separator | join iterable values |
| `split` | delimiter, optional limit | split string |
| `slice` | start, optional length | slice string/collection |
| `reverse` | none | reverse string/collection |
| `sort` | none | sorted array |
| `shuffle` | none | shuffled array |
| `keys` | none | iterable keys |
| `first` | none | first value |
| `last` | none | last value |
| `default` | fallback | fallback for undefined or engine-empty values |
| `replace` | search, replacement | string replacement |
| `json_encode` | none | JSON string with strict encoding |
| `url_encode` | none | `rawurlencode` |
| `matches` | pattern, optional offset | boolean PCRE match |
| `regex_match` | pattern, optional offset, offset-capture bool | first capture map or null |
| `regex_match_all` | pattern, optional offset, limit, offset-capture bool | bounded set-ordered match list |
| `regex_count` | pattern, optional offset, limit | match count |
| `regex_replace` | pattern, replacement, optional limit | PCRE replacement |
| `regex_split` | pattern, optional limit, split flags | PCRE split |
| `regex_grep` | pattern, optional invert bool | filter collection with PCRE |
| `regex_quote` | optional delimiter | quote input as regex literal text |
| `date` | optional format | format input timestamp or current time |
| `beautify_html` | optional indent string | dependency-free HTML pretty printing |
| `beautify_css` | optional indent string | CSS pretty printing |
| `beautify_js` | optional indent string | conservative JavaScript pretty printing |
| `minify_css` | none | string-aware CSS minification |
| `minify_js` | none | conservative JS minification that preserves statement newlines |

Filters are first-class plugin capabilities even when a legacy function has similar behavior. This is intentional because pipeline composition is distinct from `call`.

Regular-expression filters use PHP PCRE2 semantics; see [regex.md](regex.md).
