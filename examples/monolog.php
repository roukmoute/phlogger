<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level as MonologLevel;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\Level;

if (!class_exists(Logger::class)) {
    fwrite(STDERR, "Run composer install in this checkout to install the example's development dependencies.\n");
    exit(1);
}

// Monolog owns the destination, output format and placeholder interpolation.
$handler = new StreamHandler('php://stdout', MonologLevel::Info);
$handler->setFormatter(new JsonFormatter());
$backend = new Logger('app', [$handler], [new PsrLogMessageProcessor()]);

// Ask the complete handler pipeline whether it accepts this level before lazy work.
$logger = (new FluentLogger(
    $backend,
    filter: static fn (Level $level): bool => $backend->isHandling($level->value),
))->withContext(['request_id' => 'demo-request']);

try {
    $logger->atInfo()
        ->with('order_id', 42)
        ->with('summary', LazyValue::of(static fn (): string => '2 items'))
        ->log('Order {order_id} created: {summary}');

    $cause = new RuntimeException('Payment provider unavailable');
    for ($attempt = 1; $attempt <= 2; ++$attempt) {
        $logger->atWarning()
            ->withCause($cause)
            ->with('attempt', $attempt)
            ->atMostEvery(30)
            ->log('Payment failed for order {order_id}', ['order_id' => 42]);
    }

    // Debug is rejected by Monolog's Info threshold, so neither callback runs.
    $logger->atDebug()
        ->with('diagnostics', LazyValue::of(static function (): never {
            throw new LogicException('Disabled debug context must not be evaluated.');
        }))
        ->log(static function (): never {
            throw new LogicException('Disabled debug message must not be evaluated.');
        });

    // Existing services can also use the standard PSR-3 methods on this wrapper.
    $logger->info('Request {request_id} completed');
} finally {
    $backend->close();
}
