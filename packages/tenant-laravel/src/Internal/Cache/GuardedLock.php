<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Cache;

use Illuminate\Contracts\Cache\Lock;
use Tenant\Internal\Runtime\GenerationGuard;

/** @internal No unguarded callback or release path. */
final readonly class GuardedLock implements Lock
{
    public function __construct(private Lock $lock, private GenerationGuard $guard) {}
    public function get($callback = null)
    {
        $this->guard->check();
        return $this->lock->get($callback === null ? null : function () use ($callback) { $this->guard->check(); return $callback(); });
    }
    public function block($seconds, $callback = null)
    {
        $this->guard->check();
        return $this->lock->block($seconds, $callback === null ? null : function () use ($callback) { $this->guard->check(); return $callback(); });
    }
    public function release() { $this->guard->check(); return $this->lock->release(); }
    public function owner() { $this->guard->check(); return $this->lock->owner(); }
    public function forceRelease() { $this->guard->check(); $this->lock->forceRelease(); }
}
