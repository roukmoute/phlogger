<?php

declare(strict_types=1);

namespace Phlogger;

use Closure;

/** An explicit deferred value; ordinary context closures remain untouched. */
final readonly class LazyValue
{
    /** @param Closure(): mixed $supplier */
    private function __construct(private Closure $supplier)
    {
    }

    /** @param Closure(): mixed $supplier */
    public static function of(Closure $supplier): self
    {
        return new self($supplier);
    }

    public function resolve(): mixed
    {
        return ($this->supplier)();
    }
}
