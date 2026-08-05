# XtScript syntax

This page documents parser-managed core syntax. Custom statement/block tags may be added by plugins.

## Mixed HTML wrapper

Without wrappers, the whole source is parsed as XtScript. Mixed HTML documents can mark script blocks:

```html
<header>plain HTML</header>
<!--parser:xtscript-->
print Hello $name
<!--/parser:xtscript-->
<footer>plain HTML</footer>
```

Wrapper matching is case-insensitive and whitespace tolerant. Multiple non-nested blocks are supported. Unmatched/nested wrappers are syntax errors.

## Variables

```text
assign $name = "Cat"
var $legacy=value
get $name
get_or_default $name;Guest
delete $name
del $name
```

`assign` and `var` are equivalent assignment commands; `delete` and `del` are aliases.

## Output

```text
print $value
print_raw $trusted
```

`print` escapes by default when `autoEscape` is enabled. `print_raw` emits raw output. `Markup`, capture output and explicit `raw`/`escape` filters cooperate with escaping state.

## Expressions

Supported expression concepts include:

- variables and dotted map access: `$user.name`
- strings, numbers, booleans and null
- arithmetic/comparison operators retained from legacy behavior
- boolean `and`, `or`, `not` with short-circuiting
- concatenation `~`
- membership `in`, `not in`
- PCRE2 matching `matches`, `not matches`, with regex literals `/pattern/modifiers`
- tests `is`, `is not`
- null coalescing `??`
- ternary `condition ? yes : no`
- filter pipelines `|`
- arrays `[1, 2, 3]`
- maps `{"name": "Cat", role: "admin"}`

Examples:

```text
assign $label = $enabled ? "yes" : "no"
assign $name = $user.name ?? "Guest"
print $name | trim | upper
if 2 in $items and $items is iterable
if $slug matches /^[a-z0-9-]+$/i
    print found
endif
```

Dotted access is data-oriented; the engine does not grant unrestricted arbitrary PHP object method/property execution. Regular expressions are documented in [regex.md](regex.md).

## Conditions

```text
if $enabled
    print yes
elseif $pending
    print pending
else
    print no
endif
```

Use `if not ...` rather than a duplicate `unless` keyword.

## Loops

```text
foreach $items as $key => $item
    print $loop.index:$item
else
    print empty
endforeach
```

A compatibility/additive `for` form is also accepted:

```text
for $item in $items
    print $item
endfor
```

Loop control:

```text
break
continue
```

`$loop` exposes: `index`, `index0`, `iteration`, `revindex`, `revindex0`, `remaining`, `first`, `last`, `even`, `odd`, `length`, `count`, `depth`, `parent`.

## Switch

```text
switch $status
case "ok"
    print OK
break
case "pending"
    print WAIT
break
default
    print UNKNOWN
endswitch
```

## User functions

```text
function greet $name="World";
    return Hello $name
endfunction

print call greet $name=Cat;
```

`function` is the single reusable user-defined primitive; there is no `macro` keyword.

## Function libraries and imports

```text
import forms as forms
print call forms@input $name=email;
```

Imports do not render top-level output from the imported template. Namespaces are lexical: sibling calls inside `forms@input` first resolve within `forms@...`; nested imports compose namespaces.

## Include

```text
include partial.html
```

Includes resolve through the active loader and share the render budgets.

## Labels and goto

```text
goto @done
print skipped
@done
print done
```

Legacy labels/goto remain bounded by instruction/time limits.

## Template inheritance

```text
extends layout
block content
    print Page
    parent
endblock
```

Core keywords: `extends`, `block`, `endblock`, `parent`. Compatibility aliases `section`, `endsection`, `yield` are also parser-managed.

## Components and slots

```text
component card.tpl $title="Hello";
    slot header
        print Header
    endslot
    print Body
endcomponent
```

The component gets `$slot` for the default body and `$slots` for named slots. Component arguments are isolated in a child scope.

## Capture

```text
capture $html
    print_raw <strong>safe</strong>
endcapture
print $html
```

Captured output is represented as safe markup and is separately bounded by `maxCaptureBytes`.

## Scoped variables

```text
with $name="temporary";
    print $name
endwith
```

The scope is popped after the block.

## Side-effect expression

```text
do call track $event="view";
```

`do` evaluates a registered expression/function call and discards its returned output.

## Once

```text
once "asset-key"
    print_raw <script src="/app.js"></script>
endonce
```

A key renders once per render state.

## Apply filters to a block

```text
apply trim | upper
    print_raw   hello  
endapply
```

The block is captured, transformed by the pipeline, then emitted.

## Autoescape scope

```text
autoescape off
    print $trusted
endautoescape
```

Accepted modes are `on`/`true`/`html`, `html_attr`, `js`, `css`, `url`, and `off`/`false`/`none`. State is lexical and restored afterward. See [escaping strategies](escaping.md).

## Verbatim

```text
verbatim
if $this_is_documentation
    print literal
endif
endverbatim
```

The body is emitted literally while still counting toward output limits.

## Fragment cache

```text
cache "homepage:" ~ $user_id;60
    print expensive
endcache
```

Requires a configured `FragmentCacheInterface`. The second field is TTL seconds and is bounded by `EngineOptions`.

## Named stacks

```text
push scripts
    print_raw <script src="/app.js"></script>
endpush

prepend scripts
    print_raw <script src="/first.js"></script>
endprepend

stack scripts
```

Stacks are per-render and bounded by `maxStacks`/`maxStackBytes`.

## Parser-managed core tag names

The current core registry contains these names (including boundaries/aliases):

`assign`, `var`, `get`, `get_or_default`, `delete`, `del`, `print`, `print_raw`, `return`, `call`, `include`, `extends`, `block`, `endblock`, `section`, `endsection`, `yield`, `parent`, `if`, `elseif`, `else`, `endif`, `foreach`, `endforeach`, `for`, `endfor`, `break`, `continue`, `switch`, `case`, `default`, `endswitch`, `component`, `slot`, `endslot`, `endcomponent`, `capture`, `endcapture`, `cache`, `endcache`, `with`, `endwith`, `do`, `once`, `endonce`, `apply`, `endapply`, `autoescape`, `endautoescape`, `import`, `verbatim`, `endverbatim`, `push`, `endpush`, `prepend`, `endprepend`, `stack`, `function`, `endfunction`, `goto`.


## Formatter blocks

`beautify` captures the block output and formats it as `html`, `css`, or `js`:

```text
beautify html
    print_raw $html
endbeautify
```

`minify` captures and conservatively minifies `css` or `js`:

```text
minify js
    print_raw $javascript
endminify
```

The corresponding closing tags are `endbeautify` and `endminify`. The formatter filters remain available for one-value pipelines.

