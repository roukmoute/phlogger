<?php

declare(strict_types=1);

namespace Phlogger\Tests;

use InvalidArgumentException;
use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\Level;
use Phlogger\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RateLimitingTest extends TestCase
{
    public function testEveryEmitsTheFirstThenEachNthEligibleCall(): void
    {
        $backend = new RecordingLogger();
        $entry = (new FluentLogger($backend))->atInfo()->every(3);

        for ($index = 1; $index <= 8; ++$index) {
            $entry->log((string) $index);
        }

        self::assertSame(['1', '4', '7'], $backend->messages());
    }

    public function testFirstLimitsTheNumberOfAdmittedRecords(): void
    {
        $backend = new RecordingLogger();
        $entry = (new FluentLogger($backend))->atInfo()->first(2);

        for ($index = 1; $index <= 4; ++$index) {
            $entry->log((string) $index);
        }

        self::assertSame(['1', '2'], $backend->messages());
    }

    public function testFirstZeroDisablesLoggingWithoutEvaluatingLazyValues(): void
    {
        $backend = new RecordingLogger();
        $calls = 0;

        (new FluentLogger($backend))->atInfo()->first(0)->log(static function () use (&$calls): string {
            ++$calls;
            return 'unused';
        });

        self::assertSame(0, $calls);
        self::assertSame([], $backend->records);
    }

    public function testTimeLimitAllowsTheFirstCallAndTheExactIntervalBoundary(): void
    {
        $backend = new RecordingLogger();
        $time = 10.0;
        $logger = new FluentLogger($backend, clock: static function () use (&$time): float {
            return $time;
        });
        $entry = $logger->atInfo()->atMostEvery(5.0)->withRateLimitKey('time');

        $entry->log('first');
        $time = 14.999;
        $entry->log('too early');
        $time = 15.0;
        $entry->log('boundary');
        $time = 19.0;
        $entry->log('still too early');
        $time = 20.0;
        $entry->log('next boundary');

        self::assertSame(['first', 'boundary', 'next boundary'], $backend->messages());
    }

    public function testZeroTimeIntervalDoesNotThrottle(): void
    {
        $backend = new RecordingLogger();
        $entry = (new FluentLogger($backend, clock: static fn (): float => 10.0))->atInfo()->atMostEvery(0)->withRateLimitKey('zero');

        $entry->log('one');
        $entry->log('two');

        self::assertSame(['one', 'two'], $backend->messages());
    }

    public function testCombinedOccurrenceAndTimeGatesResetOnlyWhenBothPass(): void
    {
        $backend = new RecordingLogger();
        $time = 0.0;
        $logger = new FluentLogger($backend, clock: static function () use (&$time): float {
            return $time;
        });
        $entry = $logger->atInfo()->every(3)->atMostEvery(10)->first(3)->withRateLimitKey('combined');

        for ($index = 1; $index <= 4; ++$index) {
            $entry->log((string) $index);
        }
        $time = 10.0;
        $entry->log('5');
        $time = 20.0;
        for ($index = 6; $index <= 10; ++$index) {
            $entry->log((string) $index);
        }
        $time = 30.0;
        $entry->log('11');

        self::assertSame(['1', '5', '8'], $backend->messages());
    }

    public function testEveryThirdCallAndFiveSecondIntervalAdmitAtTheBoundary(): void
    {
        $backend = new RecordingLogger();
        $time = 0.0;
        $logger = new FluentLogger($backend, clock: static function () use (&$time): float {
            return $time;
        });
        $entry = $logger->atInfo()->every(3)->atMostEvery(5)->withRateLimitKey('five-second-boundary');

        foreach ([0.0, 1.0, 2.0, 5.0] as $time) {
            $entry->log((string) $time);
        }

        self::assertSame(['0', '5'], $backend->messages());
    }

    public function testRepeatedModifiersReplaceThePreviousPolicy(): void
    {
        $backend = new RecordingLogger();
        $entry = (new FluentLogger($backend, clock: static fn (): float => 0.0))->atInfo()
            ->every(10)->every(1)
            ->first(1)->first(2)
            ->atMostEvery(10)->atMostEvery(0)
            ->withRateLimitKey('replaced');

        for ($index = 1; $index <= 3; ++$index) {
            $entry->log((string) $index);
        }

        self::assertSame(['1', '2'], $backend->messages());
    }

    public function testZeroIntervalUsesTheSamePolicyKeyAsNoTimeLimit(): void
    {
        $backend = new RecordingLogger();
        $entry = (new FluentLogger($backend))->atInfo()->first(1)->withRateLimitKey('zero-normalized');

        $entry->log('first');
        $entry->atMostEvery(0)->log('same quota');

        self::assertSame(['first'], $backend->messages());
    }

    public function testFilteredEventsDoNotConsumeOccurrenceLimits(): void
    {
        $backend = new RecordingLogger();
        $allowed = false;
        $logger = new FluentLogger($backend, filter: static function (Level $level) use (&$allowed): bool {
            return $allowed;
        });
        $entry = $logger->atInfo()->every(2)->first(2)->withRateLimitKey('filter');

        $entry->log('hidden one');
        $entry->log('hidden two');
        $allowed = true;
        $entry->log('one');
        $entry->log('two');
        $entry->log('three');

        self::assertSame(['one', 'three'], $backend->messages());
    }

    public function testLazyExceptionsConsumeAnAdmittedQuota(): void
    {
        $backend = new RecordingLogger();
        $entry = (new FluentLogger($backend))->atInfo()->first(1)->withRateLimitKey('lazy-error');

        try {
            $entry->log(static function (): never {
                throw new RuntimeException('callback failed');
            });
            self::fail('The callback exception should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('callback failed', $exception->getMessage());
        }
        $entry->log('quota already consumed');

        self::assertSame([], $backend->records);
    }

    public function testConditionsDoNotConsumeOccurrenceLimits(): void
    {
        $backend = new RecordingLogger();
        $enabled = false;
        $entry = (new FluentLogger($backend))->atInfo()->every(2)->first(2)->when(static function () use (&$enabled): bool {
            return $enabled;
        })->withRateLimitKey('condition');

        $entry->log('false one');
        $entry->log('false two');
        $enabled = true;
        $entry->log('eligible one');
        $entry->log('eligible two');
        $entry->log('eligible three');
        $entry->log('eligible four');
        $entry->log('eligible five');

        self::assertSame(['eligible one', 'eligible three'], $backend->messages());
    }

    public function testRateLimitedEventsDoNotEvaluateLazyMessagesOrValues(): void
    {
        $backend = new RecordingLogger();
        $calls = 0;
        $lazy = LazyValue::of(static function () use (&$calls): string {
            ++$calls;
            return 'value';
        });
        $entry = (new FluentLogger($backend))->atInfo()->every(3)->with('value', $lazy)->withRateLimitKey('lazy');

        for ($index = 0; $index < 3; ++$index) {
            $entry->log(static function () use (&$calls): string {
                ++$calls;
                return 'message';
            });
        }

        self::assertSame(2, $calls);
        self::assertSame(['message'], $backend->messages());
    }

    public function testExplicitKeysShareTheLimitAcrossDifferentTerminalLines(): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend);

        $logger->atInfo()->first(1)->withRateLimitKey('shared')->log('one');
        $logger->atInfo()->first(1)->withRateLimitKey('shared')->log('two');

        self::assertSame(['one'], $backend->messages());
    }

    public function testDefaultCallsiteKeysSeparateDifferentTerminalLines(): void
    {
        $backend = new RecordingLogger();
        $entry = (new FluentLogger($backend))->atInfo()->first(1);

        $entry->log('one');
        $entry->log('two');

        self::assertSame(['one', 'two'], $backend->messages());
    }

    public function testLogfUsesThePublicTerminalCallsite(): void
    {
        $backend = new RecordingLogger();
        $entry = (new FluentLogger($backend))->atInfo()->first(1);

        $entry->logf('%s', 'one');
        $entry->logf('%s', 'two');

        self::assertSame(['one', 'two'], $backend->messages());
    }

    public function testSameCallsiteSeparatesDifferentLevelsAndSettings(): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend);
        $entries = [
            $logger->atInfo()->first(1),
            $logger->atError()->first(1),
            $logger->atInfo()->first(2),
        ];

        foreach ($entries as $index => $entry) {
            $entry->log((string) $index);
        }

        self::assertSame(['0', '1', '2'], $backend->messages());
    }

    public function testExplicitKeyStillSeparatesLevelsAndSettings(): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend);

        $logger->atInfo()->first(1)->withRateLimitKey('shared')->log('info first');
        $logger->atError()->first(1)->withRateLimitKey('shared')->log('error first');
        $logger->atInfo()->first(2)->withRateLimitKey('shared')->log('info different limit');

        self::assertSame(['info first', 'error first', 'info different limit'], $backend->messages());
    }

    public function testContextClonesShareRateLimitsAndReset(): void
    {
        $backend = new RecordingLogger();
        $base = new FluentLogger($backend);
        $child = $base->withContext(['request' => 1]);

        $base->atInfo()->first(1)->withRateLimitKey('clones')->log('base');
        $child->atInfo()->first(1)->withRateLimitKey('clones')->log('child blocked');
        $child->resetRateLimits();
        $base->atInfo()->first(1)->withRateLimitKey('clones')->log('base after reset');

        self::assertSame(['base', 'base after reset'], $backend->messages());
    }

    public function testResetClearsOccurrenceAndTimeLimits(): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend, clock: static fn (): float => 10.0);
        $entry = $logger->atInfo()->first(1)->every(3)->atMostEvery(100)->withRateLimitKey('reset');

        $entry->log('first');
        $entry->log('blocked');
        $logger->resetRateLimits();
        $entry->log('first again');

        self::assertSame(['first', 'first again'], $backend->messages());
    }

    public function testCapacityEvictsTheLeastRecentlyUsedKey(): void
    {
        $backend = new RecordingLogger();
        $logger = new FluentLogger($backend, rateLimitCapacity: 2);

        $logger->atInfo()->first(1)->withRateLimitKey('a')->log('a first');
        $logger->atInfo()->first(1)->withRateLimitKey('b')->log('b first');
        $logger->atInfo()->first(1)->withRateLimitKey('a')->log('a blocked and touched');
        $logger->atInfo()->first(1)->withRateLimitKey('c')->log('c first');
        $logger->atInfo()->first(1)->withRateLimitKey('a')->log('a still blocked');
        $logger->atInfo()->first(1)->withRateLimitKey('b')->log('b after eviction');

        self::assertSame(['a first', 'b first', 'c first', 'b after eviction'], $backend->messages());
    }

    #[DataProvider('invalidCapacities')]
    public function testRateLimitCapacityMustBePositive(int $capacity): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FluentLogger(new RecordingLogger(), rateLimitCapacity: $capacity);
    }

    public static function invalidCapacities(): iterable
    {
        yield [0];
        yield [-1];
    }

    #[DataProvider('invalidLimitSettings')]
    public function testInvalidLimitsAreRejected(string $method, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FluentLogger(new RecordingLogger()))->atInfo()->{$method}($value);
    }

    public static function invalidLimitSettings(): iterable
    {
        yield 'every zero' => ['every', 0];
        yield 'every negative' => ['every', -1];
        yield 'first negative' => ['first', -1];
        yield 'interval negative' => ['atMostEvery', -0.1];
        yield 'interval infinity' => ['atMostEvery', INF];
        yield 'interval NaN' => ['atMostEvery', NAN];
        yield 'empty key' => ['withRateLimitKey', ''];
    }
}
