<?php

declare(strict_types=1);

namespace Phlogger\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
    public array $records = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn (array $record): string => (string) $record['message'], $this->records);
    }
}
