<?php

declare(strict_types=1);

namespace Tenant\Runtime;

use Closure;
use Fiber;
use LogicException;
use OutOfBoundsException;
use Packman\Attributes\Api;
use Tenant\Composition\CompiledComposition;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Context\ExtensionDecoder;
use Tenant\Contracts\Runtime\DiagnosticSink;
use Tenant\Contracts\Runtime\Facility;
use Tenant\Contracts\Runtime\ObservationSink;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\ExtensionRef;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Runtime\Diagnostic;
use Tenant\Data\Runtime\FrameToken;
use Tenant\Data\Runtime\RuntimeObservation;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Exceptions\Context\ContextDenied;
use Tenant\Exceptions\Context\ContextRestoreFailed;
use Tenant\Exceptions\Context\ContextSwitchFailed;
use Tenant\Exceptions\Context\ContextTransitionInProgress;
use Tenant\Exceptions\Context\ScopeRequired;
use Tenant\Exceptions\Context\SelectionLost;
use Tenant\Exceptions\Context\UnsupportedExtension;
use Tenant\Exceptions\Context\UnsupportedProfile;
use Tenant\Exceptions\Runtime\ConcurrentEntry;
use Tenant\Exceptions\Runtime\FrameLeak;
use Tenant\Exceptions\Runtime\InvalidFrameToken;
use Tenant\Exceptions\Runtime\OpenResource;
use Tenant\Exceptions\Runtime\RuntimePoisoned;
use Tenant\Exceptions\Runtime\StaleHandle;
use Tenant\Exceptions\TenantFailure;
use Tenant\Exceptions\Verification\BindingMismatch;
use Tenant\Exceptions\Verification\StaleIncarnation;
use Tenant\Exceptions\Verification\StaleRoute;
use Tenant\Internal\Runtime\Frame;
use Tenant\Internal\Runtime\ParticipantFrameState;
use Tenant\Internal\Runtime\TransitionContext;
use Throwable;
use WeakMap;

/** Default sequential executor using a started-participant journal, reverse cleanup, and poison boundary. */
#[Api(since: '1.0')]
final class SafeTenantRuntime implements TenantRuntime
{
    private function __clone() {}

    public function __serialize(): array
    {
        throw new LogicException('Runtime is process local');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Runtime is process local');
    }

    /** @var list<Frame> */
    private array $frames = [];

    private ContextState $published;

    private bool $transition = false;

    private ?TenantFailure $fatal = null;

    private int $generation = 0;

    private int $leases = 0;

    /** @var WeakMap<Facility, bool> Refuse a factory reusing live mutable participants. */
    private readonly WeakMap $usedParticipants;

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
        $this->usedParticipants = new WeakMap;
        $this->lane = Fiber::getCurrent();
        if ($composition->version !== 1) {
            throw new TenantFailure('incompatible_spi');
        }
        if (isset($composition->services['tenant:runtime/default'])) {
            throw new TenantFailure('exclusive_slot_conflict');
        }
        if (isset($composition->services['tenant:resolver/default']) && $composition->services['tenant:resolver/default'] !== $resolver) {
            throw new TenantFailure('replacement_incompatible');
        }
    }

    private function check(): void
    {
        if ($this->fatal !== null) {
            throw new RuntimePoisoned([$this->fatal]);
        }
        if (Fiber::getCurrent() !== $this->lane) {
            throw new ConcurrentEntry;
        }
        if ($this->transition) {
            throw new ContextTransitionInProgress;
        }
    }

    public function current(): ContextState
    {
        $this->check();

        return $this->published;
    }

    /** @internal For guarded adapters; never exposes an unguarded resource. */
    public function guard(): GenerationGuard
    {
        $this->check();
        if ($this->published->kind === 'missing') {
            throw new ScopeRequired;
        }
        if ($this->published->kind !== 'active') {
            throw new ContextDenied;
        }
        $generation = $this->generation;

        return new GenerationGuard(function () use ($generation): void {
            $this->check();
            if ($generation !== $this->generation) {
                throw new StaleHandle;
            }
        }, function (int $delta): void {
            $this->leases += $delta;
        });
    }

    public function enter(Selection $selection, Purpose $purpose): FrameToken
    {
        $this->check();
        if ($this->leases !== 0) {
            throw new OpenResource;
        }
        $this->transition = true;
        $frame = null;
        $phase = null;
        try {
            $resolved = $this->resolve($selection, $purpose);
            $frame = new Frame($selection, $purpose, $resolved, $this->published);
            $phase = new TransitionContext($resolved->context, $resolved->facets);
            $bindingVerified = false;
            foreach ($this->composition->participants as $declaration) {
                if ($declaration->stage === 'product' && ! $bindingVerified) {
                    $this->verifyBinding($selection, $resolved);
                    $bindingVerified = true;
                }
                $facility = $declaration->factory->create();
                if (isset($this->usedParticipants[$facility])) {
                    throw new TenantFailure('replacement_incompatible');
                }
                $this->usedParticipants[$facility] = true;
                $state = new ParticipantFrameState;
                // Protocol invariant: include the failing participant, even if prepare throws.
                $frame->started[] = [$facility, $state];
                $facility->prepare($phase, $state);
                $facility->activate($phase, $state);
            }
            if (! $bindingVerified) {
                $this->verifyBinding($selection, $resolved);
            }
            foreach ($selection->extensions as $extension) {
                $decoder = $this->composition->services['tenant:extension/'.$extension->type.'/'.$extension->version] ?? null;
                if ($decoder instanceof ExtensionDecoder) {
                    $decoder->validate($extension, $phase, $purpose);
                }
            }
            $this->frames[] = $frame;
            $this->publish($resolved->context);
            $this->observe('entered', 'success', $frame, $resolved->context);

            return $frame->token;
        } catch (Throwable $failure) {
            $this->observe('activation_failed', 'failure', $frame, null, $failure);
            if ($frame !== null && $frame->started !== []) {
                $this->cleanup($frame, $failure, false);
            }
            throw $failure instanceof TenantFailure ? $failure : new ContextSwitchFailed([$failure]);
        } finally {
            $phase?->close();
            $this->transition = false;
        }
    }

    private function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
    {
        $profile = $this->composition->profiles[$selection->expectedProfile->id] ?? null;
        if (! is_array($profile) || ($profile['version'] ?? null) !== $selection->expectedProfile->version || ! is_array($profile['modes'] ?? null) || ! in_array($selection->mode, $profile['modes'], true)) {
            throw new UnsupportedProfile;
        }
        if (isset($profile['bindingCapability']) && (! is_string($profile['bindingCapability']) || ! ($this->composition->services['tenant:binding/default'] ?? null) instanceof BindingVerifier)) {
            throw new TenantFailure('unsupported_capability');
        }
        foreach ($selection->extensions as $extension) {
            if ($extension->required && ! ($this->composition->services['tenant:extension/'.$extension->type.'/'.$extension->version] ?? null) instanceof ExtensionDecoder) {
                throw new UnsupportedExtension;
            }
        }
        $resolved = $this->resolver->resolve($selection, $purpose);
        if ($selection->mode !== 'central' && is_string($profile['bindingCapability'] ?? null)) {
            try {
                $resolved->facets->require(new CapabilityId($profile['bindingCapability']));
            } catch (OutOfBoundsException $failure) {
                throw new TenantFailure('unsupported_capability', [$failure]);
            }
        }
        $context = $resolved->context->context;
        if ($selection->mode === 'central') {
            if ($resolved->context->kind !== 'central') {
                throw new BindingMismatch;
            }

            return $resolved;
        }
        if ($context === null || $context->mode !== $selection->mode || $selection->identity === null || $context->identity->namespace !== $selection->identity->namespace || $context->identity->id !== $selection->identity->id) {
            throw new BindingMismatch;
        }
        if ($context->profile->id !== $selection->expectedProfile->id || $context->profile->version !== $selection->expectedProfile->version) {
            throw new UnsupportedProfile;
        }
        if (! self::sameExtensions($context->extensions, $selection->extensions)) {
            throw new SelectionLost;
        }
        $expected = $selection->expectedBinding;
        if ($expected !== null) {
            if ($expected->resourceRef !== $context->binding->resourceRef || $expected->incarnation !== $context->binding->incarnation) {
                throw new StaleIncarnation;
            }
            if ($expected->routeRevision !== $context->binding->routeRevision) {
                throw new StaleRoute;
            }
        }

        return $resolved;
    }

    /**
     * @param  list<ExtensionRef>  $actual
     * @param  list<ExtensionRef>  $expected
     */
    private static function sameExtensions(array $actual, array $expected): bool
    {
        if (count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $index => $reference) {
            $other = $actual[$index];
            if ($other->type !== $reference->type || $other->version !== $reference->version || $other->required !== $reference->required) {
                return false;
            }
            $actualRefs = $other->refs;
            $expectedRefs = $reference->refs;
            // Maps have no insertion order semantics; opaque values never undergo numeric coercion.
            ksort($actualRefs, SORT_STRING);
            ksort($expectedRefs, SORT_STRING);
            if ($actualRefs !== $expectedRefs) {
                return false;
            }
        }

        return true;
    }

    private function verifyBinding(Selection $selection, ResolvedIsolation $resolved): void
    {
        if ($selection->mode === 'central') {
            return;
        }
        $profile = $this->composition->profiles[$selection->expectedProfile->id] ?? null;
        $capability = is_array($profile) ? ($profile['bindingCapability'] ?? null) : null;
        $verifier = $this->composition->services['tenant:binding/default'] ?? null;
        if ($capability === null && $verifier === null) {
            return;
        } // Trusted synthetic no-IO resolver profile.
        if (! is_string($capability) || ! $verifier instanceof BindingVerifier) {
            throw new TenantFailure('unsupported_capability');
        }
        $expected = $resolved->context->context->binding ?? throw new BindingMismatch;
        $proof = $verifier->verify(new BindingVerificationInput($expected, $resolved->facets->require(new CapabilityId($capability))));
        if ($proof->expected->resourceRef !== $expected->resourceRef || $proof->expected->incarnation !== $expected->incarnation || $proof->expected->routeRevision !== $expected->routeRevision) {
            throw new BindingMismatch;
        }
    }

    public function restore(FrameToken $token): void
    {
        $this->check();
        $frame = $this->frames[count($this->frames) - 1] ?? null;
        if ($frame === null || $frame->token !== $token) {
            throw new InvalidFrameToken;
        }
        if ($this->leases !== 0) {
            throw new OpenResource;
        }
        $this->transition = true;
        try {
            $this->cleanup($frame, null, true);
        } finally {
            $this->transition = false;
        }
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
                    'single' => Selection::single($s->identity ?? throw new BindingMismatch, $s->extensions, $binding),
                    default => Selection::tenant($s->identity ?? throw new BindingMismatch, $s->expectedProfile, $s->extensions, $binding),
                };
                $resolved = $this->resolve($pinned, $previous->purpose);
            }
        } catch (Throwable $failure) {
            $failures[] = $failure;
        }
        $restore = new TransitionContext($resolved->context ?? $frame->previous, $resolved->facets ?? new FacetBindings([]), $failures === []);
        try {
            foreach (array_reverse($frame->started) as [$facility, $state]) {
                try {
                    $facility->restore($restore, $state);
                } catch (Throwable $failure) {
                    $failures[] = $failure;
                }
                try {
                    $facility->verify($restore, $state);
                } catch (Throwable $failure) {
                    $failures[] = $failure;
                }
            }
        } finally {
            $restore->close();
        }
        if ($previous !== null && $resolved !== null) {
            try {
                $this->verifyBinding($previous->selection, $resolved);
                $phase = new TransitionContext($resolved->context, $resolved->facets);
                try {
                    foreach ($previous->selection->extensions as $extension) {
                        $decoder = $this->composition->services['tenant:extension/'.$extension->type.'/'.$extension->version] ?? null;
                        if ($decoder instanceof ExtensionDecoder) {
                            $decoder->validate($extension, $phase, $previous->purpose);
                        }
                    }
                } finally {
                    $phase->close();
                }
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }
        if ($failures !== []) {
            if ($original !== null) {
                array_unshift($failures, $original);
            }
            $fatal = new ContextRestoreFailed($failures);
            $this->fatal = $fatal;
            $this->generation++;
            $this->observe('restore_failed', 'failure', $frame, null, $fatal);
            $this->observe('poisoned', 'poisoned', $frame, null, $fatal);
            throw $fatal;
        }
        if ($pop) {
            array_pop($this->frames);
        }
        $this->publish($resolved->context ?? $frame->previous);
        $this->observe('restored', 'success', $frame, $this->published);
    }

    /** @param Closure(ContextState): mixed $callback */
    public function run(Selection $selection, Purpose $purpose, Closure $callback): mixed
    {
        $token = $this->enter($selection, $purpose);
        $frame = $this->frames[count($this->frames) - 1] ?? throw new InvalidFrameToken;
        $original = null;
        $result = null;
        try {
            $result = $callback($this->current());
        } catch (Throwable $failure) {
            $original = $failure;
            $this->transition = true;
            try {
                $this->observe('callback_failed', 'failure', $frame, $frame->resolved->context, $failure);
            } finally {
                $this->transition = false;
            }
        } finally {
            if ($this->fatal !== null) {
                if ($original !== null && $original !== $this->fatal) {
                    $causes = [$original, ...$this->fatal->causes()];
                    throw $this->fatal instanceof FrameLeak
                        ? new FrameLeak($causes)
                        : new ContextRestoreFailed($causes);
                }
                throw $this->fatal;
            }
            $top = $this->frames[count($this->frames) - 1] ?? null;
            if ($top?->token !== $token || $this->leases !== 0) {
                $leak = new FrameLeak($original === null ? [] : [$original]);
                $this->fatal = $leak;
                $this->generation++;
                $this->transition = true;
                try {
                    $this->observe('poisoned', 'poisoned', $frame, null, $this->fatal);
                } finally {
                    $this->transition = false;
                }
                throw $leak;
            }
            $this->transition = true;
            try {
                $this->cleanup($frame, $original, true);
            } finally {
                $this->transition = false;
            }
        }
        if ($original !== null) {
            throw $original;
        }

        return $result;
    }

    private function publish(ContextState $state): void
    {
        $this->published = $state;
        $this->generation++;
    }

    private function observe(string $kind, string $outcome, ?Frame $frame, ?ContextState $subject, ?Throwable $failure = null): void
    {
        $reason = $failure instanceof TenantFailure ? $failure->reason() : ($failure === null ? null : 'switch_failed');
        $causes = $failure instanceof TenantFailure ? array_map(static fn (Throwable $e): string => $e instanceof TenantFailure ? $e->reason() : 'switch_failed', $failure->causes()) : [];
        $observation = new RuntimeObservation(1, $kind, $outcome, $frame->correlation ?? bin2hex(random_bytes(16)), $subject, $reason, $causes);
        foreach ($this->observers as $id => $observer) {
            try {
                $observer->observe($observation);
            } catch (Throwable) {
                try {
                    $this->diagnostics?->report(new Diagnostic('observer_failed', (string) $id, $observation->frameCorrelation));
                } catch (Throwable) { /* Do not recursively diagnose a dead diagnostic transport. */
                }
            }
        }
    }
}
