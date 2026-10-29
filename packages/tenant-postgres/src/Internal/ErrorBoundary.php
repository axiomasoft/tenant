<?php

declare(strict_types=1);
namespace Tenant\Postgres\Internal;
use Closure;
use Throwable;
use Tenant\Exceptions\{TenantFailure,IdempotencyConflict,OperationUnknown,RecoveryUnsupported,ResourceQuarantined,ArchiveUnverified,RetentionHistoryMissing,OriginalOperationMissing,ContextUnavailable,BindingMismatch};
use Tenant\Postgres\Exceptions\{ClaimConflict,ClaimUnavailable,TemplateIntegrityException};

/** No message parsing: only typed internal categories and the operation phase select a reason. */
final class ErrorBoundary
{
    public static function run(Closure $work,string $phase):mixed
    {
        try{return $work();}
        catch(Throwable $failure){
            foreach([IdempotencyConflict::class,OperationUnknown::class,RecoveryUnsupported::class,ResourceQuarantined::class,ArchiveUnverified::class,RetentionHistoryMissing::class,OriginalOperationMissing::class] as $type)if($failure instanceof $type)throw $failure;
            if($failure instanceof ClaimConflict)throw new IdempotencyConflict([$failure]);
            if($failure instanceof TemplateIntegrityException)throw new BindingMismatch([$failure]);
            if($failure instanceof ClaimUnavailable)throw new ContextUnavailable([$failure]);
            if($failure instanceof TenantFailure)throw $failure;
            throw match($phase){'effect'=>new OperationUnknown([$failure]),'recovery'=>new RecoveryUnsupported([$failure]),default=>new ContextUnavailable([$failure])};
        }
    }
}
