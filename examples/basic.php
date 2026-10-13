<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\Level;
use Psr\Log\AbstractLogger;

// Supply any PSR-3 backend; this example writes interpolated messages to stdout.
$backend = new class extends AbstractLogger {
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }
        echo '['.$level.'] '.strtr((string) $message, $replacements).PHP_EOL;
    }
};

$logger = new FluentLogger($backend, minimumLevel: Level::Info);
$logger->atInfo()->with('name', 'Phlogger')->log('Hello from {name}');
$logger->atWarning()->withCause(new RuntimeException('Example'))->log('An example warning');
$logger->atInfo()->logf('Processed %d records', LazyValue::of(static fn (): int => 42));

for ($i = 1; $i <= 7; ++$i) {
    $logger->atInfo()->every(3)->with('attempt', $i)->log('Progress at attempt {attempt}');
}

$logger->atDebug()->log(static function (): string {
    throw new LogicException('Disabled debug logging must not evaluate this callback.');
});
