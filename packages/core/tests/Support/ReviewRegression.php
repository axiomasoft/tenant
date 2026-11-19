<?php

declare(strict_types=1);

namespace TenantTests;

use Tenant\Composition\CompiledComposition;
use Tenant\Composition\CompositionBuilder;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Context\FacetAccess;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\ExtensionRef;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Runtime\FrameToken;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Data\Verification\VerifiedBinding;
use Tenant\Runtime\SafeTenantRuntime;

require_once __DIR__.'/RuntimeRig.php';

/** Assertions for independently reproduced F1/F2, also run in each archived corpus lane. */
final class ReviewRegression
{
    public static function identities(bool $decorated): array
    {
        $records = [];
        foreach ([['1', '01'], ['1', '1.0'], ['1', '1e0'], ['0', '00'], ['0', '-0'], ['123', '0123'], ['1', '1']] as [$selected,$returned]) {
            $rig = new RuntimeRig($decorated, []);
            $resolver = new ValueResolver($rig, $returned);
            $runtime = self::runtime($rig, $resolver);
            $selection = self::selection($selected);
            $callback = false;
            $call = function () use ($runtime, $selection, &$callback): string {
                return $runtime->run($selection, RuntimeRig::purpose(), function (ContextState $state) use (&$callback): string {
                    $callback = true;

                    return $state->context->identity->id;
                });
            };
            if ($selected !== $returned) {
                Check::reason($call, 'binding_mismatch');
                Check::same($callback, false);
                Check::same($rig->cells, []); // No participant prepare/activate after an aliased resolve.
            } else {
                Check::same($call(), $selected);
                Check::same($callback, true); // New equal identity/profile DTO objects must be accepted.
            }
            Check::same($runtime->current()->kind, 'missing');
            $records[] = ['selected' => $selected, 'returned' => $returned, 'callback' => $callback, 'trace' => $rig->trace];
        }

        return $records;
    }

    public static function extensions(bool $decorated): array
    {
        $records = [];
        $wanted = new ExtensionRef('acme:selection', 1, false, ['selection' => '1', 'other' => 'x']);
        $cases = [
            'numeric-ref' => new ExtensionRef('acme:selection', 1, false, ['selection' => '01', 'other' => 'x']),
            'exponent-ref' => new ExtensionRef('acme:selection', 1, false, ['selection' => '1e0', 'other' => 'x']),
            'missing-ref' => new ExtensionRef('acme:selection', 1, false, ['selection' => '1']),
            'type' => new ExtensionRef('acme:other', 1, false, ['selection' => '1', 'other' => 'x']),
            'version' => new ExtensionRef('acme:selection', 2, false, ['selection' => '1', 'other' => 'x']),
            'required' => new ExtensionRef('acme:selection', 1, true, ['selection' => '1', 'other' => 'x']),
            'equal-new-object-reordered-map' => new ExtensionRef('acme:selection', 1, false, ['other' => 'x', 'selection' => '1']),
        ];
        foreach ($cases as $case => $returned) {
            $rig = new RuntimeRig($decorated, []);
            $resolver = new ValueResolver($rig, '1', [$returned]);
            $runtime = self::runtime($rig, $resolver);
            $selection = self::selection('1', [$wanted]);
            if ($case === 'equal-new-object-reordered-map') {
                $token = $runtime->enter($selection, RuntimeRig::purpose());
                Check::truth($runtime->current()->context->extensions[0] !== $wanted, 'equal values in separate objects');
                $runtime->restore($token);
            } else {
                Check::reason(fn (): FrameToken => $runtime->enter($selection, RuntimeRig::purpose()), 'selection_lost');
                Check::same($rig->cells, []);
            }
            Check::same($runtime->current()->kind, 'missing');
            $records[] = ['case' => $case, 'selected' => $wanted, 'returned' => $returned, 'trace' => $rig->trace];
        }

        return $records;
    }

    public static function proofs(bool $decorated): array
    {
        $records = [];
        foreach (['resourceRef', 'incarnation', 'routeRevision', 'equal-new-object'] as $case) {
            $rig = new RuntimeRig($decorated, []);
            $resolver = new ValueResolver($rig, '1');
            $verifier = new class($case) implements BindingVerifier
            {
                public function __construct(private string $case) {}

                public function verify(BindingVerificationInput $input): VerifiedBinding
                {
                    $e = $input->expected;

                    return new VerifiedBinding(new BindingExpectation($this->case === 'resourceRef' ? '01' : $e->resourceRef, $this->case === 'incarnation' ? '01' : $e->incarnation, $this->case === 'routeRevision' ? '01' : $e->routeRevision), 'acme:owner', 'g1');
                }
            };
            $profiles = RuntimeRig::profiles();
            $profiles['php-scoped']['bindingCapability'] = 'acme:binding';
            $services = [['id' => 'acme:verifier', 'slot' => 'tenant:binding/default', 'spiVersion' => 1, 'service' => $verifier, 'capabilities' => []]];
            $builder = new CompositionBuilder($profiles, $services);
            $builder->register($rig->declaration('value'));
            $runtime = self::wrap(new SafeTenantRuntime($resolver, $builder->compile()), $decorated);
            $callback = false;
            $call = function () use ($runtime, &$callback): mixed {
                return $runtime->run(self::selection('1'), RuntimeRig::purpose(), function () use (&$callback): void {
                    $callback = true;
                });
            };
            if ($case === 'equal-new-object') {
                $call();
                Check::same($callback, true);
            } else {
                Check::reason($call, 'binding_mismatch');
                Check::same($callback, false);
            }
            Check::same($runtime->current()->kind, 'missing');
            Check::same($rig->cells['value'], 'missing'); // Partial resource activation was restored.
            Check::truth(in_array('verify:value:1', $rig->trace, true), 'all started verification attempted');
            $records[] = ['case' => $case, 'callback' => $callback, 'trace' => $rig->trace];
        }

        return $records;
    }

    public static function profileCapabilities(bool $decorated): array
    {
        $records = [];
        $cap = new CapabilityId('acme:required-guard');
        foreach (['absent', 'participant', 'service', 'replacement', 'ambiguous', 'mixed-missing', 'numeric-shape', 'scalar-shape', 'map-shape', 'null-shape', 'empty'] as $case) {
            $rig = new RuntimeRig($decorated, []);
            $resolver = new ValueResolver($rig, '1');
            $required = match ($case) {
                'mixed-missing' => [$cap->id, 'acme:absent'],'numeric-shape' => [1],'scalar-shape' => $cap->id,'map-shape' => ['guard' => $cap->id],'null-shape' => null,'empty' => [],default => [$cap->id]
            };
            $profiles = RuntimeRig::profiles();
            $profiles['php-scoped']['requiredCapabilities'] = $required;
            $services = $case === 'service' ? [['id' => 'acme:resolver', 'slot' => 'tenant:resolver/default', 'spiVersion' => 1, 'service' => $resolver, 'capabilities' => [$cap->id]]] : [];
            $builder = new CompositionBuilder($profiles, $services);
            if (in_array($case, ['participant', 'mixed-missing', 'ambiguous'], true)) {
                $builder->register($rig->declaration('value', provides: [$cap]));
            }
            if ($case === 'ambiguous') {
                $builder->register($rig->declaration('other', provides: [$cap]));
            }
            if ($case === 'replacement') {
                $builder->register($rig->declaration('old'));
                $builder->register($rig->declaration('value', provides: [$cap], replaces: 'acme:old'));
            }
            if (in_array($case, ['participant', 'service', 'replacement', 'empty'], true)) {
                $compiled = $builder->compile();
                Check::same($resolver->lookups, 0);
                Check::same($rig->trace, []); // Compilation never starts participants or resolves.
                if ($case === 'replacement') {
                    Check::same($compiled->orderedIds, ['acme:value']);
                }
                $runtime = self::wrap(new SafeTenantRuntime($resolver, $compiled), $decorated);
                Check::same($runtime->run(self::selection('1'), RuntimeRig::purpose(), fn (): string => 'executed'), 'executed');
                Check::same($resolver->lookups, 1);
                Check::same($runtime->current()->kind, 'missing');
            } else {
                Check::reason(fn (): CompiledComposition => $builder->compile(), $case === 'ambiguous' ? 'ambiguous_capability' : 'unsupported_capability');
                Check::same($resolver->lookups, 0);
                Check::same($rig->trace, []);
                Check::same($rig->participants, []);
            }
            $records[] = ['case' => $case, 'requiredCapabilities' => $required, 'lookups' => $resolver->lookups, 'trace' => $rig->trace];
        }

        return $records;
    }

    private static function selection(string $id, array $extensions = []): Selection
    {
        return Selection::tenant(new IsolationIdentity('acct', $id), new ProfileRef('php-scoped', 1), $extensions);
    }

    private static function runtime(RuntimeRig $rig, ValueResolver $resolver): TenantRuntime
    {
        $builder = new CompositionBuilder(RuntimeRig::profiles());
        $builder->register($rig->declaration('value'));

        return self::wrap(new SafeTenantRuntime($resolver, $builder->compile()), $rig->decorated);
    }

    private static function wrap(SafeTenantRuntime $runtime, bool $decorated): TenantRuntime
    {
        return $decorated ? new RuntimeDecorator($runtime) : $runtime;
    }
}

final class ValueResolver implements ContextResolver
{
    public int $lookups = 0;

    public function __construct(private RuntimeRig $rig, private string $returnedId, private ?array $returnedExtensions = null) {}

    public function resolve(Selection $s, Purpose $p): ResolvedIsolation
    {
        $this->lookups++;
        $this->rig->trace[] = 'value-resolve:'.$this->returnedId;
        $extensions = array_map(static fn (ExtensionRef $e): ExtensionRef => new ExtensionRef($e->type, $e->version, $e->required, $e->refs), $this->returnedExtensions ?? $s->extensions);

        return new ResolvedIsolation(ContextState::active(new IsolationContext($s->mode, new IsolationIdentity($s->identity->namespace, $this->returnedId), new ProfileRef($s->expectedProfile->id, $s->expectedProfile->version), new BindingExpectation('1', '1', '1'), $extensions)), new FacetBindings(['acme:binding' => new class implements FacetAccess {}]));
    }
}
