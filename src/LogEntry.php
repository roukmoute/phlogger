<?php

declare(strict_types=1);

namespace Phlogger;

use Closure;
use InvalidArgumentException;
use SplObjectStorage;
use Stringable;
use Throwable;
use UnexpectedValueException;

/** Immutable, reusable configuration for a fluent log statement. */
final class LogEntry
{
    /** @var list<bool|Closure(): bool> */
    private array $conditions = [];
    private ?int $every = null;
    private ?int $first = null;
    private ?float $seconds = null;
    private ?string $rateLimitKey = null;

    /**
     * @internal
     * @param array<array-key, mixed> $context
     */
    public function __construct(
        private readonly FluentLogger $logger,
        private readonly Level $level,
        private array $context = [],
    ) {
    }

    public function with(string $key, mixed $value): self
    {
        return $this->withContext([$key => $value]);
    }

    /** @param array<array-key, mixed> $context */
    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->context = array_replace($this->context, $context);

        return $clone;
    }

    public function withCause(?Throwable $cause): self
    {
        return $this->with('exception', $cause);
    }

    /** @param bool|Closure(): bool $condition */
    public function when(bool|Closure $condition): self
    {
        $clone = clone $this;
        $clone->conditions[] = $condition;

        return $clone;
    }

    public function every(int $count): self
    {
        if ($count < 1) {
            throw new InvalidArgumentException('every() requires a positive count.');
        }
        $clone = clone $this;
        $clone->every = $count;

        return $clone;
    }

    public function first(int $count): self
    {
        if ($count < 0) {
            throw new InvalidArgumentException('first() requires a non-negative count.');
        }
        $clone = clone $this;
        $clone->first = $count;

        return $clone;
    }

    public function atMostEvery(float $seconds): self
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('atMostEvery() requires finite, non-negative seconds.');
        }
        $clone = clone $this;
        $clone->seconds = $seconds === 0.0 ? null : $seconds;

        return $clone;
    }

    public function withRateLimitKey(string $key): self
    {
        if ($key === '') {
            throw new InvalidArgumentException('A rate-limit key must not be empty.');
        }
        $clone = clone $this;
        $clone->rateLimitKey = $key;

        return $clone;
    }

    /** Checks level filtering only; does not evaluate conditions or consume quotas. */
    public function isEnabled(): bool
    {
        return $this->logger->isEnabled($this->level);
    }

    /**
     * @param string|Stringable|Closure(): (string|Stringable) $message
     * @param array<array-key, mixed> $context
     */
    public function log(string|Stringable|Closure $message, array $context = []): void
    {
        if (!$this->shouldLog()) {
            return;
        }

        if ($message instanceof Closure) {
            $message = self::resolveMessage($message);
        }

        $resolved = new SplObjectStorage();
        $this->logger->write($this->level, $message, $this->resolveContext($context, $resolved));
    }

    /** Formats a printf message only after the statement passes all gates. */
    public function logf(string $format, mixed ...$arguments): void
    {
        if (!$this->shouldLog()) {
            return;
        }

        $resolved = new SplObjectStorage();
        $formattedArguments = [];
        foreach ($arguments as $value) {
            $value = self::resolveValue($value, $resolved);
            if ($value instanceof Stringable) {
                $value = (string) $value;
            }
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException('logf() arguments must resolve to scalar, null or Stringable values.');
            }
            $formattedArguments[] = $value;
        }

        $this->logger->write($this->level, vsprintf($format, $formattedArguments), $this->resolveContext([], $resolved));
    }

    private function shouldLog(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        foreach ($this->conditions as $condition) {
            if (!($condition instanceof Closure ? $condition() : $condition)) {
                return false;
            }
        }
        if ($this->every === null && $this->first === null && $this->seconds === null) {
            return true;
        }

        if ($this->rateLimitKey !== null) {
            $location = 'key:'.$this->rateLimitKey;
        } else {
            // Frame 1 is the terminal log()/logf() call, including its caller's file and line.
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
            $location = 'site:'.($caller['file'] ?? '').':'.($caller['line'] ?? 0);
        }
        $key = hash('sha256', serialize([$location, $this->level->value, $this->every, $this->first, $this->seconds]));

        return $this->logger->admit($key, $this->every, $this->first, $this->seconds);
    }

    /**
     * @param array<array-key, mixed> $context
     * @param SplObjectStorage<object, mixed> $resolved
     * @return array<array-key, mixed>
     */
    private function resolveContext(array $context, SplObjectStorage $resolved): array
    {
        $context = array_replace($this->context, $context);
        foreach ($context as $key => $value) {
            $context[$key] = self::resolveValue($value, $resolved);
        }

        return $context;
    }

    /** @param SplObjectStorage<object, mixed> $resolved */
    private static function resolveValue(mixed $value, SplObjectStorage $resolved): mixed
    {
        if (!$value instanceof LazyValue) {
            return $value;
        }
        if (!$resolved->offsetExists($value)) {
            $resolved[$value] = $value->resolve();
        }

        return $resolved[$value];
    }

    /** @param Closure(): mixed $supplier */
    private static function resolveMessage(Closure $supplier): string|Stringable
    {
        $message = $supplier();
        if (!is_string($message) && !$message instanceof Stringable) {
            throw new UnexpectedValueException('A lazy message must return a string or Stringable.');
        }

        return $message;
    }
}
