# Core tests

Tests are used through `is` / `is not`:

```text
if $value is defined
if $items is iterable
if $number is divisible_by(3)
if $value is not empty
```

| Test | Arguments | True when... |
|---|---|---|
| `matches` | pattern, optional offset | stringable value matches a PCRE pattern |
| `regex` | none | value is a syntactically valid PCRE pattern |
| `defined` | none | expression/variable exists |
| `empty` | none | undefined or engine-empty |
| `null` | none | defined and exactly null |
| `iterable` | none | PHP `is_iterable` |
| `mapping` | none | value is an array |
| `sequence` | none | list array or Traversable |
| `even` | none | numeric integer value is even |
| `odd` | none | numeric integer value is odd |
| `divisible_by` | divisor | numeric value divisible by non-zero numeric divisor |
| `same_as` | value | strict `===` equality |
| `string` | none | string |
| `number` | none | engine-recognized numeric value |
| `array` | none | array |
| `boolean` | none | boolean |

`defined` receives special undefined-state information from the expression engine; an undefined variable is not silently confused with an explicitly defined null/empty value for this test.

The infix `matches` / `not matches` operators use the same `matches` test and security-policy permission. See [regex.md](regex.md).
