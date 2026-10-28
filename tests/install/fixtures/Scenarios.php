<?php

declare(strict_types=1);

use Tenant\Composition\CompositionBuilder;
use Tenant\Contracts\{ContextResolver, Facility, FrameState, ParticipantFactory, PhaseContext, RestoreContext};
use Tenant\Data\{BindingExpectation, CapabilityId, ContextState, FacetBindings, IsolationIdentity, ParticipantDeclaration, ProfileRef, Purpose, ResolvedIsolation, Selection};
use Tenant\Tenant;

require_once __DIR__.'/SingleHost.php';

function verify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function selection(string $id, ?BindingExpectation $binding): Selection { return Selection::single(new IsolationIdentity('acme', $id), [], $binding); }
function singleScenario(): void
{
    $tenant = new Tenant(new SingleHost()); $purpose = new Purpose('acme:install');
    $value = $tenant->run(selection('A', new BindingExpectation('A', 'i1', 'r1')), $purpose, fn (ContextState $s) => $s);
    verify($value->kind === 'active' && $value->context?->mode === 'single' && $value->context->identity->id === 'A', 'single A');
    foreach ([selection('B', new BindingExpectation('A', 'i1', 'r1')), selection('A', null)] as $bad) {
        try { $tenant->enter($bad, $purpose); throw new RuntimeException('unexpected admission'); } catch (InvalidArgumentException) {}
    }
}
function coreScenario(): void
{
    verify(class_exists(Tenant::class) && interface_exists(ContextResolver::class), 'public surface missing');
    singleScenario();
}
function graphScenario(): void
{
    $package = json_decode(file_get_contents(dirname((new ReflectionClass(Tenant::class))->getFileName(), 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    verify(array_keys($package['require']) === ['php', 'ext-intl'], 'non-neutral dependencies');
    verify((new IsolationIdentity('acme', 'A'))->id !== (new IsolationIdentity('acme', 'P'))->id, 'payer mixed with resource');
}
function pluginScenario(): void
{
    $factory = new class implements ParticipantFactory {
        public function create(): Facility { return new class implements Facility {
            public function prepare(PhaseContext $context, FrameState $state): void {}
            public function activate(PhaseContext $context, FrameState $state): void {}
            public function restore(RestoreContext $context, FrameState $state): void {}
            public function verify(RestoreContext $context, FrameState $state): void {}
        }; }
    };
    $cap = new CapabilityId('acme:feature');
    $builder = new CompositionBuilder();
    $builder->register(new ParticipantDeclaration('tenant:database/default', 1, 'resource', [$cap], [], ['tenant:database/default'], [], [], null, $factory));
    $builder->register(new ParticipantDeclaration('acme:database', 1, 'resource', [$cap], [], ['tenant:database/default'], [], [], 'tenant:database/default', $factory));
    $builder->register(new ParticipantDeclaration('acme:consumer', 1, 'product', [], [$cap], [], [], [], null, $factory));
    verify($builder->compile()->orderedIds === ['acme:database', 'acme:consumer'], 'replacement and capability dependency');
    $delegate = new class implements ContextResolver {
        public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation { return new ResolvedIsolation(ContextState::central(), new FacetBindings([])); }
    };
    $decorator = new class($delegate) implements ContextResolver {
        public int $calls = 0;
        public function __construct(private ContextResolver $delegate) {}
        public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation { $this->calls++; return $this->delegate->resolve($selection, $purpose); }
    };
    $host = new CompositionBuilder([], [['id' => 'acme:resolver', 'slot' => 'tenant:resolver/default', 'spiVersion' => 1, 'service' => $decorator, 'capabilities' => ['acme:resolver']]]);
    $host->register(new ParticipantDeclaration('acme:consumer', 1, 'resource', [], [new CapabilityId('acme:resolver')], [], [], [], null, $factory));
    $compiled = $host->compile();
    $resolved = $compiled->services['tenant:resolver/default'];
    verify($resolved === $decorator && $decorator->resolve(Selection::central(new ProfileRef('central', 1)), new Purpose('acme:install'))->context->kind === 'central' && $decorator->calls === 1, 'decorator');
}
