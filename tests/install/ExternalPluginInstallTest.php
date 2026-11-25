<?php

declare(strict_types=1);

use Tenant\Composition\CompositionBuilder;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Context\ExtensionDecoder;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\ExtensionRef;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Exceptions\Context\ContextDenied;
use Tenant\Runtime\SafeTenantRuntime;

require_once __DIR__.'/fixtures/Scenarios.php';

it('executes ExternalPluginInstall against the package', function (): void {
    pluginScenario();
    expect(true)->toBeTrue();
});

it('publishes, nests and restores an organization city through the extension SPI', function (): void {
    $citiesByOrg = ['org-a' => ['north', 'south'], 'org-b' => ['east']];
    $decoder = new class($citiesByOrg) implements ExtensionDecoder
    {
        /** @param array<string, list<string>> $citiesByOrg */
        public function __construct(private readonly array $citiesByOrg) {}

        public function validate(ExtensionRef $reference, PhaseContext $context, Purpose $purpose): void
        {
            $org = $context->candidate()->context?->identity;
            $city = $reference->refs['city'] ?? null;
            if ($purpose->id !== 'acme:read' || $org?->namespace !== 'org'
                || ! in_array($city, $this->citiesByOrg[$org->id] ?? [], true)) {
                throw new ContextDenied;
            }
        }
    };

    $builder = new CompositionBuilder(
        ['php-scoped' => ['version' => 1, 'modes' => ['tenant', 'central']]],
        [[
            'id' => 'acme:city-decoder',
            'slot' => 'tenant:extension/acme:city/1',
            'spiVersion' => 1,
            'service' => $decoder,
            'capabilities' => [],
        ]],
    );

    // The authenticated host subject is reader-a in this example.
    $resolver = new class('reader-a') implements ContextResolver
    {
        public function __construct(private readonly string $subject) {}

        public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
        {
            $org = $selection->identity;
            // The host authorizes the subject for this operation and organization.
            if ($this->subject !== 'reader-a' || $purpose->id !== 'acme:read'
                || $org?->namespace !== 'org' || $org->id !== 'org-a') {
                throw new ContextDenied;
            }

            return new ResolvedIsolation(
                ContextState::active(new IsolationContext(
                    $selection->mode,
                    $org,
                    $selection->expectedProfile,
                    new BindingExpectation('org:'.$org->id, '1', '1'),
                    $selection->extensions,
                )),
                new FacetBindings([]),
            );
        }
    };
    $runtime = new SafeTenantRuntime($resolver, $builder->compile());

    expect($runtime->current()->kind)->toBe('missing');

    $org = new IsolationIdentity('org', 'org-a');
    $profile = new ProfileRef('php-scoped', 1);
    $purpose = new Purpose('acme:read');
    $selectCity = static function (string $city) use ($org, $profile): Selection {
        $cityRef = new ExtensionRef('acme:city', 1, true, ['city' => $city]);

        return Selection::tenant($org, $profile, extensions: [$cityRef]);
    };

    $readCity = static function (ContextState $state): array {
        $context = $state->context ?? throw new ContextDenied;
        foreach ($context->extensions as $ref) {
            if ($ref->type === 'acme:city' && $ref->version === 1 && isset($ref->refs['city'])) {
                return [$context->identity->namespace, $context->identity->id, $ref->refs['city']];
            }
        }

        throw new ContextDenied;
    };

    $deniedCalls = 0;
    $seen = $runtime->run($selectCity('north'), $purpose, function (ContextState $outer) use ($runtime, $selectCity, $purpose, $readCity, &$deniedCalls): array {
        $before = $readCity($outer);
        $inner = $runtime->run($selectCity('south'), $purpose, $readCity);
        $afterInner = $readCity($runtime->current());

        try {
            $runtime->run($selectCity('east'), $purpose, static function () use (&$deniedCalls): void {
                $deniedCalls++;
            });
            throw new RuntimeException('Expected the foreign city to be denied.');
        } catch (ContextDenied) {
            return [$before, $inner, $afterInner, $readCity($runtime->current())];
        }
    });

    expect($seen)->toBe([
        ['org', 'org-a', 'north'],
        ['org', 'org-a', 'south'],
        ['org', 'org-a', 'north'],
        ['org', 'org-a', 'north'],
    ])->and($deniedCalls)->toBe(0)
        ->and($runtime->current()->kind)->toBe('missing');

    $otherOrg = Selection::tenant(new IsolationIdentity('org', 'org-b'), $profile, extensions: [
        new ExtensionRef('acme:city', 1, true, ['city' => 'east']),
    ]);
    expect(fn () => $runtime->run($otherOrg, $purpose, $readCity))->toThrow(ContextDenied::class)
        ->and($runtime->current()->kind)->toBe('missing');
});
