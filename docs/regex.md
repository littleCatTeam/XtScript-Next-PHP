# Regular expressions (PCRE2)

XtScript delegates regular-expression matching to PHP's PCRE implementation. On supported PHP 8.2+ runtimes this is PCRE2, so XtScript does not implement a reduced regex dialect.

Supported pattern capabilities therefore include ordinary PCRE2 features such as:

- character classes, Unicode properties and modifiers
- numbered and named capture groups
- alternation and quantifiers
- lookahead and lookbehind
- numbered/named backreferences
- non-capturing and atomic groups
- anchors and boundaries
- inline modifiers and normal PHP/PCRE pattern modifiers

Invalid patterns raise `RegexException` for operations that execute a pattern. Use `regex_valid` or the `regex` test when validation without an exception is desired.

## Regex literals

Expression syntax accepts `/pattern/modifiers` as a first-class literal:

```text
if $name matches /^[a-z][a-z0-9_-]{2,31}$/i
    print valid
endif
```

A regex literal evaluates to the complete PCRE pattern string, including its `/.../` delimiters and modifiers. This avoids double-escaping backslashes inside ordinary XtScript strings.

Escaped delimiters and character classes are recognized:

```text
if $path matches /^\/api\/[a-z0-9/_-]+$/i
    print api
endif
```

Other PCRE delimiters are supported by functions/filters when supplied as strings, for example `~...~u`; the literal syntax itself uses `/.../modifiers`.

Division remains arithmetic:

```text
print (8 / 2)
```

## `matches` and `not matches`

```text
if $value matches /\d+/
    print contains-number
endif

if $value not matches /^admin$/i
    print not-admin
endif
```

`matches` is implemented through the core `matches` test, so `SecurityPolicyInterface::allowsTest('matches')` also controls the infix operator.

Equivalent test form:

```text
if $value is matches(/\d+/)
    print yes
endif
```

Equivalent filter form:

```text
print $value | matches(/\d+/)
```

## Captures

`regex_match` returns the first match or `null`. PHP-compatible numeric keys and named capture keys are retained. Unmatched capture groups are represented as `null`.

```text
assign $match = ("post-42" | regex_match(/(?<type>[a-z]+)-(?<id>\d+)/))
print $match.type
print $match.id
```

`regex_match_all` returns matches in set order:

```text
assign $matches = ("a1 b22 c333" | regex_match_all(/(?<number>\d+)/))
print $matches | length
```

Pass `true` as the final offset-capture argument when byte offsets are needed:

```text
assign $match = ($text | regex_match(/foo/, 0, true))
```

## Replacement

```text
print "abc123" | regex_replace(/(\d+)/, "[$1]")
```

Replacement strings use PHP `preg_replace` capture-reference semantics. Pattern and replacement arrays are accepted by the `regex_replace` system function for multi-pattern replacement.

## Splitting

```text
print "a,,b,c" | regex_split(/,+/) | join("|")
```

Split flags can be an integer or names separated by spaces, commas, or `|`:

- `no_empty`
- `delim_capture`
- `offset_capture`

Example:

```text
print "a,,b,c" | regex_split(/,/, -1, "no_empty") | join("|")
```

## Grep/filtering collections

```text
print ["alpha", "42", "apple"] | regex_grep(/^a/) | join("|")
```

The optional second filter argument inverts the result.

## Quoting user text

Use `regex_quote` before placing literal/user-provided text into a generated pattern:

```text
print "a+b" | regex_quote
```

The function form also accepts an optional one-byte delimiter.

## Core system functions

- `regex_test`
- `regex_match`
- `regex_match_all`
- `regex_count`
- `regex_replace`
- `regex_split`
- `regex_grep`
- `regex_quote`
- `regex_valid`

See [core-functions.md](core-functions.md) for argument names.

## Core filters

- `matches`
- `regex_match`
- `regex_match_all`
- `regex_count`
- `regex_replace`
- `regex_split`
- `regex_grep`
- `regex_quote`

See [filters.md](filters.md).

## Core tests

- `matches(pattern, offset?)`: subject matches the pattern
- `regex`: the value itself is a syntactically valid PCRE pattern

## Limits and safety

Regex APIs cap materialized match/split/grep/replacement results at 100,000 items/operations per call. Template/source/context limits continue to apply normally.

PCRE execution itself also inherits the PHP runtime's PCRE limits such as `pcre.backtrack_limit` and `pcre.recursion_limit`. Applications that accept untrusted regex patterns should use conservative PHP PCRE limits and an XtScript `SecurityPolicyInterface`; regex support is powerful enough to consume significant CPU when a pathological pattern is allowed.

No `/e`-style executable replacement is provided. Regex matching never evaluates matched text as PHP code.
