<?php

declare(strict_types=1);

namespace Phlogger\Tests;

use Closure;
use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\Level;
use Phlogger\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Stringable;

final class FluentLoggerTest extends TestCase
{
    #[DataProvider('levelMethods')]
    public function testEveryFluentLevelDelegatesItsPsrName(string $method, Level $level): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend);

        $logger->{$method}()->log('hello');

        self::assertInstanceOf(LoggerInterface::class, $logger);
        self::assertSame($level->value, $backend->records[0]['level']);
        self::assertSame('hello', $backend->records[0]['message']);
    }

    public static function levelMethods(): iterable
    {
        yield ['atDebug', Level::Debug];
        yield ['atInfo', Level::Info];
        yield ['atNotice', Level::Notice];
        yield ['atWarning', Level::Warning];
        yield ['atError', Level::Error];
        yield ['atCritical', Level::Critical];
        yield ['atAlert', Level::Alert];
        yield ['atEmergency', Level::Emergency];
    }

    public function testAtAcceptsAnEnumAndAPsrLevelName(): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend);

        $logger->at(Level::Info)->log('enum');
        $logger->at('warning')->log('string');

        self::assertSame(['info', 'warning'], array_column($backend->records, 'level'));
    }

    public function testMinimumLevelSuppressesLessSevereMessages(): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend, 'warning');

        foreach (Level::cases() as $level) {
            $logger->at($level)->log($level->value);
        }

        self::assertSame(['warning', 'error', 'critical', 'alert', 'emergency'], $backend->messages());
        self::assertFalse($logger->isEnabled(Level::Info));
        self::assertTrue($logger->isEnabled('error'));
    }

    public function testSuppressedMessagesDoNotEvaluateMessageOrConditions(): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend, Level::Error);
        $evaluations = 0;
        $work = static function () use (&$evaluations): string {
            ++$evaluations;
            return 'expensive';
        };

        $logger->atDebug()->when(static function () use (&$evaluations): bool {
            ++$evaluations;
            return true;
        })->with('value', LazyValue::of($work))->log($work);

        self::assertSame(0, $evaluations);
        self::assertSame([], $backend->records);
    }

    public function testDirectPsrMethodsKeepMessageAndContextObjectsUntouched(): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend);
        $message = new class implements Stringable {
            public function __toString(): string
            {
                return 'literal {key}';
            }
        };
        $closure = static fn (): string => 'closure';
        $lazy = LazyValue::of(static fn (): string => 'lazy');
        $context = ['key' => $closure, 'lazy' => $lazy];

        $logger->info($message, $context);

        self::assertSame($message, $backend->records[0]['message']);
        self::assertSame($context, $backend->records[0]['context']);
        self::assertInstanceOf(Closure::class, $backend->records[0]['context']['key']);
    }

    public function testDirectPsrLoggingUsesTheMinimumLevel(): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend, Level::Warning);

        $logger->debug('hidden');
        $logger->warning('visible');

        self::assertSame(['visible'], $backend->messages());
    }

    public function testFilterReceivesLevelsAndAppliesToBothLoggingApis(): void
    {
        $backend = new RecordingLogger();
        $seen = [];
        $logger = new FluentLogger($backend, filter: static function (Level $level) use (&$seen): bool {
            $seen[] = $level;
            return $level === Level::Error;
        });

        $logger->atInfo()->log('fluent hidden');
        $logger->atError()->log('fluent shown');
        $logger->warning('PSR hidden');
        $logger->error('PSR shown');

        self::assertSame([Level::Info, Level::Error, Level::Warning, Level::Error], $seen);
        self::assertSame(['fluent shown', 'PSR shown'], $backend->messages());
    }

    public function testFilterIsNotEvaluatedBelowTheMinimumLevel(): void
    {
        $calls = 0;
        $logger = new FluentLogger(new RecordingLogger(), Level::Error, static function (Level $level) use (&$calls): bool {
            ++$calls;
            return true;
        });

        self::assertFalse($logger->isEnabled(Level::Debug));
        $logger->atInfo()->log('hidden');
        self::assertSame(0, $calls);
    }

    public function testDirectPsrLoggingMergesDefaultsWithoutResolvingLazyValues(): void
    {
        $backend = new RecordingLogger();
        $lazy = LazyValue::of(static fn (): string => 'unresolved');
        $logger = (new FluentLogger($backend))->withContext(['request' => 7, 'value' => $lazy]);

        $logger->log(Level::Notice, 'hello {user}', ['user' => 'Ada']);

        self::assertSame('notice', $backend->records[0]['level']);
        self::assertSame('hello {user}', $backend->records[0]['message']);
        self::assertSame(['request' => 7, 'value' => $lazy, 'user' => 'Ada'], $backend->records[0]['context']);
    }

    public function testArbitraryPsrContextValuesArePreserved(): void
    {
        $backend = new RecordingLogger();
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        $object = new \stdClass();
        $context = [
            'object' => $object,
            'resource' => $resource,
            'nested' => ['object' => $object, 'resource' => $resource],
            'bool' => false,
            'null' => null,
            'int' => 7,
            'float' => 3.5,
        ];

        try {
            (new FluentLogger($backend))->info('arbitrary values', $context);
            self::assertSame($context, $backend->records[0]['context']);
        } finally {
            fclose($resource);
        }
    }

    public function testLoggerContextIsImmutableAndTerminalContextWins(): void
    {
        $backend = new RecordingLogger();
        $base = new FluentLogger($backend);
        $child = $base->withContext(['request' => 'r1', 'shared' => 'default']);
        $grandchild = $child->withContext(['user' => 7, 'shared' => 'new default']);

        $base->atInfo()->log('base');
        $child->atInfo()->with('shared', 'builder')->log('child', ['shared' => 'terminal']);
        $grandchild->atInfo()->log('grandchild');

        self::assertNotSame($base, $child);
        self::assertSame([], $backend->records[0]['context']);
        self::assertSame(['request' => 'r1', 'shared' => 'terminal'], $backend->records[1]['context']);
        self::assertSame(['request' => 'r1', 'shared' => 'new default', 'user' => 7], $backend->records[2]['context']);
    }

    #[DataProvider('invalidLevels')]
    public function testDirectPsrLoggingRejectsInvalidLevels(mixed $level): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FluentLogger(new RecordingLogger()))->log($level, 'bad level');
    }

    public static function invalidLevels(): iterable
    {
        yield ['trace'];
        yield [123];
        yield [null];
        yield [new \stdClass()];
    }
}
