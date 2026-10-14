# Symfony and Laravel integration

**English** · [Français](frameworks.fr.md)

Phlogger wraps the framework's existing PSR-3 logger. Install `roukmoute/phlogger` in your application; no Phlogger bundle or service-provider package is required. Keep framework dependencies in the application. Phlogger itself still requires only `psr/log` as a production package dependency.

Register `FluentLogger` as an additional service, or use Symfony service decoration to make it the default logger. Decoration must reference the original inner service rather than the logger it replaces, which would create a circular dependency. Laravel's recipe below registers an additional scoped service.

## Symfony

### Additional fluent service

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

### Make Phlogger the default `logger`

Use this alternative definition in `config/services.yaml` to make both `@logger` and autowired `Psr\Log\LoggerInterface` consumers receive Phlogger:

```yaml
services:
    Phlogger\FluentLogger:
        decorates: logger
        decoration_inner_name: phlogger.backend
        arguments:
            $backend: '@phlogger.backend'

    Psr\Log\LoggerInterface: '@logger'
```

Replace the additional-service definition above with this one. Symfony moves the original `logger` behind `phlogger.backend` and makes `logger` resolve to the `FluentLogger` decorator. The explicit PSR alias makes interface autowiring follow that same service. Do not use `$backend: '@logger'` in the decorator: that would inject Phlogger into itself.

Existing services typed as `LoggerInterface` continue using PSR-3 methods such as `info()` and `error()`. Type new fluent consumers as `FluentLogger` to expose `atInfo()` and the other fluent methods to static analysis; both types resolve to the same instance. Ordinary PSR calls retain their message templates and context and do not evaluate `LazyValue` objects.

Monolog continues handling records with its existing handlers, processors and reset lifecycle. The recipe decorates the default `logger` alias; explicitly injected services such as `@monolog.logger.payments` remain independent. Consumers that need backend-specific APIs can inject `@phlogger.backend` explicitly. Keep Phlogger's default debug minimum to forward all PSR levels, or deliberately configure `$minimumLevel` if you want application-wide filtering.

The runnable [default logger example](../examples/symfony-default-logger.php) compiles the container, autowires PSR and fluent consumers and emits records through the original Monolog backend. Integration tests also verify named-channel isolation and alias replacement without a circular reference. See Symfony's [service decoration documentation](https://symfony.com/doc/current/service_container/decoration.html).

### Shared worker quotas or per-message quotas

Symfony services are shared by default. Reusing the same service in a long-running worker retains its quotas across messages. Contextual clones share that state too. This is useful for a warning that should be emitted at most once per minute throughout a worker's lifetime.

For fresh quotas at each Symfony reset boundary, opt in explicitly:

For the additional-service variant:

```yaml
services:
    Phlogger\FluentLogger:
        arguments:
            $backend: '@logger'
        tags:
            - { name: kernel.reset, method: resetRateLimits }
```

For the default-logger variant, add the same `kernel.reset` tag to the decorator definition while retaining `$backend: '@phlogger.backend'`. Do not replace its backend with `@logger`.

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
php examples/symfony-default-logger.php
php examples/laravel.php
composer test
```

The examples use real framework components. Symfony's worker example suppresses the second job's warning, then admits a third job after an explicit reset; its default-logger example demonstrates service decoration and both injection types. Laravel's example emits once in each of two renewed scopes. They do not boot a complete Symfony kernel, Laravel application, Messenger worker or Octane server. The Laravel example explicitly clears scoped instances to demonstrate the boundary that the framework owns in an application.

Integration tests cover container injection, channel routing, Laravel context and events, shared worker limits, explicit resets and renewed scoped quotas. CI exercises Symfony DependencyInjection 7.4 and 8.x and Illuminate 12.x and 13.x on compatible PHP runtimes. These components are development dependencies only; production installation is checked separately for exactly `psr/log`.
