<?php

declare(strict_types=1);

use Tenant\Composition\CompiledComposition;
use Tenant\Composition\CompositionBuilder;
use Tenant\Contracts\Context\FacetAccess;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Context\ContextReference;
use Tenant\Data\Context\ExtensionRef;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Selection;
use Tenant\Data\Runtime\RuntimeObservation;
use Tenant\Data\Verification\BindingExpectation;
use TenantTests\RuntimeRig;

require_once __DIR__.'/../Support/RuntimeRig.php';

it('freezes extension values and selection lists even when callers pass PHP references', function (): void {
    $target = 'W1';
    $extension = new ExtensionRef('acme:selection', 1, true, ['selection' => &$target]);
    $original = $extension;
    $identity = new IsolationIdentity('acct', 'A');
    $profile = new ProfileRef('php-scoped', 1);
    $extensions = [&$extension];
    $tenant = Selection::tenant($identity, $profile, $extensions);
    $single = Selection::single($identity, $extensions);
    $context = new IsolationContext('tenant', $identity, $profile, new BindingExpectation('A', 'i1', 'r1'), $extensions);

    $target = 'W2';
    $extension = new ExtensionRef('acme:selection', 1, true, ['selection' => 'W2']);

    expect($original->refs)->toBe(['selection' => 'W1'])
        ->and($tenant->extensions)->toBe([$original])
        ->and($single->extensions)->toBe([$original])
        ->and($context->extensions)->toBe([$original]);
});

it('keeps frozen durable references detached from nested input aliases', function (): void {
    $identity = 'A';
    $selection = 'W1';
    $extensions = [['type' => 'acme:selection', 'version' => 1, 'required' => true, 'refs' => ['selection' => &$selection]]];
    $reference = new ContextReference([
        'schema' => 'tenant.context/1', 'mode' => 'tenant', 'namespace' => 'acct',
        'isolationId' => &$identity, 'profileId' => 'php-scoped', 'profileVersion' => 1,
        'resourceRef' => 'A', 'incarnation' => 'i1', 'routeRevision' => 'r1', 'extensions' => &$extensions,
    ]);
    $identity = 'B';
    $selection = 'W2';

    expect($reference->envelope['isolationId'])->toBe('A')
        ->and($reference->envelope['extensions'][0]['refs']['selection'])->toBe('W1');
    $extensions = [];
    expect($reference->envelope['extensions'])->toHaveCount(1);
});

it('keeps compiled profiles services and participant metadata frozen', function (): void {
    $rig = new RuntimeRig;
    $profile = ['version' => 1, 'modes' => ['tenant']];
    $resolver = $rig->resolver;
    $capability = new CapabilityId('acme:original');
    $builder = new CompositionBuilder(['php-scoped' => &$profile], [
        ['id' => 'acme:resolver', 'slot' => 'tenant:resolver/default', 'spiVersion' => 1, 'service' => &$resolver, 'capabilities' => []],
    ]);
    $declaration = $rig->declaration('resource', provides: [&$capability]);
    $builder->register($declaration);
    $compiled = $builder->compile();

    $profile['modes'] = ['central'];
    $resolver = new RuntimeRig()->resolver;
    $capability = new CapabilityId('acme:changed');

    expect($compiled->profiles['php-scoped']['modes'])->toBe(['tenant'])
        ->and($compiled->services['tenant:resolver/default'])->toBe($rig->resolver)
        ->and($compiled->participants[0]->provides[0]->id)->toBe('acme:original');
});

it('captures constructor bindings and accepts shared acyclic metadata', function (): void {
    $profile = ['version' => 1, 'modes' => ['tenant']];
    $builder = new CompositionBuilder(['first' => &$profile, 'second' => &$profile]);
    $profile['version'] = 2;

    expect($builder->compile()->profiles)->toBe([
        'first' => ['version' => 1, 'modes' => ['tenant']],
        'second' => ['version' => 1, 'modes' => ['tenant']],
    ]);

    $recursive = [];
    $recursive['self'] = &$recursive;
    expect(fn (): CompositionBuilder => new CompositionBuilder($recursive))->toThrow(InvalidArgumentException::class, 'Recursive arrays');
});

it('detaches caller-created composition arrays without cloning service instances', function (): void {
    $rig = new RuntimeRig;
    $id = 'acme:original';
    $profile = ['version' => 1, 'modes' => ['tenant']];
    $participant = $rig->declaration('resource');
    $resolver = $rig->resolver;
    $compiled = new CompiledComposition(1, [&$id], ['php-scoped' => &$profile], [&$participant], ['tenant:resolver/default' => &$resolver]);
    $original = $participant;
    $id = 'acme:changed';
    $profile['version'] = 2;
    $participant = $rig->declaration('different');
    $resolver = new RuntimeRig()->resolver;

    expect($compiled->orderedIds)->toBe(['acme:original'])
        ->and($compiled->profiles['php-scoped']['version'])->toBe(1)
        ->and($compiled->participants)->toBe([$original])
        ->and($compiled->services['tenant:resolver/default'])->toBe($rig->resolver);
});

it('keeps facet registrations and observation causes stable', function (): void {
    $facet = new class implements FacetAccess {};
    $original = $facet;
    $bindings = new FacetBindings(['acme:binding' => &$facet]);
    $cause = 'denied';
    $observation = new RuntimeObservation(1, 'restore_failed', 'failure', 'frame', null, 'restore_failed', [&$cause]);
    $facet = new class implements FacetAccess {};
    $cause = 'unavailable';

    expect($bindings->require(new CapabilityId('acme:binding')))->toBe($original)
        ->and($observation->causes)->toBe(['denied']);
});
