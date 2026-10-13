# Changelog

## 0.1.5 — 2026-10-13

- Document and test application call-site information with Monolog's `IntrospectionProcessor`, including inherited PSR methods and buffered delivery.
- Add reproducible API benchmarks for direct PSR calls, enabled and disabled fluent statements, lazy values, reusable entries and rate limits, with repeated samples and JSON output.
- Add executable Symfony and Laravel component examples, an English integration guide and bilingual website recipes for container injection, channels and quota lifecycle choices.
- Test Symfony autowiring and channel routing, Laravel context and log events, scoped quotas, shared worker quotas and explicit resets with deterministic clocks.
- Expand quality CI to compatible Symfony DependencyInjection 7.4 / 8.x and Illuminate 12.x / 13.x sets across PHP 8.3, 8.4 and 8.5; smoke-check all examples and the benchmark without timing thresholds.

Framework components and Monolog are development dependencies only. Runtime code and the public API are unchanged; `psr/log` remains the only production package dependency.

## 0.1.4 — 2026-10-13

- Lower the minimum supported PHP version to 8.3 without changing the public API or runtime source code.
- Run quality and production-only CI checks on PHP 8.3, 8.4 and 8.5.
- Target PHP 8.3 in static analysis and update the English Markdown documentation and bilingual website requirements.

`psr/log` remains the only production package dependency.

## 0.1.3 — 2026-10-13

- Publish `roukmoute/phlogger` on Packagist with automatic GitHub updates.
- Document direct installation with `composer require roukmoute/phlogger` in the README and both website languages.
- Add documentation, homepage and issue-tracker links to the package metadata.

## 0.1.2 — 2026-10-13

- Describe Phlogger directly as a fluent PSR-3 logging API in the README, package metadata and both website languages.
- Remove external inspiration references and links from the current documentation.

## 0.1.1 — 2026-10-13

- Add an executable Monolog 3 example with JSON output, PSR-3 interpolation, exception context and deferred computation filtered through the backend's `isHandling()` method.
- Add integration tests against real Monolog handlers, processors, dynamic filtering and buffered delivery.
- Run the Monolog example in CI and verify that production-only installation contains exactly `psr/log`.
- Document the PSR-3 backend boundary and Monolog integration in the English Markdown documentation and both website languages.

Monolog is a development dependency only. The production dependency set and public API are unchanged.

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
