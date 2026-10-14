# Contributing

**English** · [Français](CONTRIBUTING.fr.md)

Use PHP 8.3 or later. Install development dependencies with `composer install`, then run:

```sh
composer validate --strict
composer check
php examples/basic.php
php examples/monolog.php
php examples/monolog-introspection.php
php examples/symfony.php
php examples/symfony-default-logger.php
php examples/laravel.php
```

Tests must cover observable behavior. Use an injected monotonic clock for time-based tests instead of sleeping. Verify that rejected statements avoid deferred computation and that fluent modifiers cannot contaminate reusable entries.

Keep runtime code compatible with PHP 8.3. CI runs the test suite, examples and production-only installation checks on PHP 8.3, 8.4 and 8.5. Quality jobs select Symfony DependencyInjection 7.4 / 8.x and Illuminate 12.x / 13.x on compatible runtimes. PHPStan targets PHP 8.3 even when executed with a newer runtime.

Keep `psr/log` as the only production package dependency. Development tools belong in `require-dev`. Preserve PSR-3 message templates and arbitrary context values when using the standard logger methods.

Monolog is a development dependency for integration tests and the backend example. Keep Monolog imports in tests and examples, rather than in `src/`. Use actual handlers and processors to verify the PSR-3 boundary, lazy filtering and buffered delivery. The production CI job checks that `composer update --no-dev` installs exactly `psr/log`.

Symfony and Illuminate components are development dependencies for container, channel and quota lifecycle tests. Keep framework imports out of `src/`. Component tests explicitly exercise scope flushes or reset boundaries; do not describe them as full kernel, queue-worker or Octane tests. Document whether limits live for a request/job or for the worker, and test both shared and renewed state without sleeping.

Run `composer benchmark -- --iterations=100000 --samples=5` when investigating API costs. See [benchmarks/README.md](benchmarks/README.md) for comparable workloads and measurement limits. CI smoke-checks the runner's execution without timing assertions.

Write source comments and commit messages in English. Maintain every project Markdown document in English and French, with reciprocal language links and identical code examples. English remains the default. Update both HTML editions when changing the public API, and retain matching section IDs so language changes keep the reader's context. The site is plain HTML, CSS and local JavaScript under `docs/`; serving it requires no package manager or build step.

Run `python tools/check_documentation.py` and `node tests/documentation_navigation.cjs` to check paired editions, code examples, anchors, metadata and language navigation before publication. These checks use only the Python and Node.js standard libraries and run in CI and before Pages deployment.

Open a pull request with a concrete description of the behavior change and its validation. The existing MIT license applies to contributions.
