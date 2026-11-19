<?php

declare(strict_types=1);

use Tenant\Data\Context\ContextReference;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Queue\QueueRegistry;

it('freezes queue allowlists and handler callbacks even when host inputs contain references', function (): void {
    $namespace = 'acct';
    $mode = 'tenant';
    $profileVersion = 1;
    $extensionVersion = 1;
    $extensionField = 'workspace';
    $producer = 'host:producer';
    $purpose = 'host:work';
    $extension = 'host:workspace/1';
    $centralJob = 'TrustedHandler@fire';
    $calls = [];
    $admit = function () use (&$calls): void {
        $calls[] = 'original';
    };
    $profile = ['version' => &$profileVersion, 'modes' => [&$mode]];
    $registry = new QueueRegistry(
        [&$namespace],
        ['php-scoped' => &$profile],
        ['host:workspace' => ['version' => &$extensionVersion, 'refs' => [&$extensionField]]],
        ['host:handler' => [
            'producer' => &$producer, 'purposes' => [&$purpose], 'extensions' => [&$extension],
            'admit' => &$admit, 'handle' => static function (): void {},
        ]],
        str_repeat('k', 32),
        new ProfileRef('php-scoped', 1),
        [&$centralJob],
    );
    $namespace = 'foreign';
    $mode = 'central';
    $profileVersion = 2;
    $profile = ['version' => 3, 'modes' => ['single']];
    $extensionVersion = 2;
    $extensionField = 'foreign';
    $producer = 'foreign:producer';
    $purpose = 'foreign:work';
    $extension = 'foreign:extension/1';
    $centralJob = 'ForeignHandler@fire';
    $admit = function () use (&$calls): void {
        $calls[] = 'replacement';
    };

    $reference = new ContextReference([
        'schema' => 'tenant.context/1', 'mode' => 'tenant', 'namespace' => 'acct', 'isolationId' => 'A',
        'profileId' => 'php-scoped', 'profileVersion' => 1,
        'resourceRef' => 'resource:A', 'incarnation' => 'i1', 'routeRevision' => 'r1',
        'extensions' => [['type' => 'host:workspace', 'version' => 1, 'required' => true, 'refs' => ['workspace' => 'W1']]],
    ]);
    $selection = $registry->selection($reference, 'host:handler');
    $registry->admit('host:producer', 'host:handler', new Purpose('host:work'), []);

    expect($selection->identity?->namespace)->toBe('acct')
        ->and($selection->expectedProfile->version)->toBe(1)
        ->and($selection->extensions[0]->refs)->toBe(['workspace' => 'W1'])
        ->and($registry->centralJobs)->toBe(['TrustedHandler@fire'])
        ->and($calls)->toBe(['original'])
        ->and(fn () => $registry->authorize('foreign:producer', 'host:handler', new Purpose('foreign:work')))
        ->toThrow(TenantFailure::class, 'denied');
});
