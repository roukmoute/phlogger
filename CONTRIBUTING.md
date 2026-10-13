# Contributing

Use PHP 8.3 or later. Install development dependencies with `composer install`, then run:

```sh
composer validate --strict
composer check
php examples/basic.php
php examples/monolog.php
```

Tests must cover observable behavior. Use an injected monotonic clock for time-based tests instead of sleeping. Verify that rejected statements avoid deferred computation and that fluent modifiers cannot contaminate reusable entries.

Keep runtime code compatible with PHP 8.3. CI runs the test suite, examples and production-only installation checks on PHP 8.3, 8.4 and 8.5. PHPStan targets PHP 8.3 even when executed with a newer runtime.

Keep `psr/log` as the only production package dependency. Development tools belong in `require-dev`. Preserve PSR-3 message templates and arbitrary context values when using the standard logger methods.

Monolog is a development dependency for integration tests and the backend example. Keep Monolog imports in tests and examples, rather than in `src/`. Use actual handlers and processors to verify the PSR-3 boundary, lazy filtering and buffered delivery. The production CI job checks that `composer update --no-dev` installs exactly `psr/log`.

Write source comments, commit messages and all Markdown documentation in English. Update both language editions of the HTML documentation when changing the public API. The site is plain HTML and CSS under `docs/` and requires no package manager or build step.

Open a pull request with a concrete description of the behavior change and its validation. The existing MIT license applies to contributions.
