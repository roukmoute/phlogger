<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level as MonologLevel;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Phlogger\FluentLogger;

if (!class_exists(Logger::class)) {
    fwrite(STDERR, "Run composer install in this checkout to install the example's development dependencies.\n");
    exit(1);
}

$handler = new StreamHandler('php://stdout', MonologLevel::Debug);
$handler->setFormatter(new JsonFormatter());
$buffer = new FingersCrossedHandler($handler, MonologLevel::Error);
$backend = new Logger('app', [$buffer]);

// Skip wrapper and PSR helper frames to report the application's file and line.
// Attach this processor to the logger so it runs before records are buffered.
$backend->pushProcessor(new IntrospectionProcessor(skipClassesPartials: ['Phlogger\\', 'Psr\\Log\\']));
$logger = new FluentLogger($backend);

final class CheckoutService
{
    public function __construct(private readonly FluentLogger $logger)
    {
    }

    public function checkout(): void
    {
        // This record is buffered with its own original file, line and function.
        $this->logger->atInfo()->log('Preparing checkout');

        // The error flushes both records, each retaining its application location.
        $this->logger->atError()->log('Payment provider unavailable');

        // Standard PSR-3 convenience methods also report the application call site.
        $this->logger->info('Checkout aborted');
    }
}

try {
    (new CheckoutService($logger))->checkout();
} finally {
    $backend->close();
}
