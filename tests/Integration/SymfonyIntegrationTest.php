<?php

declare(strict_types=1);

namespace Phlogger\Tests\Integration;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\LogEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class SymfonyIntegrationTest extends TestCase
{
    public function testDecoratingLoggerMakesTheDefaultAndAutowiredPsrServicesFluent(): void
    {
        $handler = new TestHandler();
        $container = $this->decoratedContainer();
        $container->register(SymfonyDefaultLogConsumer::class)->setAutowired(true)->setPublic(true);
        $container->compile();
        $container->set('monolog.handler.app', $handler);

        $logger = $container->get('logger');
        self::assertInstanceOf(FluentLogger::class, $logger);
        self::assertSame($logger, $container->get(FluentLogger::class));
        self::assertSame($logger, $container->get(LoggerInterface::class));
        self::assertInstanceOf(Logger::class, $container->get('monolog.logger'));
        self::assertNotSame($logger, $container->get('monolog.logger'));

        $consumer = $container->get(SymfonyDefaultLogConsumer::class);
        self::assertInstanceOf(SymfonyDefaultLogConsumer::class, $consumer);
        self::assertSame($logger, $consumer->logger);
        self::assertSame($logger, $consumer->fluentLogger);

        // The normal PSR method preserves the template and its context unchanged.
        $psrLazy = LazyValue::of(static fn (): never => throw new \LogicException('PSR context must stay untouched'));
        $context = ['order_id' => 42, 'deferred' => $psrLazy];
        $consumer->logger->info('Order {order_id} received', $context);

        $calls = 0;
        $cause = new \RuntimeException('Payment provider unavailable');
        $consumer->fluentLogger->atError()->withCause($cause)
            ->with('provider', LazyValue::of(static function () use (&$calls): string {
                ++$calls;

                return 'payments';
            }))
            ->log(static fn (): string => 'Could not charge the order');

        self::assertCount(2, $handler->getRecords());
        self::assertSame('app', $handler->getRecords()[0]->channel);
        self::assertSame('Order {order_id} received', $handler->getRecords()[0]->message);
        self::assertSame($context, $handler->getRecords()[0]->context);
        self::assertSame('Could not charge the order', $handler->getRecords()[1]->message);
        self::assertSame(['exception' => $cause, 'provider' => 'payments'], $handler->getRecords()[1]->context);
        self::assertSame(1, $calls);
    }

    public function testTheExplicitPsrAliasCanOverrideAnAliasToTheRawMonologService(): void
    {
        $rawAliasContainer = $this->decoratedContainer();
        $rawAliasContainer->setAlias(LoggerInterface::class, 'monolog.logger')->setPublic(true);
        $rawAliasContainer->register(SymfonyDefaultLogConsumer::class)->setAutowired(true)->setPublic(true);
        $rawAliasContainer->compile();
        $rawAliasContainer->set('monolog.handler.app', new TestHandler());

        $rawAliasConsumer = $rawAliasContainer->get(SymfonyDefaultLogConsumer::class);
        self::assertInstanceOf(SymfonyDefaultLogConsumer::class, $rawAliasConsumer);
        self::assertSame($rawAliasContainer->get('monolog.logger'), $rawAliasConsumer->logger);
        self::assertInstanceOf(Logger::class, $rawAliasConsumer->logger);
        self::assertNotSame($rawAliasConsumer->logger, $rawAliasConsumer->fluentLogger);

        $handler = new TestHandler();
        $container = $this->decoratedContainer();
        $container->setAlias(LoggerInterface::class, 'monolog.logger')->setPublic(true);

        // Mirror the explicit alias in the default-logger recipe: autowiring must
        // follow @logger rather than a previous alias to the undecorated backend.
        $container->setAlias(LoggerInterface::class, 'logger')->setPublic(true);
        $container->register(SymfonyDefaultLogConsumer::class)->setAutowired(true)->setPublic(true);
        $container->compile();
        $container->set('monolog.handler.app', $handler);

        $consumer = $container->get(SymfonyDefaultLogConsumer::class);
        self::assertInstanceOf(SymfonyDefaultLogConsumer::class, $consumer);
        self::assertInstanceOf(FluentLogger::class, $consumer->logger);
        self::assertSame($container->get('logger'), $consumer->logger);
        self::assertSame($consumer->logger, $consumer->fluentLogger);

        $consumer->logger->warning('Provider unavailable');
        self::assertCount(1, $handler->getRecords());
        self::assertSame('app', $handler->getRecords()[0]->channel);
    }

    public function testDecoratingTheDefaultLoggerDoesNotReplaceNamedMonologChannels(): void
    {
        $appHandler = new TestHandler();
        $paymentsHandler = new TestHandler();
        $container = $this->decoratedContainer();
        $container->register('monolog.handler.payments', TestHandler::class)->setSynthetic(true)->setPublic(true);
        $container->register('monolog.logger.payments', Logger::class)
            ->setArguments(['payments', [new Reference('monolog.handler.payments')]])
            ->setPublic(true);
        $container->register('phlogger.payments', FluentLogger::class)
            ->setArgument('$backend', new Reference('monolog.logger.payments'))
            ->setPublic(true);
        $container->compile();
        $container->set('monolog.handler.app', $appHandler);
        $container->set('monolog.handler.payments', $paymentsHandler);

        $logger = $container->get('logger');
        $payments = $container->get('monolog.logger.payments');
        $fluentPayments = $container->get('phlogger.payments');
        self::assertInstanceOf(FluentLogger::class, $logger);
        self::assertInstanceOf(Logger::class, $payments);
        self::assertInstanceOf(FluentLogger::class, $fluentPayments);
        self::assertNotSame($logger, $payments);

        $logger->atInfo()->log('Application started');
        $payments->info('PSR payment accepted');
        $fluentPayments->atInfo()->log('Fluent payment accepted');

        self::assertCount(1, $appHandler->getRecords());
        self::assertSame('app', $appHandler->getRecords()[0]->channel);
        self::assertCount(2, $paymentsHandler->getRecords());
        self::assertSame('payments', $paymentsHandler->getRecords()[0]->channel);
        self::assertSame('payments', $paymentsHandler->getRecords()[1]->channel);
    }

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

    private function decoratedContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        // MonologBundle exposes a real service through the logger alias. A
        // synthetic backend itself cannot be decorated, so only the handler is synthetic.
        $container->register('monolog.handler.app', TestHandler::class)->setSynthetic(true)->setPublic(true);
        $container->register('monolog.logger', Logger::class)
            ->setArguments(['app', [new Reference('monolog.handler.app')]])
            ->setPublic(true);
        $container->setAlias('logger', 'monolog.logger')->setPublic(true);
        $container->setAlias(LoggerInterface::class, 'logger')->setPublic(true);
        $container->register(FluentLogger::class)
            ->setDecoratedService('logger', 'phlogger.backend')
            ->setArgument('$backend', new Reference('phlogger.backend'))
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

final readonly class SymfonyDefaultLogConsumer
{
    public function __construct(public LoggerInterface $logger, public FluentLogger $fluentLogger)
    {
    }
}
