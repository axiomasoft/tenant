<?php

declare(strict_types=1);

namespace Tenant\Runtime;

use Closure;
use Fiber;
use Tenant\Composition\CompiledComposition;
use Tenant\Contracts\{ContextResolver, DiagnosticSink, ExtensionDecoder, ObservationSink, TenantRuntime};
use Tenant\Data\{ContextState, Diagnostic, FacetBindings, FrameToken, Purpose, ResolvedIsolation, RuntimeObservation, Selection};
use Tenant\Exceptions\TenantFailure;
use Tenant\Internal\Runtime\{Frame, GenerationGuard, ParticipantFrameState, TransitionContext};
use Throwable;

/** Default sequential executor. CoreX started journal/reverse cleanup/finally protocol, portable. */
final class SafeTenantRuntime implements TenantRuntime
{
    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Runtime is process local'); }
    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void { throw new \LogicException('Runtime is process local'); }
    /** @var list<Frame> */
    private array $frames = [];
    private ContextState $published;
    private bool $transition = false;
    private ?TenantFailure $fatal = null;
    private int $generation = 0;
    private int $leases = 0;
    /** @var \WeakMap<\Tenant\Contracts\Facility, bool> Refuse a factory reusing live mutable participants. */
    private readonly \WeakMap $usedParticipants;
    /** @var Fiber<mixed, mixed, mixed, mixed>|null */
    private readonly ?Fiber $lane;

    /** @param list<ObservationSink> $observers */
    public function __construct(
        private readonly ContextResolver $resolver,
        private readonly CompiledComposition $composition,
        private readonly array $observers = [],
        private readonly ?DiagnosticSink $diagnostics = null,
    ) {
        $this->published = ContextState::missing();
        $this->usedParticipants = new \WeakMap();
        $this->lane = Fiber::getCurrent();
        if ($composition->version !== 1) throw new TenantFailure('incompatible_spi');
        if (isset($composition->services['tenant:runtime/default'])) throw new TenantFailure('exclusive_slot_conflict');
        if (isset($composition->services['tenant:resolver/default']) && $composition->services['tenant:resolver/default'] !== $resolver) throw new TenantFailure('replacement_incompatible');
    }

    private function check(): void
    {
        if ($this->fatal !== null) throw new \Tenant\Exceptions\RuntimePoisoned([$this->fatal]);
        if (Fiber::getCurrent() !== $this->lane) throw new \Tenant\Exceptions\ConcurrentEntry();
        if ($this->transition) throw new \Tenant\Exceptions\ContextTransitionInProgress();
    }

    public function current(): ContextState { $this->check(); return $this->published; }

    /** @internal For guarded adapters; never exposes an unguarded resource. */
    public function guard(): GenerationGuard
    {
        $this->check();
        if ($this->published->kind === 'missing') throw new \Tenant\Exceptions\ScopeRequired();
        if ($this->published->kind !== 'active') throw new \Tenant\Exceptions\ContextDenied();
        $generation = $this->generation;
        return new GenerationGuard(function () use ($generation): void {
            $this->check();
            if ($generation !== $this->generation) throw new \Tenant\Exceptions\StaleHandle();
        }, function (int $delta): void { $this->leases += $delta; });
    }

    public function enter(Selection $selection, Purpose $purpose): FrameToken
    {
        $this->check();
        if ($this->leases !== 0) throw new \Tenant\Exceptions\OpenResource();
        $this->transition = true;
        $frame = null;
        $phase = null;
        try {
            $resolved = $this->resolve($selection, $purpose);
            $frame = new Frame($selection, $purpose, $resolved, $this->published);
            $phase = new TransitionContext($resolved->context, $resolved->facets);
            $bindingVerified = false;
            foreach ($this->composition->participants as $declaration) {
                if ($declaration->stage === 'product' && !$bindingVerified) {
                    $this->verifyBinding($selection, $resolved);
                    $bindingVerified = true;
                }
                $facility = $declaration->factory->create();
                if (isset($this->usedParticipants[$facility])) throw new TenantFailure('replacement_incompatible');
                $this->usedParticipants[$facility] = true;
                $state = new ParticipantFrameState();
                // CoreX invariant: include the failing participant, even if prepare throws.
                $frame->started[] = [$facility, $state];
                $facility->prepare($phase, $state);
                $facility->activate($phase, $state);
            }
            if (!$bindingVerified) $this->verifyBinding($selection, $resolved);
            foreach ($selection->extensions as $extension) {
                $decoder = $this->composition->services['tenant:extension/'.$extension->type.'/'.$extension->version] ?? null;
                if ($decoder instanceof ExtensionDecoder) $decoder->validate($extension, $phase, $purpose);
            }
            $this->frames[] = $frame;
            $this->publish($resolved->context);
            $this->observe('entered', 'success', $frame, $resolved->context);
            return $frame->token;
        } catch (Throwable $failure) {
            $this->observe('activation_failed', 'failure', $frame, null, $failure);
            if ($frame !== null && $frame->started !== []) $this->cleanup($frame, $failure, false);
            throw $failure instanceof TenantFailure ? $failure : new \Tenant\Exceptions\ContextSwitchFailed([$failure]);
        } finally {
            $phase?->close();
            $this->transition = false;
        }
    }

    private function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
    {
        $profile = $this->composition->profiles[$selection->expectedProfile->id] ?? null;
        if (!is_array($profile) || ($profile['version'] ?? null) !== $selection->expectedProfile->version || !is_array($profile['modes'] ?? null) || !in_array($selection->mode, $profile['modes'], true)) throw new \Tenant\Exceptions\UnsupportedProfile();
        if (isset($profile['bindingCapability']) && (!is_string($profile['bindingCapability']) || !($this->composition->services['tenant:binding/default'] ?? null) instanceof \Tenant\Contracts\BindingVerifier)) throw new TenantFailure('unsupported_capability');
        foreach ($selection->extensions as $extension) {
            if ($extension->required && !($this->composition->services['tenant:extension/'.$extension->type.'/'.$extension->version] ?? null) instanceof ExtensionDecoder) throw new \Tenant\Exceptions\UnsupportedExtension();
        }
        $resolved = $this->resolver->resolve($selection, $purpose);
        if ($selection->mode !== 'central' && is_string($profile['bindingCapability'] ?? null)) {
            try { $resolved->facets->require(new \Tenant\Data\CapabilityId($profile['bindingCapability'])); }
            catch (\OutOfBoundsException $failure) { throw new TenantFailure('unsupported_capability', [$failure]); }
        }
        $context = $resolved->context->context;
        if ($selection->mode === 'central') {
            if ($resolved->context->kind !== 'central') throw new \Tenant\Exceptions\BindingMismatch();
            return $resolved;
        }
        if ($context === null || $context->mode !== $selection->mode || $context->identity != $selection->identity) throw new \Tenant\Exceptions\BindingMismatch();
        if ($context->profile != $selection->expectedProfile) throw new \Tenant\Exceptions\UnsupportedProfile();
        if ($context->extensions != $selection->extensions) throw new \Tenant\Exceptions\SelectionLost();
        $expected = $selection->expectedBinding;
        if ($expected !== null) {
            if ($expected->resourceRef !== $context->binding->resourceRef || $expected->incarnation !== $context->binding->incarnation) throw new \Tenant\Exceptions\StaleIncarnation();
            if ($expected->routeRevision !== $context->binding->routeRevision) throw new \Tenant\Exceptions\StaleRoute();
        }
        return $resolved;
    }

    private function verifyBinding(Selection $selection, ResolvedIsolation $resolved): void
    {
        if ($selection->mode === 'central') return;
        $profile = $this->composition->profiles[$selection->expectedProfile->id] ?? null;
        $capability = is_array($profile) ? ($profile['bindingCapability'] ?? null) : null;
        $verifier = $this->composition->services['tenant:binding/default'] ?? null;
        if ($capability === null && $verifier === null) return; // Trusted synthetic no-IO resolver profile.
        if (!is_string($capability) || !$verifier instanceof \Tenant\Contracts\BindingVerifier) throw new TenantFailure('unsupported_capability');
        $expected = $resolved->context->context->binding ?? throw new \Tenant\Exceptions\BindingMismatch();
        $proof = $verifier->verify(new \Tenant\Data\BindingVerificationInput($expected, $resolved->facets->require(new \Tenant\Data\CapabilityId($capability))));
        if ($proof->expected != $expected) throw new \Tenant\Exceptions\BindingMismatch();
    }

    public function restore(FrameToken $token): void
    {
        $this->check();
        $frame = $this->frames[count($this->frames) - 1] ?? null;
        if ($frame === null || $frame->token !== $token) throw new \Tenant\Exceptions\InvalidFrameToken();
        if ($this->leases !== 0) throw new \Tenant\Exceptions\OpenResource();
        $this->transition = true;
        try { $this->cleanup($frame, null, true); }
        finally { $this->transition = false; }
    }

    /** Fresh previous lookup never prevents the remaining reverse cleanup attempts. */
    private function cleanup(Frame $frame, ?Throwable $original, bool $pop): void
    {
        $failures = [];
        $previous = $pop ? ($this->frames[count($this->frames) - 2] ?? null) : ($this->frames[count($this->frames) - 1] ?? null);
        $resolved = null;
        try {
            if ($previous !== null) {
                $s = $previous->selection;
                $binding = $previous->resolved->context->context?->binding;
                $pinned = match ($s->mode) {
                    'central' => Selection::central($s->expectedProfile),
                    'single' => Selection::single($s->identity ?? throw new \Tenant\Exceptions\BindingMismatch(), $s->extensions, $binding),
                    default => Selection::tenant($s->identity ?? throw new \Tenant\Exceptions\BindingMismatch(), $s->expectedProfile, $s->extensions, $binding),
                };
                $resolved = $this->resolve($pinned, $previous->purpose);
            }
        } catch (Throwable $failure) { $failures[] = $failure; }
        $restore = new TransitionContext($resolved->context ?? $frame->previous, $resolved->facets ?? new FacetBindings([]), $failures === []);
        try {
            foreach (array_reverse($frame->started) as [$facility, $state]) {
                try { $facility->restore($restore, $state); } catch (Throwable $failure) { $failures[] = $failure; }
                try { $facility->verify($restore, $state); } catch (Throwable $failure) { $failures[] = $failure; }
            }
        } finally { $restore->close(); }
        if ($previous !== null && $resolved !== null) {
            try {
                $this->verifyBinding($previous->selection, $resolved);
                $phase = new TransitionContext($resolved->context, $resolved->facets);
                try {
                    foreach ($previous->selection->extensions as $extension) {
                        $decoder = $this->composition->services['tenant:extension/'.$extension->type.'/'.$extension->version] ?? null;
                        if ($decoder instanceof ExtensionDecoder) $decoder->validate($extension, $phase, $previous->purpose);
                    }
                } finally { $phase->close(); }
            }
            catch (Throwable $failure) { $failures[] = $failure; }
        }
        if ($failures !== []) {
            if ($original !== null) array_unshift($failures, $original);
            $fatal = new \Tenant\Exceptions\ContextRestoreFailed($failures);
            $this->fatal = $fatal;
            $this->generation++;
            $this->observe('restore_failed', 'failure', $frame, null, $fatal);
            $this->observe('poisoned', 'poisoned', $frame, null, $fatal);
            throw $fatal;
        }
        if ($pop) array_pop($this->frames);
        $this->publish($resolved->context ?? $frame->previous);
        $this->observe('restored', 'success', $frame, $this->published);
    }

    public function run(Selection $selection, Purpose $purpose, Closure $callback): mixed
    {
        $token = $this->enter($selection, $purpose);
        $frame = $this->frames[count($this->frames) - 1] ?? throw new \Tenant\Exceptions\InvalidFrameToken();
        $original = null;
        $result = null;
        try { $result = $callback($this->current()); }
        catch (Throwable $failure) {
            $original = $failure;
            $this->transition = true;
            try { $this->observe('callback_failed', 'failure', $frame, $frame->resolved->context, $failure); }
            finally { $this->transition = false; }
        } finally {
            if ($this->fatal !== null) {
                if ($original !== null && $original !== $this->fatal) throw new TenantFailure($this->fatal->reason(), [$original, $this->fatal]);
                throw $this->fatal;
            }
            $top = $this->frames[count($this->frames) - 1] ?? null;
            if ($top?->token !== $token || $this->leases !== 0) {
                $leak = new \Tenant\Exceptions\FrameLeak($original === null ? [] : [$original]);
                $this->fatal = $leak;
                $this->generation++;
                $this->transition = true;
                try { $this->observe('poisoned', 'poisoned', $frame, null, $this->fatal); }
                finally { $this->transition = false; }
                throw $leak;
            }
            $this->transition = true;
            try { $this->cleanup($frame, $original, true); }
            finally { $this->transition = false; }
        }
        if ($original !== null) throw $original;
        return $result;
    }

    private function publish(ContextState $state): void { $this->published = $state; $this->generation++; }

    private function observe(string $kind, string $outcome, ?Frame $frame, ?ContextState $subject, ?Throwable $failure = null): void
    {
        $reason = $failure instanceof TenantFailure ? $failure->reason() : ($failure === null ? null : 'switch_failed');
        $causes = $failure instanceof TenantFailure ? array_map(static fn (Throwable $e): string => $e instanceof TenantFailure ? $e->reason() : 'switch_failed', $failure->causes()) : [];
        $observation = new RuntimeObservation(1, $kind, $outcome, $frame->correlation ?? bin2hex(random_bytes(16)), $subject, $reason, $causes);
        foreach ($this->observers as $id => $observer) {
            try { $observer->observe($observation); }
            catch (Throwable) {
                try { $this->diagnostics?->report(new Diagnostic('observer_failed', (string) $id, $observation->frameCorrelation)); }
                catch (Throwable) { /* Do not recursively diagnose a dead diagnostic transport. */ }
            }
        }
    }
}
