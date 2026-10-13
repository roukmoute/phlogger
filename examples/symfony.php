<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Phlogger\FluentLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

if (!class_exists(ContainerBuilder::class)) {
    fwrite(STDERR, "Run composer install in this checkout to install the example's development dependencies.\n");
    exit(1);
}

// This standalone example uses Symfony's DI component, without booting a kernel.
$handler = new StreamHandler('php://stdout');
$handler->setFormatter(new JsonFormatter());
$backend = new Logger('app', [$handler]);
$container = new ContainerBuilder();
$container->register('logger', Logger::class)->setSynthetic(true)->setPublic(true);
$container->setAlias(LoggerInterface::class, 'logger')->setPublic(true);
$container->register(FluentLogger::class)
    ->setArgument('$backend', new Reference('logger'))
    ->setPublic(true);
$container->compile();
$container->set('logger', $backend);

/** @var FluentLogger $logger */
$logger = $container->get(FluentLogger::class);

try {
    // A shared service retains its quota across consecutive worker messages.
    foreach (['job-1', 'job-2'] as $jobId) {
        $logger->withContext(['job_id' => $jobId])->atWarning()
            ->atMostEvery(3600)->withRateLimitKey('provider-outage')
            ->log('Payment provider unavailable');
    }

    // Opt into per-message quotas by resetting after all work for a job finishes.
    // In a full Symfony app, kernel.reset can call resetRateLimits() instead.
    $logger->resetRateLimits();
    $logger->withContext(['job_id' => 'job-3'])->atWarning()
        ->atMostEvery(3600)->withRateLimitKey('provider-outage')
        ->log('Payment provider unavailable');
} finally {
    $backend->close();
}
