<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Lifecycle;

use Closure;
use Packman\Attributes\Internal;
use Tenant\Exceptions\ArchiveUnverified;
use Tenant\Exceptions\Context\ContextUnavailable;
use Tenant\Exceptions\IdempotencyConflict;
use Tenant\Exceptions\OperationUnknown;
use Tenant\Exceptions\OriginalOperationMissing;
use Tenant\Exceptions\RecoveryUnsupported;
use Tenant\Exceptions\ResourceQuarantined;
use Tenant\Exceptions\RetentionHistoryMissing;
use Tenant\Exceptions\TenantFailure;
use Tenant\Exceptions\Verification\BindingMismatch;
use Tenant\Postgres\Exceptions\Claim\ClaimConflict;
use Tenant\Postgres\Exceptions\Claim\ClaimUnavailable;
use Tenant\Postgres\Exceptions\Provisioning\TemplateIntegrityException;
use Throwable;

/** No message parsing: only typed internal categories and the operation phase select a reason. */
#[Internal]
final class ErrorBoundary
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(Closure $work, string $phase): mixed
    {
        try {
            return $work();
        } catch (Throwable $failure) {
            foreach ([IdempotencyConflict::class, OperationUnknown::class, RecoveryUnsupported::class, ResourceQuarantined::class, ArchiveUnverified::class, RetentionHistoryMissing::class, OriginalOperationMissing::class] as $type) {
                if ($failure instanceof $type) {
                    throw $failure;
                }
            }
            if ($failure instanceof ClaimConflict) {
                throw new IdempotencyConflict([$failure]);
            }
            if ($failure instanceof TemplateIntegrityException) {
                throw new BindingMismatch([$failure]);
            }
            if ($failure instanceof ClaimUnavailable) {
                throw new ContextUnavailable([$failure]);
            }
            if ($failure instanceof TenantFailure) {
                throw $failure;
            }
            throw match ($phase) {
                'effect' => new OperationUnknown([$failure]),'recovery' => new RecoveryUnsupported([$failure]),default => new ContextUnavailable([$failure])
            };
        }
    }
}
