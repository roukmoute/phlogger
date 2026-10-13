# Phlogger

A fluent logging API for PHP, inspired by [Google's Flogger](https://github.com/google/flogger).

Phlogger adds readable log statements, deferred computation and per-statement rate limiting to any PSR-3 logger. It requires **PHP 8.5+** and has exactly one production package dependency: **`psr/log`**. Bring your existing backend; Phlogger does not write files or configure handlers.

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

Once a release is registered on Packagist:

```sh
composer require roukmoute/phlogger
```

Before the first release, add this repository to your application's `composer.json`:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/roukmoute/phlogger"}
    ],
    "require": {
        "roukmoute/phlogger": "^0.1"
    }
}
```

The `v0.1.0` release is available directly from GitHub. A private repository requires GitHub access configured in Composer.

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

The static documentation under `docs/` is available in [English](docs/en/index.html) and [French](docs/fr/index.html). All Markdown documentation in the repository is in English.

The Pages workflow deploys on pushes to `master` and can also be started manually. To configure a fork, enable **Settings → Pages → Source → GitHub Actions**. Private repositories need a GitHub plan that supports Pages; published Pages content may be public depending on account settings. No build dependencies are needed.

## Development

```sh
composer install
composer validate --strict
composer check
php examples/basic.php
```

PHPUnit and PHPStan are development dependencies only. See [CONTRIBUTING.md](CONTRIBUTING.md) for the test and review workflow. The CI also checks installation with production dependencies only and runs the executable example.

## License and inspiration

MIT; see [LICENSE](LICENSE). Phlogger is an independent PHP implementation inspired by Flogger's API design. It is not affiliated with Google and does not port Google's Java source code.
