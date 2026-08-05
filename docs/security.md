# Security model

The engine is a bounded template runtime, **not an OS/process sandbox**.

## Default protections

- auto-escape ordinary `print` output with selectable HTML/attribute/JS/CSS/URL strategies
- explicit `print_raw` / `raw` escape hatches
- no arbitrary template-authored PHP execution
- no template-controlled PHP `require`
- no arbitrary function discovery: only registered functions are callable
- loader-only template/source reads
- canonical root checks in `FilesystemLoader`
- source/instruction/time/output/capture/depth/loop/context/cache/stack limits
- duplicate plugin/function/filter/test/tag/global protection
- lexical function-import namespaces
- post-render XT expansion with normal budgets
- per-render runtime state for long-lived workers
- PCRE operations use PHP PCRE2 limits plus bounded materialized regex results; `matches` is governed by the test allow-list

## Allow-list policy

```php
$policy = new AllowListSecurityPolicy(
    functions: ['strlen', 'range'],
    filters: ['escape', 'upper'],
    tests: ['defined', 'empty'],
    tags: ['print', 'if', 'foreach', 'xt:url'], // use cms:url if pluginTagPrefix is cms
    templates: ['safe/page.xt'],
);
```

For each category:

- `null` means unrestricted
- empty list means deny all
- values are exact names (capabilities are normalized appropriately)

Prefixed plugin handlers are checked as `<configured-prefix>:name` against the tag policy (`xt:name` by default).

## Untrusted templates

Use both:

1. a tenant/storage-aware loader, and
2. a `SecurityPolicyInterface`.

Do not expose high-privilege plugins (filesystem/network/database/session/secrets/process) unless the template author is intentionally allowed to use them.

A malicious PHP plugin remains malicious PHP; the template policy does not sandbox host plugin implementation.

## Output safety

`Markup` represents content intentionally considered already safe. Capture/component output may become safe markup. XT handler output is post-render output, so custom XT adapters are responsible for escaping untrusted data they insert.

## PHP backends

When a security policy is configured, the engine automatically uses the evaluator. Hosts that prohibit `eval()` can use `ExecutionBackend::Evaluator` or configure the AOT `ExecutionBackend::PhpFile` backend for trusted templates. Generated PHP is derived from validated typed instructions, written to a host-controlled cache directory, and never contains raw template source as executable PHP.

Context-selectable escaping is documented in `docs/escaping.md`. JavaScript/CSS formatter output is not a security boundary; escaping still belongs at the output context.

See the root `SECURITY.md` for the release security policy/reporting information.
