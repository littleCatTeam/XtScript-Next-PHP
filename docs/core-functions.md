# Core system functions

System functions are called with XtScript's `call` syntax:

```text
print call strlen $val="hello";
assign $part = call substr $val="abcdef";$start=2;$length=2;
```

Arguments are named with `$...` and normally separated by `;`. Functions are supplied by `CorePlugin`; plugins can register additional names.

## Encoding, hashing and escaping

| Function | Arguments | Result / notes |
|---|---|---|
| `urlencode` | `$val` | PHP-compatible URL form encoding |
| `urldecode` | `$val` | URL form decoding |
| `rawurlencode` | `$val` | RFC3986-style encoding |
| `rawurldecode` | `$val` | raw URL decoding |
| `crc32` | `$val` | CRC32 value |
| `md5` | `$val` | MD5 hex digest; compatibility utility, not password security |
| `sha1` | `$val` | SHA-1 hex digest; compatibility utility, not password security |
| `base64_encode` | `$val` | base64 string |
| `base64_decode` | `$val` | strict base64 decode; invalid input becomes empty string |
| `bin2hex` | `$val` | hex string |
| `hex2bin` | `$val` | binary string; invalid/odd hex becomes empty string |
| `hexdec` | `$val` | hexadecimal to number |
| `dechex` | `$val` | non-negative decimal integer to hex |
| `htmlspecialchars` | `$val` | HTML escape using UTF-8/ENT_QUOTES/ENT_SUBSTITUTE/ENT_HTML5 |

## String functions

| Function | Arguments | Result / notes |
|---|---|---|
| `lcfirst` | `$val` | lowercase first byte/character according to PHP string semantics |
| `ucfirst` | `$val` | uppercase first byte/character |
| `ucwords` | `$val` | uppercase word initials |
| `strtoupper` | `$val` | uppercase string |
| `strtolower` | `$val` | lowercase string |
| `trim` | `$val` | trim whitespace |
| `ltrim` | `$val` | trim left |
| `rtrim` | `$val` | trim right |
| `nl2br` | `$val` | insert HTML `<br>` around newlines |
| `br2nl` | `$val` | convert common `<br>` forms to newline |
| `str_shuffle` | `$val` | shuffled string |
| `addslashes` | `$val` | add backslashes with PHP semantics |
| `stripslashes` | `$val` | remove backslashes with PHP semantics |
| `strrev` | `$val` | reverse string |
| `strlen` | `$val` | byte length |
| `chr` | `$val` | character for low 8 bits of integer |
| `ord` | `$val` | byte value of first character; empty input returns empty string |
| `str_replace` | `$search`, `$replace`, `$subject` | case-sensitive replacement |
| `str_ireplace` | `$search`, `$replace`, `$subject` | case-insensitive replacement |
| `substr` | `$val`, `$start`, optional `$length` | substring; `$length` is genuinely optional |
| `str_repeat` | `$val`, `$multiplier` | bounded repeated string; result capped by core guard |
| `str_pad` | `$val`, `$pad_length`, optional `$pad_string`, `$pad_type` | pad right/left/both; max requested length is bounded |
| `strip_tags` | `$val`, optional `$allowable_tags` | strip HTML/PHP tags using PHP behavior |
| `strpos` | `$haystack`, `$needle`, optional `$offset` | first position or false |
| `strrpos` | `$haystack`, `$needle`, optional `$offset` | last position or false |
| `stripos` | `$haystack`, `$needle`, optional `$offset` | case-insensitive first position or false |
| `strripos` | `$haystack`, `$needle`, optional `$offset` | case-insensitive last position or false |
| `strstr` | `$haystack`, `$needle`, optional `$before_needle` | substring from/before first needle or false |
| `stristr` | `$haystack`, `$needle`, optional `$before_needle` | case-insensitive `strstr` |
| `strrchr` | `$haystack`, `$needle` | substring from last occurrence or false |
| `starts_with` | `$val`/`$haystack`, `$prefix`/`$needle` | boolean prefix test |
| `ends_with` | `$val`/`$haystack`, `$suffix`/`$needle` | boolean suffix test |

## Regular-expression functions

Patterns use PHP PCRE2 syntax. See [regex.md](regex.md) for literals, captures, operators, safety and examples.

| Function | Arguments | Result / notes |
|---|---|---|
| `regex_test` | `$pattern`, `$subject`/`$val`, optional `$offset` | boolean first-match test |
| `regex_match` | `$pattern`, `$subject`/`$val`, optional `$offset`, `$offset_capture` | first match map with numeric/named captures, or null |
| `regex_match_all` | `$pattern`, `$subject`/`$val`, optional `$offset`, `$limit`, `$offset_capture` | bounded list of set-ordered matches |
| `regex_count` | `$pattern`, `$subject`/`$val`, optional `$offset`, `$limit` | number of matches |
| `regex_replace` | `$pattern`, `$replacement`, `$subject`/`$val`, optional `$limit` | PCRE replacement; pattern/replacement/subject arrays supported where PHP supports them |
| `regex_split` | `$pattern`, `$subject`/`$val`, optional `$limit`, `$flags` | PCRE split; named flags include `no_empty`, `delim_capture`, `offset_capture` |
| `regex_grep` | `$pattern`, `$values`/`$val`, optional `$invert` | filter a bounded collection while preserving keys |
| `regex_quote` | `$val`/`$literal`, optional `$delimiter` | `preg_quote`-style literal escaping |
| `regex_valid` | `$pattern`/`$val` | validate a PCRE pattern without throwing |

## Numeric functions

| Function | Arguments | Result / notes |
|---|---|---|
| `abs` | `$num` | absolute value |
| `ceil` | `$num` | ceiling |
| `floor` | `$num` | floor |
| `round` | `$num`, optional `$precision` | rounded number |
| `pow` | `$num`, `$exp` | power |
| `sqrt` | `$num` | square root; negative values are rejected |
| `pi` | none | π |
| `mt_rand` | optional `$min`, `$max` | legacy-compatible pseudo-random integer |
| `random` | `$values`/`$val` **or** `$min`, `$max` | cryptographic `random_int` choice/range helper |

## Collection and data functions

Collection-producing helpers materialize only bounded collections.

| Function | Arguments | Result / notes |
|---|---|---|
| `range` | `$start`, `$end`, optional `$step` | bounded integer list; step cannot be zero |
| `min` | `$values`/`$val` | smallest collection value |
| `max` | `$values`/`$val` | largest collection value |
| `first` | `$values`/`$val` | first item or empty value |
| `last` | `$values`/`$val` | last item or empty value |
| `keys` | `$values`/`$val` | array keys |
| `join` | `$values`/`$val`, optional `$glue`/`$separator` | concatenate iterable values |
| `split` | `$val`, `$delimiter`, optional `$limit` | bounded string split |
| `sort` | `$values`/`$val` | sorted array |
| `shuffle` | `$values`/`$val` | shuffled array |
| `merge` | `$left`/`$a`, `$right`/`$b` | `array_merge`-style merge |
| `json_encode` | `$val` | JSON with exceptions, unescaped Unicode/slashes |
| `json_decode` | `$val` | JSON decoded as arrays; invalid JSON throws |
| `cycle` | `$values`/`$val`, `$index` | item at normalized cyclic index |

## Date/runtime/template functions

| Function | Arguments | Result / notes |
|---|---|---|
| `date` | optional `$format`, `$timestamp` | PHP date formatting; current time if timestamp omitted |
| `execution_time` | none | elapsed render time text such as `0.001234s.` |
| `template_name` | none | current template name |
| `get_variable` | `$name` | read a context variable by dynamic name |
| `source` | `$file`/`$name` | load source text through the active `LoaderInterface` |
| `file_get_contents` | `$file`/`$name` | compatibility alias that also goes through `LoaderInterface`; never arbitrary raw filesystem access |

## Notes

- `source` and `file_get_contents` obey loader boundaries and security policy template checks through the normal loader path.
- Filters intentionally keep convenient names such as `upper`, `length`, `slice`, and `default` even though equivalent legacy system functions exist.
- System functions are not arbitrary PHP calls. Only registered `FunctionDefinition` entries are callable.
