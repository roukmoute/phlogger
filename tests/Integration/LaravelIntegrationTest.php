<?php

declare(strict_types=1);

namespace Phlogger\Tests\Integration;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Context\ContextLogProcessor;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\LogManager;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\LogEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LaravelIntegrationTest extends TestCase
{
    public function testContainerInjectionPreservesLaravelContextAndLogEvents(): void
    {
        $handler = new TestHandler();
        $app = new Container();
        $events = new Dispatcher($app);
        $backend = new LaravelLogger(new Logger('app', [$handler]), $events);
        $backend->withContext(['request_id' => 'request-1', 'attempt' => 1]);
        $app->instance(LoggerInterface::class, $backend);
        $this->bindLogger($app, scoped: true);
        $received = [];
        $events->listen(MessageLogged::class, static function (MessageLogged $event) use (&$received): void {
            $received[] = $event;
        });

        $consumer = $app->make(LaravelLogConsumer::class);
        self::assertInstanceOf(LaravelLogConsumer::class, $consumer);
        self::assertSame($backend, $app->make(LoggerInterface::class));
        self::assertSame($app->make(FluentLogger::class), $consumer->logger);
        $cause = new RuntimeException('Connection refused');
        $consumer->logger->withContext(['job_id' => 'job-1'])->atError()
            ->with('payload', LazyValue::of(static fn (): int => 42))->withCause($cause)
            ->log('Import failed', ['attempt' => 2]);

        $context = ['request_id' => 'request-1', 'attempt' => 2, 'job_id' => 'job-1', 'payload' => 42, 'exception' => $cause];
        self::assertCount(1, $handler->getRecords());
        self::assertSame($context, $handler->getRecords()[0]->context);
        self::assertCount(1, $received);
        self::assertSame('error', $received[0]->level);
        self::assertSame('Import failed', $received[0]->message);
        self::assertSame($context, $received[0]->context);
    }

    public function testLogManagerChannelsKeepRoutingAndSharedContext(): void
    {
        // LogManager asks the application for this environment flag in addition
        // to its container API. Supply the flag without booting a full kernel.
        $app = new class extends Container {
            public function runningUnitTests(): bool
            {
                return false;
            }
        };
        $app->instance('config', new Repository(['logging' => [
            'default' => 'app',
            'channels' => [
                'emergency' => ['path' => 'php://stderr'],
                'app' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'name' => 'app'],
                'payments' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'name' => 'payments'],
            ],
        ]]));
        $app->instance('events', new Dispatcher($app));
        $app->bind(ContextLogProcessorContract::class, ContextLogProcessor::class);
        $manager = new LogManager($app);
        $manager->shareContext(['request_id' => 'request-1']);
        $app->instance(LoggerInterface::class, $manager);
        $this->bindLogger($app, scoped: true);
        $app->scoped('phlogger.payments', static fn (): FluentLogger => new FluentLogger($manager->channel('payments')));

        $default = $app->make(FluentLogger::class);
        $payments = $app->make('phlogger.payments');
        self::assertInstanceOf(FluentLogger::class, $default);
        self::assertInstanceOf(FluentLogger::class, $payments);
        $default->atInfo()->log('Application started');
        $payments->atInfo()->log('Payment accepted', ['order_id' => 42]);

        $appChannel = $manager->channel('app');
        $paymentsChannel = $manager->channel('payments');
        self::assertInstanceOf(LaravelLogger::class, $appChannel);
        self::assertInstanceOf(LaravelLogger::class, $paymentsChannel);
        $appBackend = $appChannel->getLogger();
        $paymentsBackend = $paymentsChannel->getLogger();
        self::assertInstanceOf(Logger::class, $appBackend);
        self::assertInstanceOf(Logger::class, $paymentsBackend);
        $appHandler = $appBackend->getHandlers()[0];
        $paymentsHandler = $paymentsBackend->getHandlers()[0];
        self::assertInstanceOf(TestHandler::class, $appHandler);
        self::assertInstanceOf(TestHandler::class, $paymentsHandler);
        self::assertCount(1, $appHandler->getRecords());
        self::assertCount(1, $paymentsHandler->getRecords());
        self::assertSame('app', $appHandler->getRecords()[0]->channel);
        self::assertSame('Application started', $appHandler->getRecords()[0]->message);
        self::assertSame('payments', $paymentsHandler->getRecords()[0]->channel);
        self::assertSame(['request_id' => 'request-1', 'order_id' => 42], $paymentsHandler->getRecords()[0]->context);
    }

    #[DataProvider('policies')]
    public function testScopedQuotasRestartWhenTheNextJobResolvesANewLogger(string $policy): void
    {
        $handler = new TestHandler();
        $app = new Container();
        $app->instance(LoggerInterface::class, new LaravelLogger(new Logger('worker', [$handler])));
        $this->bindLogger($app, scoped: true);

        $first = $app->make(FluentLogger::class);
        self::assertInstanceOf(FluentLogger::class, $first);
        self::assertSame($first, $app->make(FluentLogger::class));
        $this->logTwice($first->withContext(['job_id' => 'job-1']), $policy);
        self::assertCount(1, $handler->getRecords());

        // This is the container operation used at a lifecycle boundary.
        // The test does not boot an Octane server or a queue worker.
        $app->forgetScopedInstances();
        $second = $app->make(FluentLogger::class);
        self::assertInstanceOf(FluentLogger::class, $second);
        self::assertNotSame($first, $second);
        $this->logTwice($second->withContext(['job_id' => 'job-2']), $policy);

        self::assertCount(2, $handler->getRecords());
        self::assertSame(['job_id' => 'job-1'], $handler->getRecords()[0]->context);
        self::assertSame(['job_id' => 'job-2'], $handler->getRecords()[1]->context);
    }

    #[DataProvider('workerPolicies')]
    public function testSingletonQuotasSurviveScopeFlushUntilExplicitReset(string $policy, int $secondJobRecords): void
    {
        $handler = new TestHandler();
        $app = new Container();
        $app->instance(LoggerInterface::class, new LaravelLogger(new Logger('worker', [$handler])));
        $this->bindLogger($app, scoped: false);
        $logger = $app->make(FluentLogger::class);
        self::assertInstanceOf(FluentLogger::class, $logger);
        $this->logTwice($logger->withContext(['job_id' => 'job-1']), $policy);

        $app->forgetScopedInstances();
        self::assertSame($logger, $app->make(FluentLogger::class));
        $secondJob = $logger->withContext(['job_id' => 'job-2']);
        $this->logTwice($secondJob, $policy);
        self::assertCount(1 + $secondJobRecords, $handler->getRecords());

        $secondJob->resetRateLimits();
        $this->logTwice($logger->withContext(['job_id' => 'job-3']), $policy);
        self::assertCount(2 + $secondJobRecords, $handler->getRecords());
        self::assertSame(['job_id' => 'job-3'], $handler->getRecords()[1 + $secondJobRecords]->context);
    }

    /** @return iterable<string, array{string}> */
    public static function policies(): iterable
    {
        yield 'every' => ['every'];
        yield 'first' => ['first'];
        yield 'time' => ['time'];
    }

    /** @return iterable<string, array{string, int}> */
    public static function workerPolicies(): iterable
    {
        yield 'every' => ['every', 1];
        yield 'first' => ['first', 0];
        yield 'time' => ['time', 0];
    }

    private function bindLogger(Container $app, bool $scoped): void
    {
        $factory = static fn (Container $app): FluentLogger => new FluentLogger(
            $app->make(LoggerInterface::class),
            clock: static fn (): float => 100.0,
        );
        if ($scoped) {
            $app->scoped(FluentLogger::class, $factory);
        } else {
            $app->singleton(FluentLogger::class, $factory);
        }
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

final readonly class LaravelLogConsumer
{
    public function __construct(public FluentLogger $logger)
    {
    }
}
