<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

if (!class_exists(ContainerBuilder::class)) {
    fwrite(STDERR, "Run composer install in this checkout to install the example's development dependencies.\n");
    exit(1);
}

final readonly class OrderService
{
    public function __construct(private LoggerInterface $logger, private FluentLogger $fluentLogger)
    {
    }

    public function placeOrder(int $orderId): void
    {
        // Both constructor arguments resolve to the same Phlogger instance.
        // LoggerInterface exposes the standard PSR API to existing consumers.
        $this->logger->info('Order received', ['order_id' => $orderId]);

        // Use the concrete type when the consumer needs the fluent API.
        $this->fluentLogger->atInfo()->with('order_id', $orderId)
            ->with('summary', LazyValue::of(static fn (): array => ['items' => 3, 'total' => 49.90]))
            ->log(static fn (): string => 'Order summary prepared');
    }
}

// This standalone example models MonologBundle's aliases using Symfony's DI
// component. It does not boot a kernel or load an application's services.yaml.
$handler = new StreamHandler('php://stdout');
$handler->setFormatter(new JsonFormatter());
$container = new ContainerBuilder();
$container->register('monolog.handler.app', StreamHandler::class)->setSynthetic(true)->setPublic(true);
$container->register('monolog.logger', Logger::class)
    ->setArguments(['app', [new Reference('monolog.handler.app')]])
    ->setPublic(true);
$container->setAlias('logger', 'monolog.logger')->setPublic(true);
$container->setAlias(LoggerInterface::class, 'logger')->setPublic(true);
$container->register(FluentLogger::class)
    ->setDecoratedService('logger', 'phlogger.backend')
    ->setArgument('$backend', new Reference('phlogger.backend'))
    ->setPublic(true);
$container->register(OrderService::class, OrderService::class)->setAutowired(true)->setPublic(true);
$container->compile();
$container->set('monolog.handler.app', $handler);

/** @var FluentLogger $logger */
$logger = $container->get('logger');
/** @var OrderService $orders */
$orders = $container->get(OrderService::class);
/** @var Logger $backend */
$backend = $container->get('monolog.logger');

try {
    if ($logger !== $container->get(LoggerInterface::class) || $logger !== $container->get(FluentLogger::class)) {
        throw new LogicException('The default logger and its autowiring aliases must resolve to Phlogger.');
    }

    $orders->placeOrder(42);
} finally {
    $backend->close();
}
