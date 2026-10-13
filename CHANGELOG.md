# Changelog

## 0.1.0 — 2026-10-13

First public API release. Requires PHP 8.5+ and `psr/log` 3.x as the only production package dependency.

- Add a PSR-3 compatible `FluentLogger` with all eight fluent level methods and a `Level` enum.
- Add immutable entry and logger context, exception causes and conditional logging.
- Add deferred messages and explicit `LazyValue` context/format arguments, evaluated once per admitted record.
- Add deferred `printf` formatting through `logf()` while preserving PSR-3 templates through `log()`.
- Add count and time rate limits, per-call-site or explicit keys, bounded shared state and reset support.
- Add behavior tests, strict static analysis, an executable example and production-only CI validation.
- Add English Markdown documentation and a static English/French GitHub Pages website.

This is a pre-1.0 release: the public API may change in subsequent minor releases. Rate limits are process-local and do not coordinate multiple workers.
