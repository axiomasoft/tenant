<?php

declare(strict_types=1);

use Tenant\Composition\CompiledComposition;
use Tenant\Composition\CompositionBuilder;
use Tenant\Contracts\Runtime\Facility;
use Tenant\Contracts\Runtime\FrameState;
use Tenant\Contracts\Runtime\ParticipantFactory;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Contracts\Runtime\RestoreContext;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Composition\ParticipantDeclaration;
use Tenant\Data\Context\ContextReference;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\Selection;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Exceptions\TenantFailure;

function declaration(string $id, array $provides = [], array $requires = [], array $slots = [], ?string $replaces = null): ParticipantDeclaration
{
    $factory = new class implements ParticipantFactory
    {
        public function create(): Facility
        {
            return new class implements Facility
            {
                public function prepare(PhaseContext $context, FrameState $state): void {}

                public function activate(PhaseContext $context, FrameState $state): void {}

                public function restore(RestoreContext $context, FrameState $state): void {}

                public function verify(RestoreContext $context, FrameState $state): void {}
            };
        }
    };

    return new ParticipantDeclaration($id, 1, 'resource', $provides, $requires, $slots, [], [], $replaces, $factory);
}

it('preserves identity bytes and rejects invalid or non-normalized input', function (): void {
    expect((new IsolationIdentity('acme', 'A'))->id)->toBe('A');
    expect(fn (): IsolationIdentity => new IsolationIdentity('acme', "e\u{0301}"))->toThrow(InvalidArgumentException::class);
    expect(fn (): IsolationIdentity => new IsolationIdentity('ACME', 'A'))->toThrow(InvalidArgumentException::class);
    expect(fn (): IsolationIdentity => new IsolationIdentity('acme', "a\0b"))->toThrow(InvalidArgumentException::class);
});

it('distinguishes missing central and active single', function (): void {
    $identity = new IsolationIdentity('acme', 'A');
    $context = new IsolationContext('single', $identity, new ProfileRef('single', 1), new BindingExpectation('A', 'i1', 'r1'));
    expect(ContextState::missing()->kind)->toBe('missing')
        ->and(ContextState::central()->kind)->toBe('central')
        ->and(ContextState::active($context)->context->identity)->toBe($identity)
        ->and(Selection::single($identity)->expectedProfile->id)->toBe('single');
    new Purpose('acme:job');
    expect(fn (): BindingExpectation => new BindingExpectation('postgres://host/db', 'i1', 'r1'))->toThrow(InvalidArgumentException::class);
    expect(fn (): ProfileRef => new ProfileRef('single', 2147483648))->toThrow(InvalidArgumentException::class);
});

it('compiles in dependency order independent of registration order and freezes', function (): void {
    $cap = new CapabilityId('acme:feature');
    $a = declaration('acme:a', [$cap]);
    $b = declaration('acme:b', [], [$cap]);
    $first = new CompositionBuilder;
    $first->register($b);
    $first->register($a);
    $second = new CompositionBuilder;
    $second->register($a);
    $second->register($b);
    expect($first->compile()->orderedIds)->toBe(['acme:a', 'acme:b'])
        ->and($second->compile()->orderedIds)->toBe(['acme:a', 'acme:b']);
    expect(fn () => $first->register($a))->toThrow(TenantFailure::class, 'composition_frozen');
});

it('uses only an explicit compatible replacement and rejects duplicate slots', function (): void {
    $cap = new CapabilityId('acme:feature');
    $old = declaration('acme:old', [$cap], [], ['acme:slot']);
    $replacement = declaration('acme:new', [$cap], [], ['acme:slot'], 'acme:old');
    $builder = new CompositionBuilder;
    $builder->register($old);
    $builder->register($replacement);
    expect($builder->compile()->orderedIds)->toBe(['acme:new']);
    $builder = new CompositionBuilder;
    $builder->register($old);
    $builder->register(declaration('acme:other', [], [], ['acme:slot']));
    expect(fn (): CompiledComposition => $builder->compile())->toThrow(TenantFailure::class, 'exclusive_slot_conflict');
});

it('rejects graph ambiguity, missing requirements, incompatible replacements and cycles', function (): void {
    $cap = new CapabilityId('acme:feature');
    $missing = new CompositionBuilder;
    $missing->register(declaration('acme:a', [], [$cap]));
    expect(fn (): CompiledComposition => $missing->compile())->toThrow(TenantFailure::class, 'unsupported_capability');
    $ambiguous = new CompositionBuilder;
    $ambiguous->register(declaration('acme:a', [$cap]));
    $ambiguous->register(declaration('acme:b', [$cap]));
    $ambiguous->register(declaration('acme:c', [], [$cap]));
    expect(fn (): CompiledComposition => $ambiguous->compile())->toThrow(TenantFailure::class, 'ambiguous_capability');
    $incompatible = new CompositionBuilder;
    $incompatible->register(declaration('acme:a', [$cap], [], ['acme:slot']));
    $incompatible->register(declaration('acme:b', [], [], ['acme:slot'], 'acme:a'));
    expect(fn (): CompiledComposition => $incompatible->compile())->toThrow(TenantFailure::class, 'replacement_incompatible');
    $a = new CapabilityId('acme:a');
    $b = new CapabilityId('acme:b');
    $cyclic = new CompositionBuilder;
    $cyclic->register(declaration('acme:a', [$a], [$b]));
    $cyclic->register(declaration('acme:b', [$b], [$a]));
    expect(fn (): CompiledComposition => $cyclic->compile())->toThrow(TenantFailure::class, 'dependency_cycle');
});

it('rejects duplicate slots and incompatible service implementations', function (): void {
    $binding = ['id' => 'acme:wrong', 'slot' => 'tenant:resolver/default', 'spiVersion' => 1, 'service' => new stdClass, 'capabilities' => []];
    expect(fn (): CompiledComposition => new CompositionBuilder([], [$binding])->compile())->toThrow(TenantFailure::class, 'replacement_incompatible');
});

it('validates reference shape and rejects mutable or central tenant data', function (): void {
    $central = ['schema' => 'tenant.context/1', 'mode' => 'central', 'profileId' => 'central', 'profileVersion' => 1, 'extensions' => []];
    expect((new ContextReference($central))->envelope)->toBe($central);
    expect(fn (): ContextReference => new ContextReference($central + ['isolationId' => 'A']))->toThrow(InvalidArgumentException::class);
    $tenant = ['schema' => 'tenant.context/1', 'mode' => 'tenant', 'profileId' => 'tenant', 'profileVersion' => 1, 'namespace' => 'acme', 'isolationId' => 'A', 'resourceRef' => 'A', 'incarnation' => 'i1', 'routeRevision' => 'r1', 'extensions' => [['type' => 'acme:feature', 'version' => 1, 'required' => true, 'refs' => ['key' => new stdClass]]]];
    expect(fn (): ContextReference => new ContextReference($tenant))->toThrow(InvalidArgumentException::class);
});
