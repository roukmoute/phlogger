<?php

declare(strict_types=1);

namespace Phlogger;

use Closure;
use Phlogger\Internal\RateLimiter;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Stringable;

final class FluentLogger extends AbstractLogger
{
    private readonly Level $minimumLevel;
    private readonly RateLimiter $rateLimiter;

    /** @var array<array-key, mixed> */
    private array $context = [];

    /**
     * @param (Closure(Level): bool)|null $filter
     * @param (Closure(): (int|float))|null $clock Monotonic time in seconds, useful for testing.
     */
    public function __construct(
        private readonly LoggerInterface $backend,
        Level|string $minimumLevel = Level::Debug,
        private readonly ?Closure $filter = null,
        int $rateLimitCapacity = 1024,
        ?Closure $clock = null,
    ) {
        $this->minimumLevel = self::normalizeLevel($minimumLevel);
        $this->rateLimiter = new RateLimiter($rateLimitCapacity, $clock);
    }

    public function at(Level|string $level): LogEntry
    {
        return new LogEntry($this, self::normalizeLevel($level), $this->context);
    }

    public function atDebug(): LogEntry
    {
        return $this->at(Level::Debug);
    }

    public function atInfo(): LogEntry
    {
        return $this->at(Level::Info);
    }

    public function atNotice(): LogEntry
    {
        return $this->at(Level::Notice);
    }

    public function atWarning(): LogEntry
    {
        return $this->at(Level::Warning);
    }

    public function atError(): LogEntry
    {
        return $this->at(Level::Error);
    }

    public function atCritical(): LogEntry
    {
        return $this->at(Level::Critical);
    }

    public function atAlert(): LogEntry
    {
        return $this->at(Level::Alert);
    }

    public function atEmergency(): LogEntry
    {
        return $this->at(Level::Emergency);
    }

    public function isEnabled(Level|string $level): bool
    {
        $level = self::normalizeLevel($level);

        return $level->severity() >= $this->minimumLevel->severity()
            && ($this->filter === null || ($this->filter)($level));
    }

    /** @param array<array-key, mixed> $context */
    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->context = array_replace($this->context, $context);

        return $clone;
    }

    /** PSR-3 logging preserves arbitrary context values and message templates. */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!$level instanceof Level && !is_string($level)) {
            throw new InvalidArgumentException('A log level must be a PSR-3 level name or a Level enum.');
        }

        $level = self::normalizeLevel($level);
        if ($this->isEnabled($level)) {
            $this->write($level, $message, array_replace($this->context, $context));
        }
    }

    /** Resets rate-limit state for this logger and all its contextual clones. */
    public function resetRateLimits(): void
    {
        $this->rateLimiter->reset();
    }

    /**
     * @internal
     * @param array<array-key, mixed> $context
     */
    public function write(Level $level, string|Stringable $message, array $context): void
    {
        $this->backend->log($level->value, $message, $context);
    }

    /** @internal */
    public function admit(string $key, ?int $every, ?int $first, ?float $seconds): bool
    {
        return $this->rateLimiter->allow($key, $every, $first, $seconds);
    }

    private static function normalizeLevel(Level|string $level): Level
    {
        return $level instanceof Level ? $level : Level::fromName($level);
    }
}
