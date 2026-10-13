<?php

declare(strict_types=1);

namespace Phlogger\Tests\Integration;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Phlogger\FluentLogger;
use Phlogger\LogEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class SymfonyIntegrationTest extends TestCase
{
    public function testAutowiringInjectsTheFluentServiceWithoutReplacingThePsrBackend(): void
    {
        $handler = new TestHandler();
        $backend = new Logger('app', [$handler]);
        $container = $this->container($backend);
        $container->register(SymfonyLogConsumer::class)->setAutowired(true)->setPublic(true);
        $container->compile();
        $container->set('logger', $backend);

        $consumer = $container->get(SymfonyLogConsumer::class);
        self::assertInstanceOf(SymfonyLogConsumer::class, $consumer);
        self::assertSame($backend, $container->get(LoggerInterface::class));
        self::assertSame($container->get(FluentLogger::class), $consumer->logger);

        $consumer->logger->atInfo()->with('order_id', 42)->log('Order received');
        self::assertCount(1, $handler->getRecords());
        self::assertSame('app', $handler->getRecords()[0]->channel);
        self::assertSame(['order_id' => 42], $handler->getRecords()[0]->context);
    }

    public function testAnExplicitMonologChannelRoutesOnlyToItsOwnBackend(): void
    {
        $appHandler = new TestHandler();
        $paymentsHandler = new TestHandler();
        $backend = new Logger('app', [$appHandler]);
        $payments = new Logger('payments', [$paymentsHandler]);
        $container = $this->container($backend);
        $container->register('monolog.logger.payments', Logger::class)->setSynthetic(true)->setPublic(true);
        $container->register('phlogger.payments', FluentLogger::class)
            ->setArgument('$backend', new Reference('monolog.logger.payments'))
            ->setPublic(true);
        $container->compile();
        $container->set('logger', $backend);
        $container->set('monolog.logger.payments', $payments);

        $logger = $container->get('phlogger.payments');
        self::assertInstanceOf(FluentLogger::class, $logger);
        $logger->atInfo()->log('Payment accepted');

        self::assertSame([], $appHandler->getRecords());
        self::assertCount(1, $paymentsHandler->getRecords());
        self::assertSame('payments', $paymentsHandler->getRecords()[0]->channel);
    }

    #[DataProvider('policies')]
    public function testSharedServicesKeepWorkerQuotasUntilAnExplicitReset(string $policy, int $secondJobRecords): void
    {
        $handler = new TestHandler();
        $backend = new Logger('worker', [$handler]);
        $container = $this->container($backend);
        $container->getDefinition(FluentLogger::class)->setArgument('$clock', static fn (): float => 100.0);
        $container->compile();
        $container->set('logger', $backend);

        $logger = $container->get(FluentLogger::class);
        self::assertInstanceOf(FluentLogger::class, $logger);
        $firstJob = $logger->withContext(['job_id' => 'job-1']);
        $this->logTwice($firstJob, $policy);
        self::assertCount(1, $handler->getRecords());

        $sameLogger = $container->get(FluentLogger::class);
        self::assertSame($logger, $sameLogger);
        $secondJob = $logger->withContext(['job_id' => 'job-2']);
        $this->logTwice($secondJob, $policy);
        self::assertCount(1 + $secondJobRecords, $handler->getRecords());

        // Resetting any contextual clone resets the shared logger family's quotas.
        // This component test calls the boundary explicitly; it does not boot Messenger.
        $secondJob->resetRateLimits();
        $this->logTwice($firstJob, $policy);
        self::assertCount(2 + $secondJobRecords, $handler->getRecords());
        self::assertSame(['job_id' => 'job-1'], $handler->getRecords()[1 + $secondJobRecords]->context);
    }

    /** @return iterable<string, array{string, int}> */
    public static function policies(): iterable
    {
        yield 'every' => ['every', 1];
        yield 'first' => ['first', 0];
        yield 'time' => ['time', 0];
    }

    private function container(Logger $backend): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('logger', $backend::class)->setSynthetic(true)->setPublic(true);
        $container->setAlias(LoggerInterface::class, 'logger')->setPublic(true);
        $container->register(FluentLogger::class)
            ->setArgument('$backend', new Reference('logger'))
            ->setPublic(true);

        return $container;
    }

    private function logTwice(FluentLogger $logger, string $policy): void
    {
        $entry = $this->entry($logger, $policy)->withRateLimitKey('worker-'.$policy);
        $entry->log('Provider unavailable');
        $entry->log('Provider unavailable');
    }

    private function entry(FluentLogger $logger, string $policy): LogEntry
    {
        return match ($policy) {
            'every' => $logger->atWarning()->every(2),
            'first' => $logger->atWarning()->first(1),
            'time' => $logger->atWarning()->atMostEvery(60),
        };
    }
}

final readonly class SymfonyLogConsumer
{
    public function __construct(public FluentLogger $logger)
    {
    }
}
