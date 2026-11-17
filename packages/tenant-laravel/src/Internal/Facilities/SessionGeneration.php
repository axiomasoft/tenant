<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Closure;
use Packman\Attributes\Internal;
use Tenant\Exceptions\Runtime\StaleHandle;
use Tenant\Runtime\SafeTenantRuntime;

/** @internal Invalidate handlers created during transitions before runtime publication. */
#[Internal]
final class SessionGeneration
{
    private int $revision = 0;

    private ?string $scope = null;

    /** @param Closure(): SafeTenantRuntime $owner */
    public function __construct(private readonly Closure $owner) {}

    public function advance(): void
    {
        $this->revision++;
    }

    public function scope(): ?string
    {
        return $this->scope;
    }

    public function select(?string $scope): void
    {
        $this->scope = $scope;
    }

    /** @return Closure(): void */
    public function checker(): Closure
    {
        $revision = $this->revision;
        $guard = null;

        return function () use ($revision, &$guard): void {
            // Poison/lane/transition have priority over stale handler diagnostics.
            ($this->owner)()->current();
            if ($revision !== $this->revision) {
                throw new StaleHandle;
            }
            $guard ??= ($this->owner)()->guard();
            $guard->check();
        };
    }
}
