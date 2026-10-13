# Symfony and Laravel integration

Phlogger wraps the framework's existing PSR-3 logger. Install `roukmoute/phlogger` in your application; no Phlogger bundle or service-provider package is required. Keep framework dependencies in the application. Phlogger itself still requires only `psr/log` as a production package dependency.

Register `FluentLogger` separately from `Psr\Log\LoggerInterface`. This preserves existing logging configuration and avoids a circular factory that resolves the PSR logger it is replacing.

## Symfony

Add this definition under `services` in `config/services.yaml`:

```yaml
services:
    Phlogger\FluentLogger:
        arguments:
            $backend: '@logger'
```

Use constructor injection in an application service:

```php
namespace App\Service;

use Phlogger\FluentLogger;

final class ImportService
{
    public function __construct(private readonly FluentLogger $logger)
    {
    }

    public function import(int $batchId): void
    {
        $this->logger->atInfo()
            ->with('batch_id', $batchId)
            ->log('Import started for batch {batch_id}');
    }
}
```

With Symfony's usual autowiring configuration, this injects the additional fluent service. The original `logger` and PSR logger alias continue to serve other consumers.

For a dedicated Monolog channel, define that channel in `config/packages/monolog.yaml`, then change the fluent service's backend:

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['payments']

# config/services.yaml
services:
    Phlogger\FluentLogger:
        arguments:
            $backend: '@monolog.logger.payments'
```

Handlers, routing and processors stay in MonologBundle's configuration. This selects the backend channel; it does not automatically select Phlogger's enabled levels. Set `$minimumLevel` to a lowercase PSR-3 name, or use an application factory with the [Monolog filter recipe](../README.md#integration-with-monolog) to avoid lazy work at backend-disabled levels.

### Shared worker quotas or per-message quotas

Symfony services are shared by default. Reusing the same service in a long-running worker retains its quotas across messages. Contextual clones share that state too. This is useful for a warning that should be emitted at most once per minute throughout a worker's lifetime.

For fresh quotas at each Symfony reset boundary, opt in explicitly:

```yaml
services:
    Phlogger\FluentLogger:
        arguments:
            $backend: '@logger'
        tags:
            - { name: kernel.reset, method: resetRateLimits }
```

The tag is acted on when Symfony resets services, including Messenger's normal between-message reset. Disabling service resets changes this behavior. In a custom sequential loop, call `resetRateLimits()` between jobs yourself. This resets every contextual clone of that logger family, so do not call it while another concurrent job is using that family.

Resetting Phlogger's quotas is separate from resetting a backend's buffers and processors. Continue to use the framework's normal Monolog lifecycle. Choose whether rate limits are per job or per worker before enabling the tag: a per-job reset makes a one-minute limit admit the first warning of every job.

See Symfony's official [logging documentation](https://symfony.com/doc/current/logging.html), [reset tag reference](https://symfony.com/doc/current/reference/dic_tags.html#kernel-reset) and [Messenger documentation](https://symfony.com/doc/current/messenger.html).

## Laravel

Add a scoped binding in `App\Providers\AppServiceProvider::register()`:

```php
use Illuminate\Contracts\Foundation\Application;
use Phlogger\FluentLogger;
use Psr\Log\LoggerInterface;

public function register(): void
{
    $this->app->scoped(
        FluentLogger::class,
        static fn (Application $app): FluentLogger => new FluentLogger(
            $app->make(LoggerInterface::class),
        ),
    );
}
```

Inject `FluentLogger` into controllers, services or jobs. Laravel resolves the existing PSR logger through its logging manager. The wrapper retains Laravel's context and `MessageLogged` events instead of bypassing them with the underlying Monolog object.

For a configured channel, change only the factory's backend to `$app->make('log')->channel('payments')`. For a stack, use `$app->make('log')->stack(['single', 'slack'])`. Channels and handlers remain configured in `config/logging.php`.

The factory above leaves Phlogger's minimum at `debug`. PSR-3 cannot report backend level acceptance. Set `minimumLevel` in the factory to your application policy when you need Phlogger to reject lazy computation early. If adding a backend-aware filter, continue writing through the Laravel logger rather than unwrapping it for delivery.

### Scoped and singleton lifetimes

Laravel renews scoped bindings for each request or queue job, including Octane and long-running queue workers. Resolving `FluentLogger` again in the next scope creates fresh quotas. Inside a scope, repeated resolutions and contextual clones share quotas.

Use `singleton()` instead of `scoped()` only when you intentionally want worker-wide quotas. That instance and its limits survive scope changes until explicitly reset or the process ends. Avoid keeping a scoped logger or one of its contextual clones inside a singleton consumer: that consumer would retain the old scope's state. Resolve scoped consumers again for each job and keep request identifiers local to that scope.

Phlogger's reset does not clear Laravel's shared context or Monolog buffers. The application remains responsible for their lifecycle. Do not reset a shared logger while concurrent work still depends on its quotas.

See Laravel's official [logging documentation](https://laravel.com/docs/13.x/logging), [scoped bindings](https://laravel.com/docs/13.x/container#binding-scoped-singletons) and [Octane dependency injection guidance](https://laravel.com/docs/13.x/octane#dependency-injection-and-octane).

## Executable examples and validation

From a development checkout:

```sh
composer install
php examples/symfony.php
php examples/laravel.php
composer test
```

The examples use real framework components and simulate sequential jobs. Symfony's example suppresses the second job's warning, then admits a third job after an explicit reset. Laravel's example emits once in each of two renewed scopes. They do not boot a complete Symfony kernel, Laravel application, Messenger worker or Octane server. The Laravel example explicitly clears scoped instances to demonstrate the boundary that the framework owns in an application.

Integration tests cover container injection, channel routing, Laravel context and events, shared worker limits, explicit resets and renewed scoped quotas. CI exercises Symfony DependencyInjection 7.4 and 8.x and Illuminate 12.x and 13.x on compatible PHP runtimes. These components are development dependencies only; production installation is checked separately for exactly `psr/log`.
