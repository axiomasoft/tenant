<?php

declare(strict_types=1);
namespace Tenant\Postgres;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\PostgresConnector;
use Tenant\Postgres\Internal\{AnchorHistory,LifecycleJournal,PhysicalFence};
use Tenant\Postgres\Exceptions\ClaimUnavailable;

/** Privileged operator control. Executes no pg_restore, CREATE, DROP, or automatic replay. */
final readonly class RecoveryOperator
{
    /** @param Closure(object):array $managementRoute Owner-only route, restricted to the catalog server. */
    public function __construct(private Connection $catalog, private LifecycleJournal $journal, private Closure $managementRoute, private string $runtimeRole) {}
    public function admit(RecoveryIntent $intent, Admission $admission): RecoveryPlan
    {
        $admission->assertAllowed($intent->purpose,$intent->targetResourceRef);$generation=$this->journal->assertHistory();
        $archive=$this->journal->verifiedArchiveFor($intent->archiveRef,$admission);
        if (!hash_equals($archive->sha256,$intent->archiveHash)) throw new ClaimUnavailable('archive_unverified');
        $target=$this->target($intent);
        if (($intent->kind==='restore') !== ($target->resource_ref===$archive->resourceRef)) throw new ClaimUnavailable('Restore keeps logical resource; clone requires a new resource.');
        return new RecoveryPlan($intent->digest(),$generation,$intent,$archive,['close admission and stop workers','exclude every old physical backend under target fences','retain original receipts/tombstones outside restored backup','advance independent recovery anchor before restoring control plane','restore archive into the frozen isolated target using the operator tool','verify original marker, stamp new incarnation and generation, verify through owner before reopening']);
    }
    /** Operator step before IO. All admissions close before a new global generation is committed. */
    public function quarantine(RecoveryPlan $plan, Admission $admission): string
    {
        $admission->assertAllowed($plan->intent->purpose,$plan->intent->targetResourceRef);
        if ($plan->intentDigest!==$plan->intent->digest() || $plan->externalGeneration!==$this->journal->assertHistory() || $this->admit($plan->intent,$admission)!=$plan) throw new ClaimUnavailable('Stale or altered recovery plan.');
        $this->target($plan->intent);
        $names=$this->catalog->table('tenant_pg_resources')->orderBy('database_name')->pluck('database_name')->all();
        return $this->fenced($names,0,function()use($plan):string {
            // An advisory fence alone is insufficient: exclude known live delayed backends.
            foreach (['tenant_pg_lifecycle_operations','tenant_pg_provision_attempts'] as $table) {
                if ($this->catalog->table($table)->whereIn('status',['accepted','running','unknown'])->exists()) throw new ClaimUnavailable('Original operation unresolved; reconcile before recovery.');
            }
            $this->catalog->transaction(fn()=>$this->catalog->table('tenant_pg_resources')->whereNull('deleted_at')->update(['status'=>'quarantined']));
            // A crash between anchor advancement and catalog update leaves admission fail-closed.
            $generation=$this->journal->recoveryAnchor()->advance($plan->externalGeneration);
            $this->catalog->table('tenant_pg_control')->where('id',1)->update(['recovery_generation'=>$generation]);
            return $generation;
        });
    }
    /** After operator restore, verifies the old archive marker, then stamps a replacement identity.
     * Caller retains original operation history. Other resources remain quarantined pending reconciliation. */
    public function complete(RecoveryPlan $plan, string $generation, Admission $admission): ResourceRecovery
    {
        $intent=$plan->intent;$admission->assertAllowed($intent->purpose,$intent->targetResourceRef);
        if ($plan->intentDigest!==$intent->digest() || $generation===$plan->externalGeneration || $generation!==$this->journal->assertHistory()) throw new ClaimUnavailable('Recovery generation not advanced.');
        $currentArchive=$this->journal->verifiedArchiveFor($intent->archiveRef,$admission);
        if ($currentArchive!=$plan->archive) throw new ClaimUnavailable('archive_unverified: original archive changed');
        return (new PhysicalFence($this->catalog))->run($intent->targetDatabase,function(Closure $fence)use($plan,$intent,$generation):ResourceRecovery {
            $row=$this->target($intent);
            if (!in_array($row->status,['deleted','quarantined','recovering'],true)) throw new ClaimUnavailable('Target admission must be closed.');
            $route=($this->managementRoute)($row);$central=$this->catalog->getConfig();
            if (($route['driver']??null)!=='pgsql' || ($route['database']??null)!==$row->database_name || ($route['host']??null)!==($central['host']??null) || (int)($route['port']??5432)!==(int)($central['port']??5432) || isset($route['url']) || isset($route['read']) || isset($route['write']) || !preg_match('/^[A-Za-z0-9_]{1,63}$/D',$this->runtimeRole)) throw new ClaimUnavailable('Recovery route mismatch.');
            $pdo=(new PostgresConnector)->connect($route);$fence();
            $marker=['protocol'=>'tenant.binding/1','owner_id'=>'tenant-postgres','resource_ref'=>$plan->archive->resourceRef,'incarnation'=>$plan->archive->incarnation,'recovery_generation'=>$plan->archive->recoveryGeneration];
            if ($pdo->query('SELECT protocol,owner_id,resource_ref,incarnation,recovery_generation FROM tenant_binding')->fetchAll(\PDO::FETCH_ASSOC)!==[$marker]) throw new ClaimUnavailable('Restored marker differs from original archive.');
            $incarnation=(string)\Illuminate\Support\Str::uuid7();$revision=(string)\Illuminate\Support\Str::uuid7();
            // Durable claim before the marker changes; partial completion stays quarantined.
            if ($this->catalog->table('tenant_pg_resources')->where('resource_ref',$row->resource_ref)->where('incarnation',$row->incarnation)->update(['status'=>'recovering','incarnation'=>$incarnation,'route_revision'=>$revision,'recovery_generation'=>$generation])!==1) throw new ClaimUnavailable('Recovery target changed.');
            $pdo->beginTransaction();
            try {
                $q=$pdo->prepare('UPDATE tenant_binding SET resource_ref=?,incarnation=?,recovery_generation=? WHERE resource_ref=? AND incarnation=? AND recovery_generation=?');
                $q->execute([$row->resource_ref,$incarnation,$generation,$marker['resource_ref'],$marker['incarnation'],$marker['recovery_generation']]);
                if ($q->rowCount()!==1) throw new ClaimUnavailable('Marker CAS failed.');
                $pdo->exec('REVOKE ALL ON tenant_binding FROM PUBLIC');$pdo->exec('REVOKE ALL ON tenant_binding FROM "'.$this->runtimeRole.'"');$pdo->exec('GRANT SELECT ON tenant_binding TO "'.$this->runtimeRole.'"');
                $fence();$pdo->commit();
            } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();throw $e; }
            $expected=['protocol'=>'tenant.binding/1','owner_id'=>'tenant-postgres','resource_ref'=>$row->resource_ref,'incarnation'=>$incarnation,'recovery_generation'=>$generation];
            if ($pdo->query('SELECT protocol,owner_id,resource_ref,incarnation,recovery_generation FROM tenant_binding')->fetchAll(\PDO::FETCH_ASSOC)!==[$expected]) throw new ClaimUnavailable('Replacement marker verification failed.');
            $fence();$this->journal->assertHistory();
            $this->catalog->table('tenant_pg_resources')->where('resource_ref',$row->resource_ref)->where('incarnation',$incarnation)->update(['status'=>'quarantined','deleted_at'=>null]);
            return new ResourceRecovery($row->resource_ref,$incarnation,$revision,$generation);
        });
    }
    private function target(RecoveryIntent $intent): object
    {
        $row=$this->catalog->table('tenant_pg_resources')->where('resource_ref',$intent->targetResourceRef)->first();
        if ($row===null || $row->incarnation!==$intent->targetIncarnation || $row->cluster_id!==$intent->targetClusterId || $row->database_name!==$intent->targetDatabase) throw new ClaimUnavailable('Frozen recovery target changed.');return $row;
    }
    private function fenced(array $names,int $offset,Closure $work): mixed
    {
        if ($offset===count($names)) return $work();
        return (new PhysicalFence($this->catalog))->run($names[$offset],fn()=>$this->fenced($names,$offset+1,$work));
    }
}
