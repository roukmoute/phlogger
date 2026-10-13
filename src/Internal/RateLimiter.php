<?php

declare(strict_types=1);

namespace Phlogger\Internal;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/** @internal Process-local state, shared by contextual clones of a logger. */
final class RateLimiter
{
    /** @var array<string, array{attempts: int, emitted: int, last: float|null}> */
    private array $states = [];

    /** @var Closure(): (int|float) */
    private readonly Closure $clock;

    /** @param (Closure(): (int|float))|null $clock Monotonic time in seconds. */
    public function __construct(private readonly int $capacity, ?Closure $clock = null)
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Rate-limit capacity must be positive.');
        }

        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    public function allow(string $key, ?int $every, ?int $first, ?float $seconds): bool
    {
        $state = $this->states[$key] ?? ['attempts' => 0, 'emitted' => 0, 'last' => null];

        // Refresh insertion order on every access to implement bounded LRU storage.
        unset($this->states[$key]);
        if (count($this->states) >= $this->capacity) {
            $oldest = array_key_first($this->states);
            if ($oldest !== null) {
                unset($this->states[$oldest]);
            }
        }

        if ($state['attempts'] < ($every ?? 1)) {
            ++$state['attempts'];
        }

        $now = null;
        if ($seconds !== null) {
            $now = (float) ($this->clock)();
            if (!is_finite($now)) {
                throw new UnexpectedValueException('The rate-limit clock must return finite seconds.');
            }
        }

        $allowed = ($first === null || $state['emitted'] < $first)
            && ($every === null || $state['emitted'] === 0 || $state['attempts'] >= $every)
            && ($seconds === null || $state['last'] === null || $now - $state['last'] >= $seconds);

        if ($allowed) {
            $state['attempts'] = 0;
            // Only first(n) needs a count; otherwise a single bit of history is enough.
            if ($state['emitted'] < ($first ?? 1)) {
                ++$state['emitted'];
            }
            $state['last'] = $now;
        }

        $this->states[$key] = $state;

        return $allowed;
    }

    public function reset(): void
    {
        $this->states = [];
    }
}
