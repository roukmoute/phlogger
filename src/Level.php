<?php

declare(strict_types=1);

namespace Phlogger;

use Psr\Log\InvalidArgumentException;

enum Level: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    public static function fromName(string $name): self
    {
        return self::tryFrom($name) ?? throw new InvalidArgumentException('Unknown log level: '.$name);
    }

    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Notice => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
            self::Alert => 6,
            self::Emergency => 7,
        };
    }
}
