# Exceptions

All package-specific exception categories derive from `XtScript\Exception\XtScriptException`, which extends `RuntimeException`.

## `XtScriptException`

Base runtime/package error. Resource-budget failures and some evaluator runtime errors may surface through this base category or a more specific subclass.

## `SyntaxErrorException`

Parser/syntax error. Extra fields:

- `template: ?string`
- `templateLine: ?int`

The rendered exception message includes template/line location when available.

## `TemplateNotFoundException`

Thrown when a loader cannot resolve a requested template. Field:

- `template: string`

## `PluginException`

Invalid/duplicate plugin registrations, invalid definitions and capability-name conflicts.

## `SecurityException`

Denied function/filter/test/tag/template operation under the configured security policy.

## Host/runtime exceptions

Strict helper functions may also surface ordinary PHP exceptions such as `InvalidArgumentException`, `LengthException`, `DomainException`, or `JsonException` when registered functionality receives invalid input. Applications may catch `Throwable` at a top-level render boundary when they need to normalize all failures.

- `TemplateContractException` — optional typed host context failed validation.

## `RegexException`

Raised for invalid PCRE patterns, unsupported regex flags, or regex result/operation limits. Pattern validation through `regex_valid` / the `regex` test returns false instead of throwing.
