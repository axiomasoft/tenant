<?php

declare(strict_types=1);

namespace Tenant\Errors\Enums;

use InvalidArgumentException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '1.0')]
enum TenantErrorCode: string implements ErrorCode
{
    case Missing = 'tenant.missing';
    case Denied = 'tenant.denied';
    case Unavailable = 'tenant.unavailable';
    case StaleIncarnation = 'tenant.stale_incarnation';
    case StaleRoute = 'tenant.stale_route';
    case BindingMismatch = 'tenant.binding_mismatch';
    case UnsupportedProfile = 'tenant.unsupported_profile';
    case UnsupportedVersion = 'tenant.unsupported_version';
    case UnsupportedExtension = 'tenant.unsupported_extension';
    case InvalidReference = 'tenant.invalid_reference';
    case UntrustedProducer = 'tenant.untrusted_producer';
    case SelectionLost = 'tenant.selection_lost';
    case SwitchFailed = 'tenant.switch_failed';
    case RestoreFailed = 'tenant.restore_failed';
    case RuntimePoisoned = 'tenant.runtime_poisoned';
    case TransitionInProgress = 'tenant.transition_in_progress';
    case InvalidFrameToken = 'tenant.invalid_frame_token';
    case ConcurrentEntry = 'tenant.concurrent_entry';
    case StaleHandle = 'tenant.stale_handle';
    case OpenResource = 'tenant.open_resource';
    case FrameLeak = 'tenant.frame_leak';
    case UnsupportedCapability = 'tenant.unsupported_capability';
    case FacilityConfiguration = 'tenant.facility_configuration';
    case DuplicateImplementation = 'tenant.duplicate_implementation';
    case ExclusiveSlotConflict = 'tenant.exclusive_slot_conflict';
    case ReplacementTargetMissing = 'tenant.replacement_target_missing';
    case ReplacementIncompatible = 'tenant.replacement_incompatible';
    case ReplacementConflict = 'tenant.replacement_conflict';
    case MissingDependency = 'tenant.missing_dependency';
    case AmbiguousCapability = 'tenant.ambiguous_capability';
    case DependencyCycle = 'tenant.dependency_cycle';
    case IncompatibleSpi = 'tenant.incompatible_spi';
    case CompositionFrozen = 'tenant.composition_frozen';
    case InvalidStageOrder = 'tenant.invalid_stage_order';
    case ObserverFailed = 'tenant.observer_failed';
    case DiagnosticFailed = 'tenant.diagnostic_failed';
    case IdentityEncodingUnsupported = 'tenant.identity_encoding_unsupported';
    case IdempotencyConflict = 'tenant.idempotency_conflict';
    case OperationUnknown = 'tenant.operation_unknown';
    case RecoveryUnsupported = 'tenant.recovery_unsupported';
    case ResourceQuarantined = 'tenant.resource_quarantined';
    case ArchiveUnverified = 'tenant.archive_unverified';
    case RetentionHistoryMissing = 'tenant.retention_history_missing';
    case OriginalOperationMissing = 'tenant.original_operation_missing';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }

    public static function fromReason(string $reason): self
    {
        return self::tryFrom('tenant.'.$reason)
            ?? throw new InvalidArgumentException(sprintf('Unknown tenant error reason "%s".', $reason));
    }

    public function category(): ErrorCategory
    {
        return match ($this) {
            self::Missing,
            self::Denied,
            self::UntrustedProducer => ErrorCategory::Authorization,
            self::Unavailable,
            self::OperationUnknown => ErrorCategory::Unavailable,
            self::StaleIncarnation,
            self::StaleRoute,
            self::BindingMismatch,
            self::TransitionInProgress,
            self::ConcurrentEntry,
            self::StaleHandle,
            self::OpenResource,
            self::DuplicateImplementation,
            self::ExclusiveSlotConflict,
            self::ReplacementIncompatible,
            self::ReplacementConflict,
            self::AmbiguousCapability,
            self::DependencyCycle,
            self::CompositionFrozen,
            self::IdempotencyConflict,
            self::ResourceQuarantined,
            self::ArchiveUnverified => ErrorCategory::Conflict,
            self::UnsupportedProfile,
            self::UnsupportedVersion,
            self::UnsupportedExtension,
            self::UnsupportedCapability,
            self::IncompatibleSpi,
            self::IdentityEncodingUnsupported,
            self::RecoveryUnsupported => ErrorCategory::Unsupported,
            self::InvalidReference,
            self::InvalidFrameToken,
            self::MissingDependency,
            self::InvalidStageOrder => ErrorCategory::Validation,
            self::SelectionLost,
            self::ReplacementTargetMissing,
            self::RetentionHistoryMissing,
            self::OriginalOperationMissing => ErrorCategory::NotFound,
            self::SwitchFailed,
            self::RestoreFailed,
            self::RuntimePoisoned,
            self::FrameLeak,
            self::FacilityConfiguration,
            self::ObserverFailed,
            self::DiagnosticFailed => ErrorCategory::Internal,
        };
    }

    public function defaultStatus(): int
    {
        return match ($this->category()) {
            ErrorCategory::Authorization => 403,
            ErrorCategory::NotFound => 404,
            ErrorCategory::Conflict => 409,
            ErrorCategory::Unsupported,
            ErrorCategory::Validation => 422,
            ErrorCategory::Unavailable => 503,
            default => 500,
        };
    }

    public function isRetryable(): bool
    {
        return match ($this) {
            self::Unavailable,
            self::StaleRoute,
            self::TransitionInProgress,
            self::ConcurrentEntry,
            self::OpenResource => true,
            default => false,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Missing => 'Tenant isolation scope is required.',
            self::Denied => 'Tenant isolation context denied.',
            self::Unavailable => 'Tenant isolation resource is unavailable.',
            self::StaleIncarnation => 'Tenant resource incarnation is stale.',
            self::StaleRoute => 'Tenant route revision is stale.',
            self::BindingMismatch => 'Tenant binding proof mismatch.',
            self::UnsupportedProfile => 'Unsupported isolation profile.',
            self::UnsupportedVersion => 'Unsupported tenant context wire version.',
            self::UnsupportedExtension => 'Unsupported tenant context extension.',
            self::InvalidReference => 'Invalid tenant context reference.',
            self::UntrustedProducer => 'Untrusted tenant context producer.',
            self::SelectionLost => 'Selected product reference is lost.',
            self::SwitchFailed => 'Tenant context switch failed.',
            self::RestoreFailed => 'Tenant context restore failed.',
            self::RuntimePoisoned => 'Tenant runtime is poisoned.',
            self::TransitionInProgress => 'Tenant context transition is in progress.',
            self::InvalidFrameToken => 'Invalid tenant frame token.',
            self::ConcurrentEntry => 'Concurrent tenant runtime entry refused.',
            self::StaleHandle => 'Tenant facility handle is stale.',
            self::OpenResource => 'Open tracked resource blocks transition.',
            self::FrameLeak => 'Unclosed tenant frame or stream leaked.',
            self::UnsupportedCapability => 'Unsupported tenant capability.',
            self::FacilityConfiguration => 'Invalid tenant facility configuration.',
            self::DuplicateImplementation => 'Duplicate participant implementation identifier.',
            self::ExclusiveSlotConflict => 'Exclusive composition slot conflict.',
            self::ReplacementTargetMissing => 'Composition replacement target is missing.',
            self::ReplacementIncompatible => 'Composition replacement is incompatible.',
            self::ReplacementConflict => 'Conflicting composition replacements.',
            self::MissingDependency => 'Missing required composition dependency.',
            self::AmbiguousCapability => 'Ambiguous capability provider.',
            self::DependencyCycle => 'Composition dependency cycle detected.',
            self::IncompatibleSpi => 'Incompatible tenant SPI version.',
            self::CompositionFrozen => 'Tenant composition is frozen.',
            self::InvalidStageOrder => 'Invalid composition stage ordering.',
            self::ObserverFailed => 'Tenant runtime observer failed.',
            self::DiagnosticFailed => 'Tenant diagnostic sink failed.',
            self::IdentityEncodingUnsupported => 'Unsupported tenant identity encoding.',
            self::IdempotencyConflict => 'Operation idempotency conflict.',
            self::OperationUnknown => 'Physical operation outcome is unknown.',
            self::RecoveryUnsupported => 'Physical recovery is unsupported.',
            self::ResourceQuarantined => 'Physical resource is quarantined.',
            self::ArchiveUnverified => 'Archive integrity verification failed.',
            self::RetentionHistoryMissing => 'Retention operation history is missing.',
            self::OriginalOperationMissing => 'Original operation receipt is missing.',
        };
    }
}
