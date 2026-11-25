<?php

declare(strict_types=1);

namespace TenantTests;

use Fiber;
use LogicException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tenant\Composition\CompiledComposition;
use Tenant\Composition\CompositionBuilder;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Context\ExtensionDecoder;
use Tenant\Contracts\Context\FacetAccess;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\ExtensionRef;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Runtime\FrameToken;
use Tenant\Data\Runtime\RuntimeObservation;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Data\Verification\VerifiedBinding;
use Tenant\Exceptions\Context\ContextRestoreFailed;
use Tenant\Exceptions\Context\SelectionLost;
use Tenant\Exceptions\TenantFailure;
use Tenant\Exceptions\Verification\BindingMismatch;
use Tenant\Runtime\GenerationGuard;
use Tenant\Runtime\SafeTenantRuntime;

require_once __DIR__.'/RuntimeRig.php';
require_once __DIR__.'/ReviewRegression.php';

final class Scenarios
{
    public static function execute(string $fixture, bool $decorated): array
    {
        $before = Check::$count;
        $rig = new RuntimeRig($decorated);
        match ($fixture) {
            'STATE-01' => self::modes($rig),
            'STATE-02' => self::transition($rig),
            'STATE-03' => self::nested($rig),
            'STATE-04' => self::poison($rig),
            'STATE-05' => self::lifetime($rig),
            'STATE-06' => self::tokens($rig),
            'AUTH-01' => self::authority($rig),
            'AUTH-02' => self::revocation($rig),
            'SELECT-01' => self::selectionLost($rig),
            'EXT01-PLUGIN' => self::plugin($rig),
            'EXT02-ORDER' => self::order($rig),
            'EXT02-NEGATIVE' => self::negative($rig),
            'EXT03-SNAPSHOTS' => self::snapshots($rig),
            'JOURNAL-01' => self::journal($rig),
            'EXT04-EVENTS', 'EVENT-TRACE-01' => self::events($rig),
            'EXT05-OWNER' => self::owner($rig),
            default => throw new RuntimeException('Unknown original fixture '.$fixture),
        };

        return ['fixtureId' => $fixture, 'variant' => $decorated ? 'decorator' : 'default', 'assertions' => Check::$count - $before, 'trace' => $rig->trace, 'lookups' => $rig->resolver->lookups, 'observations' => $rig->observations, 'diagnostics' => $rig->diagnostics];
    }

    public static function modes(RuntimeRig $r): void
    {
        Check::same($r->tenant->current()->kind, 'missing');
        Check::reason(fn (): GenerationGuard => $r->runtime->guard(), 'missing');
        $central = $r->tenant->enter(Selection::central(new ProfileRef('php-scoped', 1)), RuntimeRig::purpose());
        Check::same($r->tenant->current()->kind, 'central');
        Check::reason(fn (): GenerationGuard => $r->runtime->guard(), 'denied');
        $r->tenant->restore($central);
        Check::same($r->tenant->current()->kind, 'missing');
        $a = $r->enter('A');
        Check::same($r->tenant->current()->context->mode, 'tenant');
        $r->tenant->restore($a);
        $a = $r->tenant->enter(Selection::single(new IsolationIdentity('acct', 'A')), RuntimeRig::purpose());
        Check::same($r->tenant->current()->context->mode, 'single');
        Check::reason(fn (): FrameToken => $r->tenant->enter(Selection::single(new IsolationIdentity('acct', 'B')), RuntimeRig::purpose()), 'denied');
        $r->tenant->restore($a);
        unset($r->resolver->catalog['A']);
        Check::reason(fn (): FrameToken => $r->tenant->enter(Selection::single(new IsolationIdentity('acct', 'A')), RuntimeRig::purpose()), 'unavailable');
    }

    public static function transition(RuntimeRig $r): void
    {
        $a = $r->enter('A');
        $r->hook = function () use ($r, $a): void {
            Check::reason(fn (): ContextState => $r->tenant->current(), 'transition_in_progress');
            Check::reason(fn (): FrameToken => $r->enter('A'), 'transition_in_progress');
            Check::reason(fn () => $r->tenant->restore($a), 'transition_in_progress');
        };
        $b = $r->enter('B');
        Check::same($r->tenant->current()->context->identity->id, 'B');
        Check::reason(fn (): ContextState => $r->retainedPhase->candidate(), 'stale_handle');
        $r->tenant->restore($b);
        $r->hook = null;
        $r->tenant->restore($a);
        $fiber = new Fiber(fn (): TenantFailure => Check::reason(fn (): FrameToken => $r->enter('A'), 'concurrent_entry'));
        $fiber->start();
    }

    public static function nested(RuntimeRig $r): void
    {
        $central = $r->tenant->enter(Selection::central(new ProfileRef('php-scoped', 1)), RuntimeRig::purpose());
        $value = $r->tenant->run(RuntimeRig::selection('A'), RuntimeRig::purpose(), function (ContextState $a) use ($r): string {
            Check::same($a->context->identity->id, 'A');
            $e = new RuntimeException('callback');
            Check::same(Check::failure(fn (): mixed => $r->tenant->run(RuntimeRig::selection('B'), RuntimeRig::purpose(), static fn (): never => throw $e)), $e);
            Check::same($r->tenant->current()->context->identity->id, 'A');
            Check::same(array_values($r->cells), ['A', 'A', 'A', 'A']);

            return 'result';
        });
        Check::same($value, 'result');
        Check::same($r->tenant->current()->kind, 'central');
        $r->tenant->restore($central);
        Check::same($r->tenant->current()->kind, 'missing');
        // Every prepare and activate position, including partial prepare mutation.
        foreach (['prepare', 'activate'] as $phase) {
            foreach (['cache', 'db', 'h1', 'h2'] as $id) {
                $x = new RuntimeRig($r->decorated);
                $a = $x->enter('A');
                $e = new RuntimeException($phase.':'.$id);
                $x->failures[$phase.':'.$id.':B'] = $e;
                Check::switchFailure(fn (): FrameToken => $x->enter('B'), $e);
                Check::same($x->tenant->current()->context->identity->id, 'A');
                Check::same(array_values($x->cells), ['A', 'A', 'A', 'A']);
                Check::truth(in_array('restore:'.$id.':B', $x->trace, true), 'failing participant restored');
                $x->tenant->restore($a);
                $r->trace[] = ['injected' => $phase.':'.$id, 'trace' => $x->trace];
            }
        }
    }

    public static function poison(RuntimeRig $r): void
    {
        // Lookup and every restore/verify failure: original first, all cleanup causes retained.
        $a = $r->enter('A');
        $guard = $r->runtime->guard();
        $e1 = new LogicException('callback secret');
        $e2 = new RuntimeException('h2 restore');
        $e3 = new RuntimeException('h1 verify');
        $failure = Check::reason(fn (): mixed => $r->tenant->run(RuntimeRig::selection('B'), RuntimeRig::purpose(), function () use ($r, $e1, $e2, $e3): never {
            unset($r->resolver->catalog['A']);
            $r->failures['restore:h2:B'] = $e2;
            $r->failures['verify:h1:B'] = $e3;
            throw $e1;
        }), 'restore_failed');
        Check::same($failure->causes()[0], $e1);
        Check::same($failure->causes()[1]->reason(), 'unavailable');
        Check::same(array_slice($failure->causes(), 2), [$e2, $e3]);
        Check::same(array_values(array_filter($r->trace, static fn ($e): bool => is_string($e) && str_starts_with($e, 'restore:') && str_ends_with($e, ':B'))), ['restore:h2:B', 'restore:h1:B', 'restore:db:B', 'restore:cache:B']);
        foreach ([$r->tenant->current(...), fn (): FrameToken => $r->enter('A'), fn () => $r->tenant->restore($a), fn (): mixed => $r->tenant->run(RuntimeRig::selection('A'), RuntimeRig::purpose(), fn (): true => true), $guard->check(...)] as $call) {
            Check::reason($call, 'runtime_poisoned');
        }
        foreach (['restore', 'verify'] as $phase) {
            foreach (['cache', 'db', 'h1', 'h2'] as $id) {
                $x = new RuntimeRig($r->decorated);
                $a = $x->enter('A');
                $x->failures[$phase.':'.$id.':A'] = new RuntimeException('cleanup');
                Check::reason(fn () => $x->tenant->restore($a), 'restore_failed');
                Check::same(count(array_filter($x->trace, fn ($e): bool => str_starts_with($e, 'verify:'))), 4);
                $r->trace[] = ['injected' => $phase.':'.$id, 'trace' => $x->trace];
            }
        }
        foreach ([false, true] as $outer) {
            $x = new RuntimeRig($r->decorated);
            if ($outer) {
                $x->enter('A');
            }
            $x->observerThrows = $x->diagnosticThrows = true;
            $x->failures['activate:h2:B'] = $e1;
            $x->failures['restore:db:B'] = $e2;
            Check::same(Check::reason(fn (): FrameToken => $x->enter('B'), 'restore_failed')->causes(), [$e1, $e2]);
        }
        // Catching a fatal nested failure cannot turn outer run into success.
        $x = new RuntimeRig($r->decorated);
        Check::reason(fn (): mixed => $x->tenant->run(RuntimeRig::selection('A'), RuntimeRig::purpose(), function () use ($x): string {
            $x->failures['restore:db:B'] = new RuntimeException('cleanup');
            Check::reason(fn (): mixed => $x->tenant->run(RuntimeRig::selection('B'), RuntimeRig::purpose(), fn (): true => true), 'restore_failed');

            return 'caught';
        }), 'restore_failed');
        $x = new RuntimeRig($r->decorated);
        $afterCatch = new LogicException('outer callback after caught fatal');
        $cleanup = new RuntimeException('inner cleanup');
        $failure = Check::reason(fn (): mixed => $x->tenant->run(RuntimeRig::selection('A'), RuntimeRig::purpose(), function () use ($x, $afterCatch, $cleanup): never {
            $x->failures['restore:db:B'] = $cleanup;
            Check::reason(fn (): mixed => $x->tenant->run(RuntimeRig::selection('B'), RuntimeRig::purpose(), fn (): true => true), 'restore_failed');
            throw $afterCatch;
        }), 'restore_failed');
        Check::truth($failure instanceof ContextRestoreFailed, 'typed fatal remains typed after a caught nested fatal');
        Check::same($failure->causes(), [$afterCatch, $cleanup]);
    }

    public static function lifetime(RuntimeRig $r): void
    {
        $a = $r->enter('A');
        $old = $r->runtime->guard();
        $old->check();
        $b = $r->enter('B');
        Check::reason(fn () => $old->check(), 'stale_handle');
        $r->tenant->restore($b);
        Check::reason(fn () => $old->check(), 'stale_handle');
        $fresh = $r->runtime->guard();
        $fresh->check();
        $release = $fresh->acquire();
        $n = count($r->trace);
        Check::reason(fn () => $r->tenant->restore($a), 'open_resource');
        Check::reason(fn (): FrameToken => $r->enter('B'), 'open_resource');
        Check::same(count($r->trace), $n);
        $release();
        $release();
        $r->tenant->restore($a);
        foreach (['token', 'lease', 'manual-close'] as $leak) {
            $x = new RuntimeRig($r->decorated);
            Check::reason(fn (): mixed => $x->tenant->run(RuntimeRig::selection('A'), RuntimeRig::purpose(), function () use ($x, $leak): string {
                if ($leak === 'token') {
                    $x->enter('B');
                } elseif ($leak === 'lease') {
                    $x->runtime->guard()->acquire();
                } else {
                    $token = new ReflectionProperty($x->runtime, 'frames')->getValue($x->runtime)[0]->token;
                    $x->tenant->restore($token);
                }

                return 'unreachable-success';
            }), 'frame_leak');
            Check::reason(fn (): FrameToken => $x->enter('B'), 'runtime_poisoned');
        }
    }

    public static function tokens(RuntimeRig $r): void
    {
        $a = $r->enter('A');
        $b = $r->enter('B');
        $foreign = new RuntimeRig;
        $f = $foreign->enter('A');
        foreach ([$a, $f, new FrameToken, new ReflectionClass(FrameToken::class)->newInstanceWithoutConstructor()] as $invalid) {
            Check::reason(fn () => $r->tenant->restore($invalid), 'invalid_frame_token');
            Check::same($r->tenant->current()->context->identity->id, 'B');
        }
        Check::failure(fn (): string => serialize($b));
        Check::failure(fn (): FrameToken => clone $b);
        Check::failure(fn (): SafeTenantRuntime => clone $r->runtime);
        Check::failure(fn (): string => serialize($r->runtime));
        Check::failure(fn (): mixed => unserialize('O:22:"Tenant\\Data\\FrameToken":0:{}'));
        $r->tenant->restore($b);
        Check::reason(fn () => $r->tenant->restore($b), 'invalid_frame_token');
        $r->tenant->restore($a);
        Check::reason(fn () => $r->tenant->restore($a), 'invalid_frame_token');
        $foreign->tenant->restore($f);
    }

    public static function authority(RuntimeRig $r): void
    {
        $a = $r->enter('A');
        $before = $r->cells;
        $r->resolver->denied['B'] = true;
        foreach (['B' => 'denied', 'missing' => 'unavailable', 'forged' => 'unavailable'] as $id => $reason) {
            Check::reason(fn (): FrameToken => $r->enter($id), $reason);
        }
        $r->resolver->trusted = false;
        Check::reason(fn (): FrameToken => $r->enter('A'), 'untrusted_producer');
        $r->resolver->trusted = true;
        Check::same($r->cells, $before);
        Check::same($r->tenant->current()->context->identity->id, 'A');
        Check::reason(fn (): FrameToken => $r->tenant->enter(RuntimeRig::selection('A', [], new BindingExpectation('A', 'wrong', 'r1')), RuntimeRig::purpose()), 'stale_incarnation');
        Check::reason(fn (): FrameToken => $r->tenant->enter(RuntimeRig::selection('A', [], new BindingExpectation('A', 'i1', 'old')), RuntimeRig::purpose()), 'stale_route');
        $r->tenant->restore($a);
        $r->trace[] = ['review-F1' => [
            'identities' => ReviewRegression::identities($r->decorated),
            'extensions' => ReviewRegression::extensions($r->decorated),
            'proofs' => ReviewRegression::proofs($r->decorated),
        ]];
    }

    public static function revocation(RuntimeRig $r): void
    {
        $a = $r->enter('A');
        $r->resolver->purposeDenied['acme:read'] = true;
        Check::reason(fn (): FrameToken => $r->enter('A'), 'denied');
        $maintenance = $r->tenant->enter(RuntimeRig::selection('A'), RuntimeRig::purpose('acme:maintenance'));
        unset($r->resolver->purposeDenied['acme:read']);
        $r->tenant->restore($maintenance);
        Check::same(count(array_filter($r->resolver->lookups, fn (array $l): bool => $l[0] === 'A')), 4);
        $r->tenant->restore($a);
    }

    public static function selectionLost(RuntimeRig $r): void
    {
        $a = $r->enter('A');
        $r->hook = static function ($phase, $id, $state): void {
            if ($phase === 'activate' && $id === 'h2' && $state->get('target') === 'B') {
                throw new TenantFailure('selection_lost');
            }
        };
        Check::reason(fn (): FrameToken => $r->enter('B'), 'selection_lost');
        Check::same(array_values($r->cells), ['A', 'A', 'A', 'A']);
        Check::same($r->tenant->current()->context->identity->id, 'A');
        $r->hook = null;
        $r->tenant->restore($a);
    }

    public static function plugin(RuntimeRig $r): void
    {
        $cap = new CapabilityId('acme:feature');
        $builder = new CompositionBuilder(RuntimeRig::profiles());
        $builder->register($r->declaration('old', 'resource', [$cap], [], ['acme:slot']));
        $builder->register($r->declaration('plugin', 'resource', [$cap], [], ['acme:slot'], replaces: 'acme:old'));
        $builder->register($r->declaration('host', 'product', requires: [$cap]));
        $compiled = $builder->compile();
        Check::same($compiled->orderedIds, ['acme:plugin', 'acme:host']);
        $delegate = $r->resolver;
        $decorator = new class($delegate) implements ContextResolver
        {
            public int $calls = 0;

            public function __construct(private ContextResolver $delegate) {}

            public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
            {
                $this->calls++;

                return $this->delegate->resolve($selection, $purpose);
            }
        };
        $runtime = new SafeTenantRuntime($decorator, $compiled);
        $token = $runtime->enter(RuntimeRig::selection('A'), RuntimeRig::purpose());
        $runtime->restore($token);
        Check::same($decorator->calls, 1);
        Check::same($r->cells['plugin'], 'missing');
        self::bindingAndExtensions($r);
    }

    public static function bindingAndExtensions(RuntimeRig $r): void
    {
        $access = new class implements FacetAccess {};
        $resolver = new class($r->resolver, $access) implements ContextResolver
        {
            public function __construct(private ContextResolver $delegate, private FacetAccess $access) {}

            public function resolve(Selection $s, Purpose $p): ResolvedIsolation
            {
                $resolved = $this->delegate->resolve($s, $p);

                return new ResolvedIsolation($resolved->context, new FacetBindings(['acme:binding' => $this->access]));
            }
        };
        $verifier = new class($access, $r) implements BindingVerifier
        {
            public bool $fail = false;

            public int $calls = 0;

            public function __construct(private FacetAccess $expectedAccess, private RuntimeRig $rig) {}

            public function verify(BindingVerificationInput $input): VerifiedBinding
            {
                $this->calls++;
                $this->rig->trace[] = 'binding:'.$input->expected->resourceRef;
                Check::same($input->access, $this->expectedAccess);
                if ($this->fail) {
                    throw new BindingMismatch;
                }

                return new VerifiedBinding($input->expected, 'acme:owner', 'g1');
            }
        };
        $decoder = new class($r) implements ExtensionDecoder
        {
            public array $calls = [];

            public function __construct(private RuntimeRig $rig) {}

            public function validate(ExtensionRef $reference, PhaseContext $context, Purpose $purpose): void
            {
                $this->calls[] = $context->candidate()->context->identity->id;
                $this->rig->trace[] = 'extension:'.$reference->refs['selection'];
                Check::truth($this->rig->cells['edge'] !== 'missing', 'extension after resource activation');
                if ($reference->refs['selection'] === 'deleted') {
                    throw new SelectionLost;
                }
            }
        };
        $profiles = RuntimeRig::profiles();
        $profiles['php-scoped']['bindingCapability'] = 'acme:binding';
        $services = [
            ['id' => 'acme:verifier', 'slot' => 'tenant:binding/default', 'spiVersion' => 1, 'service' => $verifier, 'capabilities' => []],
            ['id' => 'acme:decoder', 'slot' => 'tenant:extension/acme:selection/1', 'spiVersion' => 1, 'service' => $decoder, 'capabilities' => []],
        ];
        $builder = new CompositionBuilder($profiles, $services);
        $builder->register($r->declaration('edge'));
        $runtime = new SafeTenantRuntime($resolver, $builder->compile());
        $required = new ExtensionRef('acme:selection', 1, true, ['selection' => 'W']);
        $runtime->enter(RuntimeRig::selection('A', [$required]), RuntimeRig::purpose());
        Check::same($decoder->calls, ['A']);
        Check::same($verifier->calls, 1);
        $b = $runtime->enter(RuntimeRig::selection('B'), RuntimeRig::purpose());
        $runtime->restore($b);
        Check::same($verifier->calls, 3); // B entry then fresh A restoration proof.
        Check::same($runtime->current()->context->identity->id, 'A');
        $n = count($r->resolver->lookups);
        Check::reason(fn (): FrameToken => $runtime->enter(RuntimeRig::selection('B', [new ExtensionRef('unknown:type', 1, true, ['x' => '1'])]), RuntimeRig::purpose()), 'unsupported_extension');
        Check::same(count($r->resolver->lookups), $n);
        Check::reason(fn (): FrameToken => $runtime->enter(RuntimeRig::selection('B', [new ExtensionRef('acme:selection', 1, true, ['selection' => 'deleted'])]), RuntimeRig::purpose()), 'selection_lost');
        Check::same($runtime->current()->context->identity->id, 'A');
        $optional = $runtime->enter(RuntimeRig::selection('B', [new ExtensionRef('unknown:type', 1, false, ['x' => '1'])]), RuntimeRig::purpose());
        $runtime->restore($optional);
        $verifier->fail = true;
        Check::reason(fn (): FrameToken => $runtime->enter(RuntimeRig::selection('B'), RuntimeRig::purpose()), 'restore_failed');
        Check::reason(fn (): ContextState => $runtime->current(), 'runtime_poisoned');
        // Previous incarnation/route changes cannot silently rebind a retained frame.
        foreach (['incarnation', 'route'] as $changed) {
            $x = new RuntimeRig($r->decorated);
            $x->enter('A');
            $b = $x->enter('B');
            $x->resolver->catalog['A'] = $changed === 'incarnation' ? ['i2', 'r1'] : ['i1', 'r2'];
            $failure = Check::reason(fn () => $x->tenant->restore($b), 'restore_failed');
            Check::same($failure->causes()[0]->reason(), $changed === 'incarnation' ? 'stale_incarnation' : 'stale_route');
            Check::same(count(array_filter($x->trace, fn ($e): bool => str_starts_with($e, 'verify:'))), 4);
        }
    }

    public static function order(RuntimeRig $r): void
    {
        $cap = new CapabilityId('acme:feature');
        $parts = [$r->declaration('a', 'resource', [$cap]), $r->declaration('b', 'resource', requires: [$cap]), $r->declaration('c', 'product')];
        foreach ([[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]] as $permutation) {
            $b = new CompositionBuilder(RuntimeRig::profiles());
            foreach ($permutation as $i) {
                $b->register($parts[$i]);
            }
            $c = $b->compile();
            Check::same($c->orderedIds, ['acme:a', 'acme:b', 'acme:c']);
            $runtime = new SafeTenantRuntime($r->resolver, $c);
            $t = $runtime->enter(RuntimeRig::selection('A'), RuntimeRig::purpose());
            $runtime->restore($t);
        }
    }

    public static function negative(RuntimeRig $r): void
    {
        $cap = new CapabilityId('acme:feature');
        $cases = [
            'exclusive_slot_conflict' => [$r->declaration('a', slots: ['acme:slot']), $r->declaration('b', slots: ['acme:slot'])],
            'missing_dependency' => [$r->declaration('a', after: ['acme:absent'])],
            'unsupported_capability' => [$r->declaration('a', requires: [$cap])],
            'ambiguous_capability' => [$r->declaration('a', provides: [$cap]), $r->declaration('b', provides: [$cap]), $r->declaration('c', requires: [$cap])],
            'dependency_cycle' => [$r->declaration('a', after: ['acme:b']), $r->declaration('b', after: ['acme:a'])],
            'incompatible_spi' => [$r->declaration('a', spi: 2)],
            'replacement_target_missing' => [$r->declaration('a', replaces: 'acme:absent')],
            'replacement_incompatible' => [$r->declaration('a', 'resource', [$cap]), $r->declaration('b', 'product', [$cap], replaces: 'acme:a')],
            'invalid_stage_order' => [$r->declaration('a', 'product', before: ['acme:b']), $r->declaration('b', 'resource')],
            'duplicate_implementation' => [$r->declaration('a'), $r->declaration('a')],
        ];
        $before = $r->trace;
        foreach ($cases as $reason => $declarations) {
            $b = new CompositionBuilder;
            foreach ($declarations as $d) {
                $b->register($d);
            }
            Check::reason(fn (): CompiledComposition => $b->compile(), $reason);
        }
        Check::same($r->trace, $before);
        Check::reason(fn () => $r->builder->register($r->declaration('late')), 'composition_frozen');
        Check::reason(fn (): CompiledComposition => $r->builder->compile(), 'composition_frozen');
        $r->trace[] = ['review-F2' => ReviewRegression::profileCapabilities($r->decorated)];
    }

    public static function snapshots(RuntimeRig $r): void
    {
        $a = $r->enter('A');
        $b = $r->enter('B');
        $same = $r->enter('B');
        Check::same(count(array_unique(array_map(spl_object_id(...), $r->states))), 12);
        Check::same(count(array_unique(array_map(spl_object_id(...), $r->participants))), 12);
        $r->tenant->restore($same);
        Check::same(array_values($r->cells), ['B', 'B', 'B', 'B']);
        $r->tenant->restore($b);
        Check::same(array_values($r->cells), ['A', 'A', 'A', 'A']);
        $productOnly = $r->tenant->enter(RuntimeRig::selection('A', [new ExtensionRef('acme:selection', 1, true, ['selection' => 'W1'])]), RuntimeRig::purpose());
        Check::same([$r->cells['db'], $r->cells['h1'], $r->cells['h2']], ['A', 'A/W1', 'A/W1']);
        $productInner = $r->tenant->enter(RuntimeRig::selection('A', [new ExtensionRef('acme:selection', 1, true, ['selection' => 'W2'])]), RuntimeRig::purpose());
        Check::same([$r->cells['db'], $r->cells['h1'], $r->cells['h2']], ['A', 'A/W2', 'A/W2']);
        $r->tenant->restore($productInner);
        Check::same([$r->cells['db'], $r->cells['h1'], $r->cells['h2']], ['A', 'A/W1', 'A/W1']);
        $r->tenant->restore($productOnly);
        $r->tenant->restore($a);
        Check::same(array_values($r->cells), ['missing', 'missing', 'missing', 'missing']);
        self::journal($r);
        $x = new RuntimeRig($r->decorated);
        $x->tenant->enter(RuntimeRig::selection('A', [new ExtensionRef('acme:selection', 1, true, ['selection' => 'W1'])]), RuntimeRig::purpose());
        $b = $x->enter('B');
        unset($x->knownSelections['W1']);
        $failure = Check::reason(fn () => $x->tenant->restore($b), 'restore_failed');
        Check::same($failure->causes()[0]->reason(), 'selection_lost');
    }

    public static function journal(RuntimeRig $r): void
    {
        $a = $r->enter('A');
        $r->trace = [];
        $r->observations = [];
        $e = new RuntimeException('prepare partial');
        $r->failures['prepare:h2:B'] = $e;
        Check::switchFailure(fn (): FrameToken => $r->enter('B'), $e);
        $restores = array_values(array_filter($r->trace, static fn ($s): bool => str_starts_with($s, 'restore:')));
        Check::same($restores, ['restore:h2:B', 'restore:h1:B', 'restore:db:B', 'restore:cache:B']);
        Check::same(array_values($r->cells), ['A', 'A', 'A', 'A']);
        Check::same(count(array_filter($r->observations, fn ($o): bool => $o->kind === 'entered' && $o->subject?->context?->identity->id === 'B')), 0);
        $r->tenant->restore($a);
    }

    public static function events(RuntimeRig $r): void
    {
        $a = $r->enter('A');
        $guard = $r->runtime->guard();
        $r->observerThrows = $r->diagnosticThrows = $r->reenterObserver = true;
        $r->onObserve = function (RuntimeObservation $o) use ($r): void {
            if (in_array($o->kind, ['entered', 'restored'], true)) {
                // Publication and generation precede observation, current is deliberately protected.
                Check::same(new ReflectionProperty($r->runtime, 'published')->getValue($r->runtime), $o->subject);
                Check::truth(new ReflectionProperty($r->runtime, 'generation')->getValue($r->runtime) > 1, 'generation published');
            }
        };
        $e = new LogicException('original callback SECRET');
        Check::same(Check::failure(fn (): mixed => $r->tenant->run(RuntimeRig::selection('B'), RuntimeRig::purpose(), static fn (): never => throw $e)), $e);
        Check::reason(fn () => $guard->check(), 'stale_handle');
        Check::same(array_column(array_slice($r->observations, 1), 'kind'), ['entered', 'callback_failed', 'restored']);
        Check::same($r->observerFailures, []);
        Check::truth(count($r->reentryReasons) >= 9, 'all observation reentry exercised');
        Check::same(array_unique($r->reentryReasons), [0 => 'transition_in_progress']);
        Check::truth(count($r->diagnostics) >= 3, 'dead transport did not interrupt observers');
        Check::truth(! str_contains(json_encode($r->observations), 'SECRET'), 'sanitized observations');
        $r->tenant->restore($a);
        self::journal(new RuntimeRig($r->decorated));
    }

    public static function owner(RuntimeRig $r): void
    {
        self::tokens($r);
        $service = ['id' => 'acme:runtime', 'slot' => 'tenant:runtime/default', 'spiVersion' => 1, 'service' => $r->runtime, 'capabilities' => []];
        Check::reason(fn (): CompiledComposition => new CompositionBuilder([], [$service, $service + []])->compile(), 'duplicate_implementation');
        $other = $service;
        $other['id'] = 'acme:other';
        Check::reason(fn (): CompiledComposition => new CompositionBuilder([], [$service, $other])->compile(), 'exclusive_slot_conflict');
        Check::reason(fn (): SafeTenantRuntime => new SafeTenantRuntime($r->resolver, new CompositionBuilder(RuntimeRig::profiles(), [$service])->compile()), 'exclusive_slot_conflict');
        Check::reason(fn () => $r->builder->register($r->declaration('late')), 'composition_frozen');
        self::poison(new RuntimeRig($r->decorated));
    }
}
