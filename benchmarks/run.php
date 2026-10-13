<?php

declare(strict_types=1);

use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\Level;
use Psr\Log\NullLogger;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Run this benchmark from the command line.');
}

$options = ['iterations' => 100_000, 'samples' => 7, 'warmup' => 10_000];
$json = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        echo "Usage: php benchmarks/run.php [--iterations=N] [--samples=N] [--warmup=N] [--json]\n";
        echo "Defaults: 100000 attempts per sample, 7 samples, 10000 warm-up attempts per case.\n";
        exit(0);
    }
    if ($argument === '--json') {
        $json = true;
        continue;
    }
    if (!preg_match('/^--(iterations|samples|warmup)=([0-9]+)$/D', $argument, $match)) {
        fwrite(STDERR, "Unknown argument: {$argument}. Use --help for usage.\n");
        exit(1);
    }
    $value = filter_var($match[2], FILTER_VALIDATE_INT);
    $minimum = match ($match[1]) { 'samples' => 3, 'warmup' => 0, default => 1 };
    $maximum = $match[1] === 'samples' ? 99 : 50_000_000;
    if ($value === false || $value < $minimum || $value > $maximum) {
        fwrite(STDERR, "--{$match[1]} must be an integer between {$minimum} and {$maximum}.\n");
        exit(1);
    }
    $options[$match[1]] = $value;
}

$autoload = dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Install dependencies with composer install before running benchmarks.\n");
    exit(1);
}
require $autoload;

/** Each operation owns fresh rate-limit state, created outside the measured loop. */
function operation(string $name): Closure
{
    $backend = new NullLogger();
    $enabled = new FluentLogger($backend);
    $disabled = new FluentLogger($backend, minimumLevel: Level::Info);
    $context = ['request_id' => 'benchmark'];
    $accepts = static fn (Level $level): bool => $level->severity() >= Level::Info->severity();
    $message = static fn (): string => 'Payload '.hash('sha256', str_repeat('a', 64));
    $value = static fn (): string => hash('sha256', str_repeat('b', 64));
    $entry = $enabled->atInfo();
    $chain = $enabled->atInfo()->withContext($context)->when(true);
    $lazyEntry = $enabled->atInfo()->with('value', LazyValue::of($value));
    $disabledLazyEntry = $disabled->atDebug()->with('value', LazyValue::of($value));
    $rateEntry = $enabled->atInfo()->every(100)->withRateLimitKey('benchmark');
    $clock = static fn (): float => 0.0;
    $timed = new FluentLogger($backend, clock: $clock);
    $timeEntry = $timed->atInfo()->atMostEvery(60)->withRateLimitKey('benchmark');

    return match ($name) {
        'psr-enabled' => static function (int $iterations) use ($backend, $context): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $backend->info('Message', $context);
            }
        },
        'bridge-enabled' => static function (int $iterations) use ($enabled, $context): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $enabled->info('Message', $context);
            }
        },
        'fluent-enabled' => static function (int $iterations) use ($enabled, $context): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $enabled->atInfo()->log('Message', $context);
            }
        },
        'fluent-reused' => static function (int $iterations) use ($entry, $context): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $entry->log('Message', $context);
            }
        },
        'fluent-chain-new' => static function (int $iterations) use ($enabled, $context): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $enabled->atInfo()->withContext($context)->when(true)->log('Message');
            }
        },
        'fluent-chain-reused' => static function (int $iterations) use ($chain): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $chain->log('Message');
            }
        },
        'psr-disabled-guarded' => static function (int $iterations) use ($backend, $context, $accepts): void {
            for ($i = 0; $i < $iterations; ++$i) {
                if ($accepts(Level::Debug)) {
                    $backend->debug('Message', $context);
                }
            }
        },
        'bridge-disabled' => static function (int $iterations) use ($disabled, $context): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $disabled->debug('Message', $context);
            }
        },
        'fluent-disabled' => static function (int $iterations) use ($disabled, $context): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $disabled->atDebug()->log('Message', $context);
            }
        },
        'fluent-disabled-chain' => static function (int $iterations) use ($disabled, $context): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $disabled->atDebug()->withContext($context)->when(true)->log('Message');
            }
        },
        'psr-lazy-enabled' => static function (int $iterations) use ($backend, $message, $value): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $backend->info($message(), ['value' => $value()]);
            }
        },
        'fluent-lazy-new' => static function (int $iterations) use ($enabled, $message, $value): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $enabled->atInfo()->with('value', LazyValue::of($value))->log($message);
            }
        },
        'fluent-lazy-reused' => static function (int $iterations) use ($lazyEntry, $message): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $lazyEntry->log($message);
            }
        },
        'psr-lazy-disabled-guarded' => static function (int $iterations) use ($backend, $message, $value, $accepts): void {
            for ($i = 0; $i < $iterations; ++$i) {
                if ($accepts(Level::Debug)) {
                    $backend->debug($message(), ['value' => $value()]);
                }
            }
        },
        'fluent-lazy-disabled-new' => static function (int $iterations) use ($disabled, $message, $value): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $disabled->atDebug()->with('value', LazyValue::of($value))->log($message);
            }
        },
        'fluent-lazy-disabled-reused' => static function (int $iterations) use ($disabledLazyEntry, $message): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $disabledLazyEntry->log($message);
            }
        },
        'psr-every-100-manual' => static function (int $iterations) use ($backend): void {
            for ($i = 0; $i < $iterations; ++$i) {
                if ($i % 100 === 0) {
                    $backend->info('Message');
                }
            }
        },
        'fluent-every-100-new-key' => static function (int $iterations) use ($enabled): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $enabled->atInfo()->every(100)->withRateLimitKey('benchmark')->log('Message');
            }
        },
        'fluent-every-100-reused-key' => static function (int $iterations) use ($rateEntry): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $rateEntry->log('Message');
            }
        },
        'fluent-every-100-call-site' => static function (int $iterations) use ($enabled): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $enabled->atInfo()->every(100)->log('Message');
            }
        },
        'psr-time-manual' => static function (int $iterations) use ($backend, $clock): void {
            $last = null;
            for ($i = 0; $i < $iterations; ++$i) {
                $now = $clock();
                if ($last === null || $now - $last >= 60) {
                    $last = $now;
                    $backend->info('Message');
                }
            }
        },
        'fluent-time-reused-key' => static function (int $iterations) use ($timeEntry): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $timeEntry->log('Message');
            }
        },
        default => throw new InvalidArgumentException('Unknown benchmark case: '.$name),
    };
}

/** @param non-empty-list<int|float> $values */
function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 0
        ? ($values[$middle - 1] + $values[$middle]) / 2
        : (float) $values[$middle];
}

function setting(string $name): string
{
    $value = ini_get($name);

    return $value === false ? 'unavailable' : $value;
}

$names = [
    'psr-enabled', 'bridge-enabled', 'fluent-enabled', 'fluent-reused',
    'fluent-chain-new', 'fluent-chain-reused', 'psr-disabled-guarded',
    'bridge-disabled', 'fluent-disabled', 'fluent-disabled-chain',
    'psr-lazy-enabled', 'fluent-lazy-new', 'fluent-lazy-reused',
    'psr-lazy-disabled-guarded', 'fluent-lazy-disabled-new', 'fluent-lazy-disabled-reused',
    'psr-every-100-manual', 'fluent-every-100-new-key',
    'fluent-every-100-reused-key', 'fluent-every-100-call-site',
    'psr-time-manual', 'fluent-time-reused-key',
];
$measurements = [];
foreach ($names as $name) {
    operation($name)($options['warmup']);
    $measurements[$name] = [];
}
for ($sample = 0; $sample < $options['samples']; ++$sample) {
    // Rotate the order so a case is not always first or last in a sample.
    $ordered = array_merge(array_slice($names, $sample % count($names)), array_slice($names, 0, $sample % count($names)));
    foreach ($ordered as $name) {
        $run = operation($name);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baselineBytes = memory_get_usage(false);
        $start = hrtime(true);
        $run($options['iterations']);
        $elapsed = hrtime(true) - $start;
        $peakBytes = max(0, memory_get_peak_usage(false) - $baselineBytes);
        $measurements[$name][] = ['elapsed_ns' => $elapsed, 'peak_extra_bytes' => $peakBytes];
        unset($run);
    }
}

$results = [];
foreach ($measurements as $name => $samples) {
    $perAttempt = array_map(static fn (array $sample): float => $sample['elapsed_ns'] / $options['iterations'], $samples);
    $admitted = str_contains($name, 'disabled') ? 0 : $options['iterations'];
    if (str_contains($name, 'every-100')) {
        $admitted = intdiv($options['iterations'] - 1, 100) + 1;
    } elseif (str_contains($name, 'time-')) {
        $admitted = 1;
    }
    $results[] = [
        'case' => $name,
        'median_ns_per_attempt' => median($perAttempt),
        'min_ns_per_attempt' => min($perAttempt),
        'max_ns_per_attempt' => max($perAttempt),
        'median_peak_extra_bytes' => median(array_column($samples, 'peak_extra_bytes')),
        'expected_admitted_per_sample' => $admitted,
        'samples' => $samples,
    ];
}
$report = [
    'environment' => [
        'php' => PHP_VERSION,
        'os' => PHP_OS_FAMILY,
        'architecture' => php_uname('m'),
        'opcache_cli' => setting('opcache.enable_cli'),
        'jit' => setting('opcache.jit'),
        'jit_buffer_size' => setting('opcache.jit_buffer_size'),
    ],
    'options' => $options,
    'backend' => NullLogger::class,
    'results' => $results,
];
if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit(0);
}
printf("PHP %s | %s %s | opcache.cli=%s | jit=%s | jit_buffer=%s\n", ...array_values($report['environment']));
printf("%d attempts/sample | %d samples | %d warm-up attempts/case | NullLogger\n\n", $options['iterations'], $options['samples'], $options['warmup']);
printf("%-32s %12s %12s %12s %10s %10s\n", 'Case', 'Median ns', 'Min ns', 'Max ns', 'Peak KiB', 'Admitted*');
foreach ($results as $result) {
    printf("%-32s %12.1f %12.1f %12.1f %10.2f %10d\n", $result['case'], $result['median_ns_per_attempt'], $result['min_ns_per_attempt'], $result['max_ns_per_attempt'], $result['median_peak_extra_bytes'] / 1024, $result['expected_admitted_per_sample']);
}
echo "\nTimes are per attempted statement, including the loop; setup and warm-up are excluded.\n";
echo "Peak KiB is temporary PHP memory above each sample's prepared baseline, not allocation count.\n";
echo "*Admitted is the expected count; NullLogger discards records. Read benchmarks/README.md for comparison limits.\n";
