<?php

declare(strict_types=1);

namespace Phlogger\Tests\Integration;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level as MonologLevel;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\Level;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MonologIntegrationTest extends TestCase
{
    public function testAllEightLevelsReachMonologWithTheirMatchingSeverity(): void
    {
        $handler = new TestHandler(MonologLevel::Debug);
        $logger = new FluentLogger(new Logger('integration', [$handler]));
        $levels = [
            [Level::Debug, MonologLevel::Debug],
            [Level::Info, MonologLevel::Info],
            [Level::Notice, MonologLevel::Notice],
            [Level::Warning, MonologLevel::Warning],
            [Level::Error, MonologLevel::Error],
            [Level::Critical, MonologLevel::Critical],
            [Level::Alert, MonologLevel::Alert],
            [Level::Emergency, MonologLevel::Emergency],
        ];

        foreach ($levels as [$level]) {
            $logger->at($level)->log('entry '.$level->value);
        }

        $records = $handler->getRecords();
        self::assertCount(8, $records);
        foreach ($levels as $index => [$level, $monologLevel]) {
            self::assertSame($monologLevel, $records[$index]->level);
            self::assertSame('entry '.$level->value, $records[$index]->message);
            self::assertSame('integration', $records[$index]->channel);
        }
    }

    public function testPsrProcessorInterpolatesTheMessageAndPreservesContextAndCause(): void
    {
        $handler = new TestHandler();
        $backend = new Logger('integration', [$handler]);
        $backend->pushProcessor(new PsrLogMessageProcessor());
        $logger = (new FluentLogger($backend))->withContext(['service' => 'indexer', 'attempt' => 1]);
        $cause = new RuntimeException('Connection refused');

        $logger->atError()
            ->with('user', LazyValue::of(static fn (): string => 'Ada'))
            ->with('metadata', ['job' => 'import'])
            ->withCause($cause)
            ->log('Attempt {attempt} by {user} in {service} failed', ['attempt' => 2]);

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame('Attempt 2 by Ada in indexer failed', $records[0]->message);
        self::assertSame([
            'service' => 'indexer',
            'attempt' => 2,
            'user' => 'Ada',
            'metadata' => ['job' => 'import'],
            'exception' => $cause,
        ], $records[0]->context);
    }

    public function testMessageTemplatesRemainIntactWithoutAnInterpolationProcessor(): void
    {
        $handler = new TestHandler();
        $logger = new FluentLogger(new Logger('integration', [$handler]));

        $logger->atInfo()->with('user', 'Ada')->log('Hello {user}');

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame('Hello {user}', $records[0]->message);
        self::assertSame(['user' => 'Ada'], $records[0]->context);
    }

    public function testBackendFilterSkipsLazyWorkAndTracksHandlerChanges(): void
    {
        $warningHandler = new TestHandler(MonologLevel::Warning);
        $backend = new Logger('integration', [$warningHandler]);
        $filterChecks = 0;
        $messageCalls = 0;
        $contextCalls = 0;
        $logger = new FluentLogger($backend, filter: static function (Level $level) use ($backend, &$filterChecks): bool {
            ++$filterChecks;

            return $backend->isHandling($level->value);
        });
        $entry = $logger->atDebug()->with('payload', LazyValue::of(static function () use (&$contextCalls): int {
            ++$contextCalls;

            return 42;
        }));
        $message = static function () use (&$messageCalls): string {
            ++$messageCalls;

            return 'lazy debug';
        };

        $entry->log($message);
        self::assertSame(0, $messageCalls);
        self::assertSame(0, $contextCalls);
        self::assertSame([], $warningHandler->getRecords());

        $debugHandler = new TestHandler(MonologLevel::Debug);
        $backend->setHandlers([$debugHandler]);
        $entry->log($message);

        self::assertSame(1, $messageCalls);
        self::assertSame(1, $contextCalls);
        self::assertCount(1, $debugHandler->getRecords());
        self::assertSame('lazy debug', $debugHandler->getRecords()[0]->message);
        self::assertSame(['payload' => 42], $debugHandler->getRecords()[0]->context);

        $backend->setHandlers([$warningHandler]);
        $entry->log($message);

        self::assertSame(3, $filterChecks);
        self::assertSame(1, $messageCalls);
        self::assertSame(1, $contextCalls);
        self::assertSame([], $warningHandler->getRecords());
    }

    public function testFingersCrossedBuffersDebugAndFlushesItWhenAnErrorArrives(): void
    {
        $handler = new TestHandler(MonologLevel::Debug);
        $buffer = new FingersCrossedHandler($handler, MonologLevel::Error);
        $backend = new Logger('integration', [$buffer]);
        $logger = new FluentLogger($backend, filter: static fn (Level $level): bool => $backend->isHandling($level->value));
        $messageCalls = 0;
        $cause = new RuntimeException('Import failed');

        self::assertTrue($backend->isHandling(MonologLevel::Debug));
        $logger->atDebug()->with('step', 'prepare')->log(static function () use (&$messageCalls): string {
            ++$messageCalls;

            return 'preparing import';
        });

        self::assertSame(1, $messageCalls);
        self::assertSame([], $handler->getRecords());

        $logger->atError()->withCause($cause)->log('import failed');

        $records = $handler->getRecords();
        self::assertCount(2, $records);
        self::assertSame(MonologLevel::Debug, $records[0]->level);
        self::assertSame('preparing import', $records[0]->message);
        self::assertSame(['step' => 'prepare'], $records[0]->context);
        self::assertSame(MonologLevel::Error, $records[1]->level);
        self::assertSame('import failed', $records[1]->message);
        self::assertSame($cause, $records[1]->context['exception']);
    }

    public function testStreamHandlerWritesRateLimitedJsonWithStructuredExceptions(): void
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        $handler = new StreamHandler($stream, MonologLevel::Debug);
        $handler->setFormatter(new JsonFormatter());
        $backend = new Logger('integration', [$handler]);
        $backend->pushProcessor(new PsrLogMessageProcessor());
        $cause = new RuntimeException('Connection refused', 17);
        $lazyCalls = 0;
        $entry = (new FluentLogger($backend))->atError()
            ->with('job', 'import')
            ->with('items', LazyValue::of(static function () use (&$lazyCalls): int {
                ++$lazyCalls;

                return 42;
            }))
            ->withCause($cause)
            ->every(2)
            ->withRateLimitKey('json-import');

        try {
            for ($attempt = 1; $attempt <= 4; ++$attempt) {
                $entry->log('Task {job} failed', ['attempt' => $attempt]);
            }

            rewind($stream);
            $output = stream_get_contents($stream);
            self::assertIsString($output);
            $lines = explode("\n", trim($output));
            self::assertCount(2, $lines);
            self::assertSame(2, $lazyCalls);

            foreach ($lines as $index => $line) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('Task import failed', $record['message']);
                self::assertSame('ERROR', $record['level_name']);
                self::assertSame('integration', $record['channel']);
                self::assertSame('import', $record['context']['job']);
                self::assertSame(42, $record['context']['items']);
                self::assertSame(1 + 2 * $index, $record['context']['attempt']);
                self::assertSame(RuntimeException::class, $record['context']['exception']['class']);
                self::assertSame('Connection refused', $record['context']['exception']['message']);
                self::assertSame(17, $record['context']['exception']['code']);
                self::assertSame($cause->getFile().':'.$cause->getLine(), $record['context']['exception']['file']);
            }
        } finally {
            $handler->close();
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
