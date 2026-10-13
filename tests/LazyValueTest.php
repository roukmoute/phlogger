<?php

declare(strict_types=1);

namespace Phlogger\Tests;

use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LazyValueTest extends TestCase
{
    public function testStandaloneResolutionDoesNotCacheTheCallback(): void
    {
        $calls = 0;
        $lazy = LazyValue::of(static function () use (&$calls): int {
            return ++$calls;
        });

        self::assertSame(1, $lazy->resolve());
        self::assertSame(2, $lazy->resolve());
    }

    public function testValuesAreResolvedOncePerRecordAndFreshlyForTheNextRecord(): void
    {
        $backend = new RecordingLogger();
        $calls = 0;
        $lazy = LazyValue::of(static function () use (&$calls): int {
            return ++$calls;
        });
        $entry = (new FluentLogger($backend))->atInfo()->withContext(['first' => $lazy, 'second' => $lazy]);

        self::assertSame(0, $calls);
        $entry->log('first record', ['third' => $lazy]);
        $entry->log('second record', ['third' => $lazy]);

        self::assertSame(2, $calls);
        self::assertSame(['first' => 1, 'second' => 1, 'third' => 1], $backend->records[0]['context']);
        self::assertSame(['first' => 2, 'second' => 2, 'third' => 2], $backend->records[1]['context']);
    }

    public function testPrintfAndContextShareOneEvaluationPerRecord(): void
    {
        $backend = new RecordingLogger();
        $calls = 0;
        $lazy = LazyValue::of(static function () use (&$calls): string {
            ++$calls;
            return 'same value';
        });

        (new FluentLogger($backend))->atInfo()->with('value', $lazy)->logf('%s / %s', $lazy, $lazy);

        self::assertSame(1, $calls);
        self::assertSame(['same value / same value'], $backend->messages());
        self::assertSame(['value' => 'same value'], $backend->records[0]['context']);
    }

    public function testOnlyTopLevelLazyValuesAreResolved(): void
    {
        $backend = new RecordingLogger();
        $lazy = LazyValue::of(static fn (): int => 42);
        $closure = static fn (): string => 'ordinary closure';
        $nested = ['nested' => $lazy];

        (new FluentLogger($backend))->atInfo()->withContext(['top' => $lazy, 'nested' => $nested, 'closure' => $closure])->log('context');

        self::assertSame(42, $backend->records[0]['context']['top']);
        self::assertSame($nested, $backend->records[0]['context']['nested']);
        self::assertSame($closure, $backend->records[0]['context']['closure']);
    }

    public function testOverriddenValuesAreNeverEvaluated(): void
    {
        $backend = new RecordingLogger();
        $calls = 0;
        $lazy = LazyValue::of(static function () use (&$calls): string {
            ++$calls;
            return 'overridden';
        });

        (new FluentLogger($backend))->withContext(['key' => $lazy])->atInfo()->with('key', $lazy)->log('record', ['key' => 'terminal']);

        self::assertSame(0, $calls);
        self::assertSame(['key' => 'terminal'], $backend->records[0]['context']);
    }

    public function testLazyValuesMayReturnNullWithoutBeingEvaluatedTwice(): void
    {
        $backend = new RecordingLogger();
        $calls = 0;
        $lazy = LazyValue::of(static function () use (&$calls): mixed {
            ++$calls;
            return null;
        });

        (new FluentLogger($backend))->atInfo()->withContext(['first' => $lazy, 'second' => $lazy])->log('null');

        self::assertSame(1, $calls);
        self::assertSame(['first' => null, 'second' => null], $backend->records[0]['context']);
    }

    public function testLazyExceptionsPropagateWithoutCallingTheBackend(): void
    {
        $backend = new RecordingLogger();

        try {
            (new FluentLogger($backend))->atInfo()->with('value', LazyValue::of(static function (): never {
                throw new RuntimeException('lazy failed');
            }))->log('unused');
            self::fail('The lazy exception should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('lazy failed', $exception->getMessage());
            self::assertSame([], $backend->records);
        }
    }
}
