<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Phlogger\FluentLogger;
use Psr\Log\LoggerInterface;

if (!class_exists(Container::class)) {
    fwrite(STDERR, "Run composer install in this checkout to install the example's development dependencies.\n");
    exit(1);
}

// This standalone example uses Illuminate components, without booting Laravel.
$handler = new StreamHandler('php://stdout');
$handler->setFormatter(new JsonFormatter());
$backend = new Logger('app', [$handler]);
$app = new Container();
$laravelLogger = new LaravelLogger($backend, new Dispatcher($app));
$app->instance(LoggerInterface::class, $laravelLogger);

// Put this binding in AppServiceProvider::register() in a Laravel application.
// Keep Laravel's PSR logger: it provides channel context and MessageLogged events.
$app->scoped(FluentLogger::class, static fn (Container $app): FluentLogger => new FluentLogger(
    $app->make(LoggerInterface::class),
));

try {
    foreach (['job-1', 'job-2'] as $jobId) {
        /** @var FluentLogger $logger */
        $logger = $app->make(FluentLogger::class);
        $job = $logger->withContext(['job_id' => $jobId]);
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $job->atWarning()->atMostEvery(3600)->withRateLimitKey('provider-outage')
                ->log('Payment provider unavailable', ['attempt' => $attempt]);
        }

        // Laravel queue workers / Octane flush scoped bindings at lifecycle boundaries.
        // Simulate that boundary explicitly in this standalone component example.
        $app->forgetScopedInstances();
    }
} finally {
    $backend->close();
}
