<?php

declare(strict_types=1);

namespace Tenant\Internal\Runtime;

use Closure;

/** @internal Adapter hook. Every operation calls check; close always releases its lease. */
final class GenerationGuard
{
    /** @param Closure(): void $check @param Closure(int): void $lease */
    public function __construct(private readonly Closure $check, private readonly Closure $lease) {}
    public function check(): void { ($this->check)(); }
    /** @return Closure(): void Idempotent release, including after poison. */
    public function acquire(): Closure
    {
        $this->check();
        ($this->lease)(1);
        $closed = false;
        return function () use (&$closed): void {
            if (!$closed) { $closed = true; ($this->lease)(-1); }
        };
    }
}
