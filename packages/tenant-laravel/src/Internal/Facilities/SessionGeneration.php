<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Closure;
use Tenant\Runtime\SafeTenantRuntime;
use Tenant\Exceptions\StaleHandle;

/** @internal Invalidate handlers created during transitions before runtime publication. */
final class SessionGeneration
{
    private int $revision = 0;
    /** @param Closure(): SafeTenantRuntime $owner */
    public function __construct(private readonly Closure $owner) {}
    public function advance(): void { $this->revision++; }
    public function checker(): Closure
    {
        $revision = $this->revision;
        $guard = null;
        return function () use ($revision, &$guard): void {
            // Poison/lane/transition have priority over stale handler diagnostics.
            ($this->owner)()->current();
            if ($revision !== $this->revision) throw new StaleHandle();
            $guard ??= ($this->owner)()->guard();
            $guard->check();
        };
    }
}
