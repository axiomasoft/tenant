<?php

declare(strict_types=1);

use Tenant\Contracts\Context\ContextCodec;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Context\ExtensionDecoder;
use Tenant\Contracts\Context\FacetAccess;
use Tenant\Contracts\Runtime\DiagnosticSink;
use Tenant\Contracts\Runtime\Facility;
use Tenant\Contracts\Runtime\FrameState;
use Tenant\Contracts\Runtime\ObservationSink;
use Tenant\Contracts\Runtime\ParticipantFactory;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Contracts\Runtime\RestoreContext;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Contracts\Runtime\TrackedStream;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Composition\ParticipantDeclaration;
use Tenant\Data\Context\ContextReference;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\ExtensionRef;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\FrozenDispatch;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Runtime\Diagnostic;
use Tenant\Data\Runtime\FrameToken;
use Tenant\Data\Runtime\RuntimeObservation;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Data\Verification\VerifiedBinding;
use Tenant\Errors\TenantProblemDefinitions;
use Tenant\Exceptions\Context\ContextDenied;
use Tenant\Exceptions\Context\ContextRestoreFailed;
use Tenant\Exceptions\Context\ContextSwitchFailed;
use Tenant\Exceptions\Context\ContextTransitionInProgress;
use Tenant\Exceptions\Context\ContextUnavailable;
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
use Tenant\Exceptions\Verification\BindingMismatch;
use Tenant\Exceptions\Verification\StaleIncarnation;
use Tenant\Exceptions\Verification\StaleRoute;
use Tenant\Internal\Validation\Validate;

(static function (): void {
    $contracts = 'Tenant\\Contracts\\';
    $data = 'Tenant\\Data\\';
    $exceptions = 'Tenant\\Exceptions\\';

    /** @var array<string, class-string> $aliases */
    $aliases = [
        $contracts.'ContextCodec' => ContextCodec::class,
        $contracts.'ContextResolver' => ContextResolver::class,
        $contracts.'ExtensionDecoder' => ExtensionDecoder::class,
        $contracts.'FacetAccess' => FacetAccess::class,
        $contracts.'DiagnosticSink' => DiagnosticSink::class,
        $contracts.'Facility' => Facility::class,
        $contracts.'FrameState' => FrameState::class,
        $contracts.'ObservationSink' => ObservationSink::class,
        $contracts.'ParticipantFactory' => ParticipantFactory::class,
        $contracts.'PhaseContext' => PhaseContext::class,
        $contracts.'RestoreContext' => RestoreContext::class,
        $contracts.'TenantRuntime' => TenantRuntime::class,
        $contracts.'TrackedStream' => TrackedStream::class,
        $contracts.'BindingVerifier' => BindingVerifier::class,
        $data.'CapabilityId' => CapabilityId::class,
        $data.'ParticipantDeclaration' => ParticipantDeclaration::class,
        $data.'ContextReference' => ContextReference::class,
        $data.'ContextState' => ContextState::class,
        $data.'ExtensionRef' => ExtensionRef::class,
        $data.'FacetBindings' => FacetBindings::class,
        $data.'FrozenDispatch' => FrozenDispatch::class,
        $data.'IsolationContext' => IsolationContext::class,
        $data.'IsolationIdentity' => IsolationIdentity::class,
        $data.'ProfileRef' => ProfileRef::class,
        $data.'Purpose' => Purpose::class,
        $data.'ResolvedIsolation' => ResolvedIsolation::class,
        $data.'Selection' => Selection::class,
        $data.'Diagnostic' => Diagnostic::class,
        $data.'FrameToken' => FrameToken::class,
        $data.'RuntimeObservation' => RuntimeObservation::class,
        $data.'BindingExpectation' => BindingExpectation::class,
        $data.'BindingVerificationInput' => BindingVerificationInput::class,
        $data.'VerifiedBinding' => VerifiedBinding::class,
        'Tenant\\Errors\\Internal\\'.'TenantProblemDefinitions' => TenantProblemDefinitions::class,
        $exceptions.'ContextDenied' => ContextDenied::class,
        $exceptions.'ContextRestoreFailed' => ContextRestoreFailed::class,
        $exceptions.'ContextSwitchFailed' => ContextSwitchFailed::class,
        $exceptions.'ContextTransitionInProgress' => ContextTransitionInProgress::class,
        $exceptions.'ContextUnavailable' => ContextUnavailable::class,
        $exceptions.'ScopeRequired' => ScopeRequired::class,
        $exceptions.'SelectionLost' => SelectionLost::class,
        $exceptions.'UnsupportedExtension' => UnsupportedExtension::class,
        $exceptions.'UnsupportedProfile' => UnsupportedProfile::class,
        $exceptions.'ConcurrentEntry' => ConcurrentEntry::class,
        $exceptions.'FrameLeak' => FrameLeak::class,
        $exceptions.'InvalidFrameToken' => InvalidFrameToken::class,
        $exceptions.'OpenResource' => OpenResource::class,
        $exceptions.'RuntimePoisoned' => RuntimePoisoned::class,
        $exceptions.'StaleHandle' => StaleHandle::class,
        $exceptions.'BindingMismatch' => BindingMismatch::class,
        $exceptions.'StaleIncarnation' => StaleIncarnation::class,
        $exceptions.'StaleRoute' => StaleRoute::class,
        'Tenant\\Internal\\'.'Validate' => Validate::class,
    ];

    foreach ($aliases as $legacy => $target) {
        if (! class_exists($legacy, false) && ! interface_exists($legacy, false)) {
            class_alias($target, $legacy);
        }
    }
})();
