# Phlogger

A fluent logging API for PHP.

Phlogger adds readable log statements, deferred computation and per-statement rate limiting to any PSR-3 logger. It requires **PHP 8.3+** and has exactly one production package dependency: **`psr/log`**. Bring your existing backend; Phlogger does not write files or configure handlers.

```php
use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\Level;

$logger = new FluentLogger($psrLogger, minimumLevel: Level::Info);

$logger->atWarning()
    ->withCause($exception)
    ->with('order_id', $orderId)
    ->atMostEvery(30)
    ->log('Payment failed for order {order_id}');

$logger->atDebug()
    ->with('summary', LazyValue::of(fn () => calculateSummary()))
    ->log('Diagnostic summary: {summary}');
```

The second statement does not call `calculateSummary()` because Phlogger's minimum level is `Info`.

## Installation

Install the package from [Packagist](https://packagist.org/packages/roukmoute/phlogger):

```sh
composer require roukmoute/phlogger
```

Requires PHP 8.3 or later. Composer installs a tagged release and `psr/log`; no custom repository configuration is needed. Monolog remains an optional backend chosen by your application.

## Levels and PSR-3 compatibility

Use `atDebug()`, `atInfo()`, `atNotice()`, `atWarning()`, `atError()`, `atCritical()`, `atAlert()` or `atEmergency()`. Dynamic levels accept `Level` enum cases or the exact lowercase PSR-3 names:

```php
$logger->at(Level::Notice)->log('Import completed');
$logger->at('notice')->log('Import completed');
```

`FluentLogger` also implements `Psr\Log\LoggerInterface`, so it can be injected into existing services:

```php
$logger->info('User {id} signed in', ['id' => 42]);
$logger->log('error', 'Request failed', ['exception' => $exception]);
```

These PSR-3 methods preserve arbitrary context values, including closures and `LazyValue` objects. Deferred values are evaluated only through the fluent API. Unknown levels throw `Psr\Log\InvalidArgumentException`, even when logging is disabled.

## Integration with Monolog

Phlogger is a fluent layer over **any PSR-3 logger**. Monolog is one supported backend: it owns handlers, destinations, formatting and buffering, while Phlogger provides the fluent statements and deferred computation.

If your application chooses Monolog, install it in that application:

```sh
composer require "monolog/monolog:^3.0"
```

```php
$handler = new Monolog\Handler\StreamHandler('php://stdout', Monolog\Level::Info);
$handler->setFormatter(new Monolog\Formatter\JsonFormatter());
$backend = new Monolog\Logger('app', [$handler], [
    new Monolog\Processor\PsrLogMessageProcessor(),
]);

$logger = new Phlogger\FluentLogger(
    $backend,
    filter: static fn (Phlogger\Level $level): bool =>
        $backend->isHandling($level->value),
);

$logger->atWarning()
    ->withCause($exception)
    ->with('order_id', 42)
    ->atMostEvery(30)
    ->log('Payment failed for order {order_id}');
```

`PsrLogMessageProcessor` interpolates `{placeholders}` from the context. Without it, Phlogger still forwards the original template and structured values. `JsonFormatter` formats the record for output, including exception details.

The optional filter consults Monolog's complete handler pipeline before Phlogger evaluates lazy messages or context. It follows later handler changes and avoids deferred work at disabled levels. Phlogger's own `minimumLevel` can further restrict those levels. `isHandling()` estimates level acceptance; buffering, routing and other handler policies can still affect delivery. Consult the backend pipeline rather than copying the threshold of a final file handler: `FingersCrossedHandler`, for example, can retain debug records until an error triggers a flush.

To record the application's calling file and line, configure Monolog's `IntrospectionProcessor` to skip Phlogger's wrapper frames:

```php
$backend->pushProcessor(new Monolog\Processor\IntrospectionProcessor(
    skipClassesPartials: ['Phlogger\\', 'Psr\\Log\\'],
));
```

Also skipping `Psr\Log\` handles inherited methods such as `info()`. Attach the processor to the **logger**, before handlers buffer records. Attaching it to a handler behind `FingersCrossedHandler` records the flush location instead of the original call. The [introspection example](examples/monolog-introspection.php) and integration tests cover distinct call sites, including buffered delivery.

In this repository, Monolog is a **development dependency only**, used by the executable [Monolog example](examples/monolog.php) and the [integration tests](tests/Integration/MonologIntegrationTest.php). Phlogger's production package dependency remains `psr/log` alone. To run the example from a checkout:

```sh
composer install
php examples/monolog.php
```

It writes three JSON records to stdout: an order, one rate-limited payment warning, and a completion message. Debug callbacks remain unevaluated. Applications using long-lived workers own Monolog's `reset()`/`close()` lifecycle; Phlogger exposes `resetRateLimits()` separately.

## Symfony and Laravel

Register `FluentLogger` as an additional service that wraps the framework's existing logger. Symfony can inject `@logger` or a named Monolog channel, or decorate `logger` so Phlogger becomes the default PSR logger. Laravel can use a scoped binding around its PSR logger or a selected channel, retaining Laravel's context and log events.

The [framework integration guide](docs/frameworks.md) contains application configuration, channel selection and worker lifecycle choices. Runnable [Symfony](examples/symfony.php) and [Laravel](examples/laravel.php) examples use real components and simulate sequential jobs without booting full application kernels.

The alternative [Symfony default logger example](examples/symfony-default-logger.php) makes `@logger`, `LoggerInterface` and `FluentLogger` resolve to one Phlogger instance. It retains the original backend as the inner service to avoid circular resolution.

Choose quota lifetime deliberately: a shared worker logger retains counters across jobs; an explicit reset or a fresh scoped instance starts new quotas. Contextual clones share their parent's limiter state. Registering the fluent service separately preserves the framework's existing PSR logger binding.

## Context and exceptions

```php
$requestLogger = $logger->withContext(['request_id' => $requestId]);
$entry = $requestLogger->atInfo()->with('component', 'checkout');

$entry->with('order_id', 42)->log('Order {order_id} created');
$entry->with('order_id', 43)->log('Order {order_id} created');

$requestLogger->atError()
    ->withCause($exception)
    ->log('Checkout failed', ['order_id' => 42]);
```

Logger context and entry modifiers return new objects. Reusing an entry does not leak context between statements. Context precedence is logger defaults, then entry context, then terminal `log()` context. Later values replace earlier ones without recursively merging arrays. `withCause()` writes PSR-3's `exception` key; `withCause(null)` sets it to `null`.

`log()` preserves the message template and structured context for the backend. Placeholder interpolation and escaping belong to that backend. Strings and `Stringable` messages are supported.

## Deferred computation

```php
$logger->atDebug()->log(fn () => buildDiagnosticMessage());

$details = LazyValue::of(fn () => collectDiagnostics());
$logger->atDebug()
    ->with('details', $details)
    ->with('snapshot', $details)
    ->log('Diagnostics collected');

$logger->atDebug()->logf(
    'Cache size: %d',
    LazyValue::of(fn () => countCacheEntries()),
);
```

Lazy message closures must return a string or `Stringable`. `LazyValue` is evaluated for top-level fluent context values and `logf()` arguments; nested arrays and ordinary closures in context remain untouched. The same `LazyValue` object is evaluated once per emitted record, even when it appears in both context and formatted arguments. Reusing it in another record evaluates it again.

`logf()` uses PHP's `vsprintf()` after filtering. Arguments must resolve to scalar, `null` or `Stringable` values. Formatting errors and exceptions from deferred callbacks propagate to the caller.

PHP evaluates ordinary function arguments before the fluent API receives them. Wrap expensive work in a closure or `LazyValue` to defer it.

## Filtering and conditional logging

```php
$logger = new FluentLogger(
    $psrLogger,
    minimumLevel: Level::Info,
    filter: fn (Level $level): bool => $loggingEnabled,
);

$logger->atInfo()
    ->when(fn (): bool => $shouldReport)
    ->when($featureEnabled)
    ->log('Feature activated');
```

The minimum level is checked before the optional filter. Conditions run in chain order, after level filtering and before rate limiting. Multiple conditions must all pass. Deferred messages, context values and formatted arguments run only after all these gates pass.

PSR-3 does not expose a backend's enabled levels. Phlogger cannot discover whether that backend will discard a record. Configure Phlogger's minimum level/filter accordingly; the lazy guarantee applies when **Phlogger** rejects a statement. `isEnabled()` on the logger or an entry checks level filtering only, without evaluating conditions or consuming quotas. Filters and conditions should be side-effect-free.

## Rate limiting

```php
// First call, then every hundredth additional eligible call: 1, 101, 201, ...
$logger->atInfo()->every(100)->log('Still processing');

// At most one admitted record per 30 seconds; the first is immediate.
$logger->atWarning()->atMostEvery(30)->log('Queue remains full');

// Only the first three admitted records.
$logger->atError()->first(3)->log('Connection failed');

// Explicitly group calls made through helpers or multiple call sites.
$logger->atWarning()
    ->withRateLimitKey('queue-full')
    ->every(10)
    ->atMostEvery(30)
    ->log('Queue remains full');
```

Limits use the terminal `log()`/`logf()` file and line, the level and the limiter configuration. An explicit key replaces file and line. Two statements on the same line with the same level and policy share a quota; use distinct explicit keys when needed. Different levels or policies have separate quotas. Repeated configuration of a limiter replaces its previous value.

Combined limits must all be ready. Counters reset together when a call is admitted, so an elapsed interval can emit as soon as the count threshold has already been met. Rejected levels and conditions do not advance counters. Admission consumes the quota before deferred computation and backend delivery; a callback or backend exception does not refund it.

`every()` requires a positive integer. `first(0)` suppresses all calls. `atMostEvery()` accepts finite, non-negative seconds, including fractions; zero disables the time limit. The default clock uses monotonic `hrtime()`. An optional constructor `clock` callback can supply monotonic seconds for deterministic tests.

State is shared by contextual clones of one logger and is local to that logger family in the current process. It is not shared across PHP-FPM workers or hosts. Storage retains at most 1,024 policies by default; `rateLimitCapacity` configures this bound. Least recently used entries are evicted, which resets their quotas. `resetRateLimits()` clears state for the logger and all its contextual clones, useful at worker lifecycle boundaries.

## Documentation website

The documentation website is available in [English](https://roukmoute.github.io/phlogger/en/) and [French](https://roukmoute.github.io/phlogger/fr/). Its static source is under `docs/`. All Markdown documentation in the repository is in English.

The Pages workflow deploys on pushes to `master` and can also be started manually. To configure a fork, enable **Settings → Pages → Source → GitHub Actions**. Private repositories need a GitHub plan that supports Pages; published Pages content may be public depending on account settings. No build dependencies are needed.

## Benchmarks

Run the dependency-free API benchmark from a development checkout:

```sh
composer benchmark -- --iterations=100000 --samples=5
```

It compares direct PSR calls, guarded calls, fluent enabled and disabled statements, deferred values and rate limits with a `NullLogger` backend. Warm-up and repeated samples separate API overhead from file or network I/O. The [benchmark guide](benchmarks/README.md) explains the scenarios, machine-readable output and measurement limits. Timings are observations on the current machine, not CI performance thresholds.

## Development

```sh
composer install
composer validate --strict
composer check
php examples/basic.php
php examples/monolog.php
php examples/monolog-introspection.php
php examples/symfony.php
php examples/symfony-default-logger.php
php examples/laravel.php
```

Monolog, framework components, PHPUnit and PHPStan are development dependencies only. See [CONTRIBUTING.md](CONTRIBUTING.md) for the test and review workflow. CI runs tests, static analysis, all examples and a benchmark smoke check on PHP 8.3, 8.4 and 8.5, with Symfony DependencyInjection 7.4 / 8.x and Illuminate 12.x / 13.x compatibility sets. Isolated production-only installations contain exactly `psr/log`. Static analysis also targets the PHP 8.3 language level.

## License

MIT; see [LICENSE](LICENSE).
