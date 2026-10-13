<?php

declare(strict_types=1);

namespace Phlogger\Tests;

use Phlogger\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;

final class LevelTest extends TestCase
{
    #[DataProvider('levels')]
    public function testNamesMapToTheirPsrLevel(Level $level, string $name): void
    {
        self::assertSame($name, $level->value);
        self::assertSame($level, Level::fromName($name));
    }

    public static function levels(): iterable
    {
        yield 'debug' => [Level::Debug, 'debug'];
        yield 'info' => [Level::Info, 'info'];
        yield 'notice' => [Level::Notice, 'notice'];
        yield 'warning' => [Level::Warning, 'warning'];
        yield 'error' => [Level::Error, 'error'];
        yield 'critical' => [Level::Critical, 'critical'];
        yield 'alert' => [Level::Alert, 'alert'];
        yield 'emergency' => [Level::Emergency, 'emergency'];
    }

    public function testSeverityIncreasesFromDebugToEmergency(): void
    {
        $levels = [Level::Debug, Level::Info, Level::Notice, Level::Warning, Level::Error, Level::Critical, Level::Alert, Level::Emergency];

        for ($index = 1; $index < count($levels); ++$index) {
            self::assertGreaterThan($levels[$index - 1]->severity(), $levels[$index]->severity());
        }
    }

    #[DataProvider('invalidNames')]
    public function testUnknownNameThrowsThePsrException(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        Level::fromName($name);
    }

    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'custom' => ['trace'];
        yield 'numeric' => ['200'];
    }
}
