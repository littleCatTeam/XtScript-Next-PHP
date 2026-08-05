# Typed contracts and dependency analysis

## Optional typed context contract

Legacy rendering remains unchanged:

```php
$engine->render('profile', ['name' => 'Cat']);
```

Hosts that want validation can use `TemplateContract`:

```php
use XtScript\TemplateContract;

$contract = new TemplateContract(
    types: [
        'name' => 'string',
        'count' => 'int',
        'note' => '?string',
    ],
    defaults: ['note' => null],
    allowExtra: false,
);

$html = $engine->renderWithContract('profile', $contract, [
    'name' => 'Cat',
    'count' => 2,
]);
```

Supported portable type names are `mixed`, `string`, `int`, `float`, `number`, `bool`, `array`, `list`, `iterable`, `scalar`, `object`, and `null`; prefix a type with `?` to permit null.

## Strict variables

```php
new EngineOptions(strictVariables: true);
```

Undefined values used directly then raise `SyntaxErrorException`. Safe undefined-aware constructs continue to work:

```text
$missing ?? "fallback"
$missing | default("fallback")
$missing is defined
```

## Dependency graph

```php
$graph = $engine->dependencies('main');
$direct = $graph->dependenciesOf('main');
$all = $graph->transitiveDependenciesOf('main');
```

Static dependencies are collected from `include`, `import`, `extends`, and `component`, including nested control-flow bodies. Domain-qualified logical references are passed through the same configured loader and respect `allowDomainTemplateReferences`.
