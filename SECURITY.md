# Security Policy

XtScript Next for PHP is designed to execute template code with explicit capabilities and bounded resource usage. It is **not** an operating-system or process sandbox.

## Security model

The engine never executes template-authored PHP and never performs template-controlled PHP `require`. In the default `Auto` mode it may use a conservative generated-PHP fast path for validated instruction trees; see **PHP-eval fast path** below. Template file access goes through the configured `LoaderInterface`, and PHP plugins are registered by trusted host code.

The default runtime limits cover source bytes, instructions, execution time, output bytes, capture bytes, include/inheritance depth, function depth, loop iterations, context size, fragment-cache keys/TTL, once keys, named stacks, and named-stack bytes.

Auto-escaping is enabled by default. `print_raw`, the `raw` filter, and trusted plugin output are explicit escape hatches and must only receive content that the host considers safe.

## Untrusted templates

For user-authored or otherwise untrusted templates, configure a `SecurityPolicyInterface` such as `AllowListSecurityPolicy` and keep the loader tenant-aware. The policy can allow/deny functions, filters, tests, tags, and template loads. Optional `<xt:name>` plugin calls use the same tag policy under the identity `xt:name` (for example `xt:filelist`).

Imported function libraries use lexical namespaces. A library imported as `forms` resolves its own sibling/dependency functions inside `forms@...` and does not inherit caller-owned user functions. Nested imports compose namespaces (for example `forms@helpers@escape`). This prevents imported libraries from reaching private caller functions by guessing their names while preserving explicit system/plugin function capabilities.

Do not register PHP plugins that expose filesystem, network, database, process, secrets, or session capabilities unless those capabilities are intentionally available to the template author. This applies especially to optional XT-tag adapters: tags such as file-list, include, auth, forum, RSS, or counters can become high-privilege capabilities depending on the host implementation. Registered plugin code is trusted PHP and executes in the host process.

XT-tag expansion is post-render and bounded by the normal instruction/time/output limits. Unknown XT tags remain literal unless a specific or wildcard XT handler is registered. XT handler output is treated as rendered output rather than auto-escaped template data, so adapters must escape untrusted values themselves or return carefully constructed `Markup`.

## Loader requirements

A custom loader must enforce its own authorization boundary. In a multi-tenant application, resolving a template name is not sufficient authorization: the loader must ensure the active tenant/site is allowed to read that template.

`FilesystemLoader` canonicalizes paths and restricts resolved files to configured roots, including symlink checks. Custom storage/database loaders must provide equivalent logical isolation.

Legacy domain-qualified template references (`site.example/path`) are optional and can be disabled globally with `EngineOptions(allowDomainTemplateReferences: false)`. When enabled, the same configured `LoaderInterface` receives the complete logical reference; core never maps a domain to another loader. An application loader may resolve that reference through its site filesystem, database, storage service, or internal API. If that loader performs network I/O internally, it is responsible for TLS validation, timeouts, response-size limits, redirect policy, DNS/IP restrictions and SSRF protection. Core never treats `http://` or `https://` as a template reference.

## Long-running workers

Render execution state is per-render rather than static/global. This avoids sharing mutable parser/runtime counters between normal requests and is suitable for reuse in long-running PHP workers. Host applications remain responsible for isolating any mutable state inside their own plugins or storage adapters.

## Reporting a vulnerability

Please report security issues privately to the project maintainer before publishing exploit details. Include the affected version/commit, a minimal reproduction, expected behavior, and observed behavior.


## PHP-eval fast path

`ExecutionBackend::Auto` may compile a conservative subset of the validated instruction tree into a cached PHP closure for performance. The compiler emits only engine-controlled PHP syntax and `var_export()` literals; raw template source is never spliced into executable PHP. Unsupported instructions fall back to the evaluator.

For untrusted-template deployments using `SecurityPolicyInterface`, the fast path is disabled automatically and the evaluator is used. Hosts that prohibit `eval()` by policy can set `EngineOptions(executionBackend: ExecutionBackend::Evaluator)`.

## Generated PHP / AOT cache

`ExecutionBackend::PhpFile` is intended for trusted templates and writes content-hashed generated PHP closures to the host-configured `phpFileCacheDirectory`. The directory must not be writable by untrusted tenants. Generated source comes from the validated instruction tree, not raw template source. A configured `SecurityPolicyInterface` continues to force the portable evaluator.

## Asset formatting

HTML/CSS/JavaScript beautifiers and minifiers are formatting utilities, not sanitizers. Use the engine escaping strategies (`html`, `html_attr`, `js`, `css`, `url`) at the output context even when content has been formatted or minified.
