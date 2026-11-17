<?php

declare(strict_types=1);

use Tenant\Contracts\Context\FacetAccess;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Exceptions\Context\ScopeRequired;
use Tenant\Exceptions\Context\UnsupportedProfile;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Contracts\Facilities\ResourceScope;
use Tenant\Laravel\Data\ResourceDescriptor;
use Tenant\Laravel\Data\ScopeDescriptor;
use Tenant\Laravel\Internal\Facilities\ResourceScopeAccess;

/** @param array<string, FacetAccess> $facets */
function scopeDescriptorResolution(string $profile, array $facets): ResolvedIsolation
{
    return new ResolvedIsolation(ContextState::active(new IsolationContext(
        $profile === 'single' ? 'single' : 'tenant',
        new IsolationIdentity('host', 'tenant-a'),
        new ProfileRef($profile, 1),
        new BindingExpectation('resource:a', 'incarnation:1', 'route:1'),
    )), new FacetBindings($facets));
}

it('supplies the shared facility scope without requiring a database route', function (): void {
    $scope = new ScopeDescriptor('tenant:a:', '/private/a/durable', '/private/a/temporary', '/private/a/sessions');
    expect($scope)->toBeInstanceOf(ResourceScope::class)
        ->toBeInstanceOf(FacetAccess::class)
        ->and($scope->prefix)->toBe('tenant:a:')
        ->and($scope->durableRoot)->toBe('/private/a/durable')
        ->and($scope->ephemeralRoot)->toBe('/private/a/temporary')
        ->and($scope->sessionRoot)->toBe('/private/a/sessions')
        ->and(property_exists($scope, 'route'))->toBeFalse();

    $legacy = new ResourceDescriptor(['driver' => 'pgsql'], 'tenant:a:', '/private/a/durable', '/private/a/temporary', '/private/a/sessions');
    expect($legacy)->toBeInstanceOf(ResourceScope::class)
        ->toBeInstanceOf(FacetAccess::class)
        ->and($legacy->route)->toBe(['driver' => 'pgsql'])
        ->and($legacy->options)->toBe([]);
});

it('rejects unsafe roots for every scoped facility', function (string $property, string $invalid): void {
    $values = ['prefix' => 'tenant:a:', 'durableRoot' => '/private/a/durable', 'ephemeralRoot' => '/private/a/temporary', 'sessionRoot' => '/private/a/sessions'];
    $values[$property] = $invalid;
    expect(fn (): ScopeDescriptor => new ScopeDescriptor(...$values))->toThrow(InvalidArgumentException::class, 'facility_configuration');
})->with([
    ['prefix', ''],
    ['durableRoot', 'relative/storage'],
    ['ephemeralRoot', 'relative/storage'],
    ['sessionRoot', 'relative/storage'],
    ['durableRoot', '/private/../outside'],
    ['ephemeralRoot', '/private/../outside'],
    ['sessionRoot', '/private/../outside'],
    ['durableRoot', "/private/invalid\0path"],
    ['ephemeralRoot', "/private/invalid\0path"],
    ['sessionRoot', "/private/invalid\0path"],
]);

it('preserves PostgreSQL descriptor routing restrictions', function (): void {
    expect(fn (): ResourceDescriptor => new ResourceDescriptor(['driver' => 'sqlite'], 'a:', '/a', '/b', '/c'))
        ->toThrow(InvalidArgumentException::class, 'facility_configuration')
        ->and(fn (): ResourceDescriptor => new ResourceDescriptor(['driver' => 'pgsql'], 'a:', '/a', '/b', '/c', ['database' => 'another']))
        ->toThrow(InvalidArgumentException::class, 'routing keys in options');
});

it('reads scoped and single facets through the profile-specific resource capability', function (string $profile): void {
    $scope = new ScopeDescriptor('a:', '/a', '/b', '/c');
    $resolved = scopeDescriptorResolution($profile, ['tenant:resource' => $scope]);
    expect(ResourceScopeAccess::fromResolved($resolved))->toBe($scope);
    $phase = new class($resolved) implements PhaseContext
    {
        public function __construct(private readonly ResolvedIsolation $resolved) {}

        public function candidate(): ContextState
        {
            return $this->resolved->context;
        }

        public function require(CapabilityId $id): FacetAccess
        {
            return $this->resolved->facets->require($id);
        }
    };
    expect(ResourceScopeAccess::fromPhase($phase))->toBe($scope);
})->with(['laravel-scoped', 'single']);

it('keeps physical PostgreSQL scope requirements distinct from scoped resource metadata', function (): void {
    $scope = new ScopeDescriptor('a:', '/a', '/b', '/c');
    $physical = new ResourceDescriptor(['driver' => 'pgsql'], 'a:', '/a', '/b', '/c');

    expect(ResourceScopeAccess::fromResolved(scopeDescriptorResolution('laravel-pg-database', ['tenant:database' => $physical])))->toBe($physical)
        ->and(ResourceScopeAccess::fromResolved(scopeDescriptorResolution('laravel-scoped', ['tenant:resource' => $physical])))->toBe($physical)
        ->and(fn (): ResourceScope => ResourceScopeAccess::fromResolved(scopeDescriptorResolution('laravel-pg-database', ['tenant:database' => $scope])))
        ->toThrow(TenantFailure::class, 'unsupported_capability')
        ->and(fn (): ResourceScope => ResourceScopeAccess::fromResolved(scopeDescriptorResolution('laravel-scoped', ['tenant:database' => $physical])))
        ->toThrow(OutOfBoundsException::class)
        ->and(fn (): ResourceScope => ResourceScopeAccess::fromResolved(scopeDescriptorResolution('unregistered', ['tenant:resource' => $scope])))
        ->toThrow(UnsupportedProfile::class);
});

it('requires an active resource before exposing scope metadata', function (): void {
    expect(fn (): ResourceScope => ResourceScopeAccess::fromResolved(new ResolvedIsolation(ContextState::central(), new FacetBindings([]))))
        ->toThrow(ScopeRequired::class);
});
