<?php

declare(strict_types=1);
namespace Tenant\Postgres;
use Tenant\Postgres\Internal\CanonicalIntent;
/** V1 plans operator steps only. Target route comes from the owning catalog. */
final readonly class RecoveryIntent
{
    public function __construct(public string $kind, public ExportRef $archiveRef, public string $archiveHash, public string $targetResourceRef, public string $targetIncarnation, public string $targetClusterId, public string $targetDatabase, public string $purpose)
    {
        if (!in_array($kind,['restore','clone'],true) || !preg_match('/^[a-f0-9]{64}$/D',$archiveHash) || !preg_match('/^[A-Za-z0-9_]{1,63}$/D',$targetDatabase)) throw new \InvalidArgumentException('Invalid recovery intent.');
        foreach ([$targetResourceRef,$targetIncarnation,$targetClusterId,$purpose] as $ref) CanonicalIntent::reference($ref);
    }
    public function digest(): string { return CanonicalIntent::digest(['schemaVersion'=>1,'operationKind'=>$this->kind,'archiveRef'=>['exportId'=>$this->archiveRef->exportId,'operation'=>get_object_vars($this->archiveRef->operation)],'archiveHash'=>$this->archiveHash,'targetResourceRef'=>$this->targetResourceRef,'targetIncarnation'=>$this->targetIncarnation,'targetClusterId'=>$this->targetClusterId,'targetDatabase'=>$this->targetDatabase,'purpose'=>$this->purpose]); }
}
