<?php

declare(strict_types=1);

namespace Phlogger\Tests;

use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\Level;
use Phlogger\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;

final class LogEntryTest extends TestCase
{
    public function testEntriesCanBeReusedWithoutMutatingTheirParents(): void
    {
        $backend = new RecordingLogger();
        $root = (new FluentLogger($backend))->atInfo();
        $withUser = $root->with('user', 7);
        $withMore = $withUser->withContext(['user' => 8, 'task' => 'import']);

        $root->log('root');
        $withUser->log('user');
        $withMore->log('more', ['task' => 'export']);
        $withUser->log('reused');

        self::assertNotSame($root, $withUser);
        self::assertNotSame($withUser, $withMore);
        self::assertSame([], $backend->records[0]['context']);
        self::assertSame(['user' => 7], $backend->records[1]['context']);
        self::assertSame(['user' => 8, 'task' => 'export'], $backend->records[2]['context']);
        self::assertSame(['user' => 7], $backend->records[3]['context']);
    }

    public function testCauseUsesThePsrExceptionContextKey(): void
    {
        $backend = new RecordingLogger();
        $cause = new RuntimeException('failure');
        $entry = (new FluentLogger($backend))->atError()->withCause($cause);

        $entry->log('failed');

        self::assertSame($cause, $backend->records[0]['context']['exception']);
    }

    public function testNullCauseCanClearTheCauseOnAClonedEntry(): void
    {
        $backend = new RecordingLogger();
        $cause = new RuntimeException('failure');
        $entry = (new FluentLogger($backend))->atError()->withCause($cause);

        $entry->withCause(null)->log('cleared');
        $entry->log('original');

        self::assertArrayHasKey('exception', $backend->records[0]['context']);
        self::assertNull($backend->records[0]['context']['exception']);
        self::assertSame($cause, $backend->records[1]['context']['exception']);
    }

    public function testTerminalContextCanOverrideTheCause(): void
    {
        $backend = new RecordingLogger();
        $builderCause = new RuntimeException('builder');
        $terminalCause = new RuntimeException('terminal');

        (new FluentLogger($backend))->atError()->withCause($builderCause)->log('failed', ['exception' => $terminalCause]);

        self::assertSame($terminalCause, $backend->records[0]['context']['exception']);
    }

    public function testFalseConditionSkipsAllLazyWork(): void
    {
        $backend = new RecordingLogger();
        $evaluations = 0;
        $work = static function () use (&$evaluations): string {
            ++$evaluations;
            return 'work';
        };

        (new FluentLogger($backend))->atInfo()->when(false)->with('lazy', LazyValue::of($work))->log($work);

        self::assertSame(0, $evaluations);
        self::assertSame([], $backend->records);
    }

    public function testConditionsAreReevaluatedEachTimeAnEntryIsLogged(): void
    {
        $backend = new RecordingLogger();
        $allowed = false;
        $entry = (new FluentLogger($backend))->atInfo()->when(static function () use (&$allowed): bool {
            return $allowed;
        });

        $entry->log('hidden');
        $allowed = true;
        $entry->log('shown');

        self::assertSame(['shown'], $backend->messages());
    }

    public function testChainedConditionsAreAllRequiredAndShortCircuit(): void
    {
        $backend = new RecordingLogger();
        $laterConditionCalls = 0;

        (new FluentLogger($backend))->atInfo()->when(false)->when(static function () use (&$laterConditionCalls): bool {
            ++$laterConditionCalls;
            return true;
        })->log('hidden');

        self::assertSame(0, $laterConditionCalls);
        self::assertSame([], $backend->records);
    }

    public function testIsEnabledDoesNotEvaluateConditionsOrConsumeLimits(): void
    {
        $backend = new RecordingLogger();
        $conditions = 0;
        $entry = (new FluentLogger($backend))->atInfo()->when(static function () use (&$conditions): bool {
            ++$conditions;
            return true;
        })->first(1)->withRateLimitKey('enabled-probe');

        self::assertTrue($entry->isEnabled());
        self::assertTrue($entry->isEnabled());
        self::assertSame(0, $conditions);
        $entry->log('first');
        $entry->log('second');

        self::assertSame(2, $conditions);
        self::assertSame(['first'], $backend->messages());
    }

    public function testStringableFluentMessageIsAccepted(): void
    {
        $backend = new RecordingLogger();
        $message = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        };

        (new FluentLogger($backend))->atInfo()->log($message);

        self::assertSame(['stringable'], $backend->messages());
    }

    public function testClosureMessageIsEvaluatedOnlyForAnEmittedRecord(): void
    {
        $backend = new RecordingLogger();
        $evaluations = 0;
        $entry = (new FluentLogger($backend))->atInfo()->first(1)->withRateLimitKey('lazy-message');

        for ($index = 0; $index < 3; ++$index) {
            $entry->log(static function () use (&$evaluations): string {
                ++$evaluations;
                return 'lazy message';
            });
        }

        self::assertSame(1, $evaluations);
        self::assertSame(['lazy message'], $backend->messages());
    }

    public function testPrintfFormatsArgumentsAndKeepsEntryContext(): void
    {
        $backend = new RecordingLogger();

        (new FluentLogger($backend))->atInfo()->with('job', 'index')->logf('Processed %d files for %s: %.1f%%', 3, 'Ada', 97.5);

        self::assertSame(['Processed 3 files for Ada: 97.5%'], $backend->messages());
        self::assertSame(['job' => 'index'], $backend->records[0]['context']);
    }

    public function testPrintfArgumentsAreNotEvaluatedForASuppressedLevel(): void
    {
        $backend = new RecordingLogger();
        $calls = 0;

        (new FluentLogger($backend, Level::Error))->atDebug()->logf('%s', LazyValue::of(static function () use (&$calls): string {
            ++$calls;
            return 'expensive';
        }));

        self::assertSame(0, $calls);
        self::assertSame([], $backend->records);
    }

    public function testPrintfAcceptsNullAndStringableArguments(): void
    {
        $backend = new RecordingLogger();
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'Ada';
            }
        };

        (new FluentLogger($backend))->atInfo()->logf('%s|%s|%d', $stringable, null, true);

        self::assertSame(['Ada||1'], $backend->messages());
    }

    #[DataProvider('invalidPrintfArguments')]
    public function testPrintfRejectsNonScalarArguments(mixed $argument): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FluentLogger(new RecordingLogger()))->atInfo()->logf('%s', $argument);
    }

    public static function invalidPrintfArguments(): iterable
    {
        yield 'array' => [[]];
        yield 'object' => [new \stdClass()];
        yield 'closure' => [static fn (): string => 'ordinary closure'];
        yield 'lazy array' => [LazyValue::of(static fn (): array => [])];
    }

    public function testPrintfValidationHappensAfterConditions(): void
    {
        $backend = new RecordingLogger();

        (new FluentLogger($backend))->atInfo()->when(false)->logf('%s', []);

        self::assertSame([], $backend->records);
    }

    public function testInvalidLazyMessageReturnIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new FluentLogger(new RecordingLogger()))->atInfo()->log(static fn (): int => 42);
    }

    public function testConditionExceptionsPropagate(): void
    {
        $backend = new RecordingLogger();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('condition failed');

        (new FluentLogger($backend))->atInfo()->when(static function (): bool {
            throw new RuntimeException('condition failed');
        })->log('unused');
    }
}
