<?php

declare(strict_types=1);

namespace TenantTests;

use Closure;
use Tenant\Composition\{CompositionBuilder, CompiledComposition};
use Tenant\Contracts\{ContextResolver, DiagnosticSink, Facility, FrameState, ObservationSink, ParticipantFactory, PhaseContext, RestoreContext, TenantRuntime};
use Tenant\Data\{BindingExpectation, CapabilityId, ContextState, Diagnostic, ExtensionRef, FacetBindings, FrameToken, IsolationContext, IsolationIdentity, ParticipantDeclaration, ProfileRef, Purpose, ResolvedIsolation, RuntimeObservation, Selection};
use Tenant\Exceptions\TenantFailure;
use Tenant\Runtime\SafeTenantRuntime;
use Tenant\Tenant;
use Throwable;

/** CoreX EngineLog/EngineRig/FixedAccounts/FixedConnections/SpyBootstrapper adapted to pure PHP. */
final class RuntimeRig
{
    public array $trace = [];
    public array $cells = [];
    public array $failures = [];
    public array $participants = [];
    public array $states = [];
    public array $observations = [];
    public array $diagnostics = [];
    public array $observerFailures = [];
    public array $reentryReasons = [];
    public array $knownSelections = ['W1' => true, 'W2' => true];
    public ?Closure $hook = null;
    public bool $observerThrows = false;
    public bool $diagnosticThrows = false;
    public bool $reenterObserver = false;
    public FixedResolver $resolver;
    public SafeTenantRuntime $runtime;
    public Tenant $tenant;
    public CompositionBuilder $builder;
    public CompiledComposition $compiled;
    public ?PhaseContext $retainedPhase = null;
    public ?Closure $onObserve = null;

    public function __construct(public bool $decorated = false, array $ids = ['db', 'cache', 'h1', 'h2'])
    {
        $this->resolver = new FixedResolver($this);
        $decoder = new class($this) implements \Tenant\Contracts\ExtensionDecoder {
            public function __construct(private RuntimeRig $rig) {}
            public function validate(ExtensionRef $reference, PhaseContext $context, Purpose $purpose): void {
                if (!isset($this->rig->knownSelections[$reference->refs['selection'] ?? ''])) throw new \Tenant\Exceptions\SelectionLost();
                $this->rig->trace[]='selection:'.$reference->refs['selection'];
            }
        };
        $this->builder = new CompositionBuilder(self::profiles(), [['id'=>'acme:selection-decoder','slot'=>'tenant:extension/acme:selection/1','spiVersion'=>1,'service'=>$decoder,'capabilities'=>[]]]);
        foreach ($ids as $id) {
            $this->cells[$id] = 'missing';
            $this->builder->register($this->declaration($id));
        }
        $this->compiled = $this->builder->compile();
        $first = new class($this) implements ObservationSink {
            public function __construct(private RuntimeRig $rig) {}
            public function observe(RuntimeObservation $o): void {
                $this->rig->trace[] = 'O1:'.$o->kind;
                if ($this->rig->reenterObserver) {
                    foreach ([fn () => $this->rig->tenant->current(), fn () => $this->rig->tenant->enter(RuntimeRig::selection('A'), RuntimeRig::purpose()), fn () => $this->rig->tenant->restore(new FrameToken())] as $call) {
                        try { $call(); $this->rig->reentryReasons[] = 'unexpected-success'; }
                        catch (TenantFailure $e) { $this->rig->reentryReasons[] = $e->reason(); }
                    }
                }
                if ($this->rig->observerThrows) throw new \RuntimeException('observer contains SECRET');
            }
        };
        $second = new class($this) implements ObservationSink {
            public function __construct(private RuntimeRig $rig) {}
            public function observe(RuntimeObservation $o): void {
                $this->rig->trace[] = 'O2:'.$o->kind.':'.($o->subject?->context?->identity->id ?? $o->subject?->kind ?? 'null');
                $this->rig->observations[] = $o;
                if ($this->rig->onObserve !== null) {
                    try { ($this->rig->onObserve)($o); }
                    catch (Throwable $e) { $this->rig->observerFailures[] = $e->getMessage(); throw $e; }
                }
            }
        };
        $diagnostic = new class($this) implements DiagnosticSink {
            public function __construct(private RuntimeRig $rig) {}
            public function report(Diagnostic $d): void {
                $this->rig->diagnostics[] = $d;
                $this->rig->trace[] = 'diagnostic';
                if ($this->rig->diagnosticThrows) throw new \RuntimeException('dead transport');
            }
        };
        $this->runtime = new SafeTenantRuntime($this->resolver, $this->compiled, [$first, $second], $diagnostic);
        $this->tenant = new Tenant($decorated ? new RuntimeDecorator($this->runtime) : $this->runtime);
    }

    public static function profiles(): array { return ['php-scoped' => ['version' => 1, 'modes' => ['tenant', 'central']], 'single' => ['version' => 1, 'modes' => ['single']]]; }
    public static function purpose(string $id = 'acme:read'): Purpose { return new Purpose($id); }
    public static function selection(string $id, array $extensions = [], ?BindingExpectation $binding = null): Selection { return Selection::tenant(new IsolationIdentity('acct', $id), new ProfileRef('php-scoped', 1), $extensions, $binding); }
    public function enter(string $id): FrameToken { return $this->tenant->enter(self::selection($id), self::purpose()); }
    public function declaration(string $id, string $stage = '', array $provides = [], array $requires = [], array $slots = [], array $before = [], array $after = [], ?string $replaces = null, int $spi = 1): ParticipantDeclaration
    {
        $factory = new class($this, $id) implements ParticipantFactory {
            public function __construct(private RuntimeRig $rig, private string $id) {}
            public function create(): Facility {
                $p = new RecordingFacility($this->rig, $this->id);
                $this->rig->participants[] = $p;
                return $p;
            }
        };
        return new ParticipantDeclaration('acme:'.$id, $spi, $stage ?: (str_starts_with($id, 'h') ? 'product' : 'resource'), $provides, $requires, $slots, $before, $after, $replaces, $factory);
    }
}

final class FixedResolver implements ContextResolver
{
    public array $catalog = ['A' => ['i1', 'r1'], 'B' => ['i1', 'r1']];
    public array $denied = [];
    public array $purposeDenied = [];
    public array $lookups = [];
    public bool $trusted = true;
    public function __construct(private RuntimeRig $rig) {}
    public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
    {
        $id = $selection->identity?->id ?? 'central';
        $this->lookups[] = [$id, $purpose->id];
        $this->rig->trace[] = 'resolve:'.$id;
        if (!$this->trusted) throw new TenantFailure('untrusted_producer');
        if (isset($this->purposeDenied[$purpose->id]) || isset($this->denied[$id])) throw new TenantFailure('denied');
        if ($selection->mode === 'central') return new ResolvedIsolation(ContextState::central(), new FacetBindings([]));
        if ($selection->mode === 'single' && $id !== 'A') throw new TenantFailure('denied');
        $record = $this->catalog[$id] ?? throw new TenantFailure('unavailable');
        $context = new IsolationContext($selection->mode, $selection->identity, $selection->expectedProfile, new BindingExpectation($id, $record[0], $record[1]), $selection->extensions);
        return new ResolvedIsolation(ContextState::active($context), new FacetBindings([]));
    }
}

final class RecordingFacility implements Facility
{
    public function __construct(private RuntimeRig $rig, private string $id) {}
    public function prepare(PhaseContext $context, FrameState $state): void
    {
        $this->rig->retainedPhase = $context;
        $this->rig->states[] = $state;
        $state->put('original', $this->rig->cells[$this->id] ?? 'missing');
        $state->put('target', $context->candidate()->context?->identity->id ?? $context->candidate()->kind);
        $value = $state->get('target');
        if (str_starts_with($this->id, 'h')) foreach ($context->candidate()->context?->extensions ?? [] as $extension) {
            if ($extension->type === 'acme:selection') $value .= '/'.$extension->refs['selection'];
        }
        $state->put('value', $value);
        $this->step('prepare', $state);
    }
    public function activate(PhaseContext $context, FrameState $state): void
    {
        $this->rig->cells[$this->id] = $state->get('value');
        $this->step('activate', $state);
    }
    public function restore(RestoreContext $context, FrameState $state): void
    {
        $this->rig->cells[$this->id] = $state->get('original');
        $this->step('restore', $state);
    }
    public function verify(RestoreContext $context, FrameState $state): void
    {
        Check::same($this->rig->cells[$this->id], $state->get('original'), 'restored snapshot '.$this->id);
        $this->step('verify', $state);
    }
    private function step(string $phase, FrameState $state): void
    {
        $key = $phase.':'.$this->id.':'.$state->get('target');
        $this->rig->trace[] = $key;
        if ($this->rig->hook !== null) ($this->rig->hook)($phase, $this->id, $state);
        if (isset($this->rig->failures[$key])) {
            // Fault injection includes partially mutating prepare; journal must still restore it.
            if ($phase === 'prepare') $this->rig->cells[$this->id] = 'partial';
            throw $this->rig->failures[$key];
        }
    }
}

final class RuntimeDecorator implements TenantRuntime
{
    public function __construct(private TenantRuntime $delegate) {}
    public function current(): ContextState { return $this->delegate->current(); }
    public function enter(Selection $selection, Purpose $purpose): FrameToken { return $this->delegate->enter($selection, $purpose); }
    public function run(Selection $selection, Purpose $purpose, Closure $callback): mixed { return $this->delegate->run($selection, $purpose, $callback); }
    public function restore(FrameToken $token): void { $this->delegate->restore($token); }
}

final class Check
{
    public static int $count = 0;
    public static function same(mixed $observed, mixed $expected, string $message = ''): void
    {
        self::$count++;
        if ($observed !== $expected) throw new \RuntimeException($message.' expected '.json_encode($expected).' observed '.json_encode($observed));
    }
    public static function truth(bool $value, string $message): void { self::same($value, true, $message); }
    public static function failure(Closure $callback): Throwable
    {
        self::$count++;
        try { $callback(); } catch (Throwable $failure) { return $failure; }
        throw new \RuntimeException('Expected failure but call succeeded');
    }
    public static function reason(Closure $callback, string $reason): TenantFailure
    {
        $failure = self::failure($callback);
        self::truth($failure instanceof TenantFailure, 'reason-coded failure: '.get_class($failure).' '.$failure->getMessage());
        self::same($failure->reason(), $reason);
        return $failure;
    }
    public static function switchFailure(Closure $callback, Throwable $original): void
    {
        self::same(self::reason($callback, 'switch_failed')->causes(), [$original]);
    }
}
