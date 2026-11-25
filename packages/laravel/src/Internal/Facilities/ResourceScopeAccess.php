<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Closure;
use Packman\Attributes\Internal;
use Tenant\Contracts\Context\FacetAccess;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Exceptions\Context\ScopeRequired;
use Tenant\Exceptions\Context\UnsupportedProfile;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Contracts\Facilities\ResourceScope;
use Tenant\Laravel\Data\ResourceDescriptor;

/** Selects exactly the facet advertised by the active Laravel profile. */
#[Internal]
final class ResourceScopeAccess
{
    public static function fromPhase(PhaseContext $context): ResourceScope
    {
        return self::resolve($context->candidate(), $context->require(...));
    }

    public static function fromResolved(ResolvedIsolation $resolved): ResourceScope
    {
        return self::resolve($resolved->context, $resolved->facets->require(...));
    }

    /** @param Closure(CapabilityId): FacetAccess $require */
    private static function resolve(ContextState $state, Closure $require): ResourceScope
    {
        $profile = $state->context?->profile->id ?? throw new ScopeRequired;
        $capability = match ($profile) {
            'laravel-pg-database' => 'tenant:database',
            'laravel-scoped', 'single' => 'tenant:resource',
            default => throw new UnsupportedProfile,
        };
        $scope = $require(new CapabilityId($capability));
        if (! $scope instanceof ResourceScope || ($profile === 'laravel-pg-database' && ! $scope instanceof ResourceDescriptor)) {
            throw new TenantFailure('unsupported_capability');
        }
        ResourceScopeValidation::assertValid($scope->prefix, $scope->durableRoot, $scope->ephemeralRoot, $scope->sessionRoot);

        return $scope;
    }
}
