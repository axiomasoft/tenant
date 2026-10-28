<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Tenant\Postgres\{Admission,OperationKey,OperationReceipt,ProvisionIntent};use Tenant\Postgres\Data\{ClaimRequest,ResourceSnapshot};
use Tenant\Postgres\Internal\Database\TemplateIntegrityVerifier;
use Tenant\Postgres\Exceptions\{ClaimConflict,ClaimUnavailable};
use Tenant\Postgres\Internal\Database\TemplateClonePostgreSQLManager;

/** A single original provision attempt; unknown DDL is never automatically repeated. */
final readonly class ProvisionJournal
{
    /** @param Closure(ResourceSnapshot): void $verifyBinding */
    public function __construct(private Connection $connection, private TemplateIntegrityVerifier $integrity, private Closure $verifyBinding) {}

    public function register(ProvisionIntent $intent, OperationKey $key, ResourceSnapshot $snapshot, string $templateDatabase, Admission $admission): OperationReceipt
    {
        $admission->assertAllowed('provision',$intent->hostIntentRef);
        if($snapshot->clusterId!==$intent->clusterId || $snapshot->templateVersion!==$intent->templateVersion)throw new ClaimConflict('Snapshot differs from intent.');
        foreach([$snapshot->databaseName,$templateDatabase] as $identifier)if(preg_match('/^[A-Za-z0-9_]{1,63}$/D',$identifier)!==1)throw new ClaimConflict('Invalid database identifier.');
        $keyHash=$key->storageKey();$digest=$intent->digest();
        return $this->connection->transaction(function()use($intent,$key,$snapshot,$templateDatabase,$keyHash,$digest):OperationReceipt{
            $this->connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',['tenant:provision-key:'.$keyHash]);
            $r=$this->connection->table('tenant_pg_provision_attempts')->where('key_hash',$keyHash)->lockForUpdate()->first();
            if($r!==null){
                if($r->key_namespace!==$key->namespace||$r->operation_key!==$key->key||!hash_equals($r->intent_digest,$digest)||$r->resource_ref!==$snapshot->resourceRef||$r->incarnation!==$snapshot->incarnation||$r->database_name!==$snapshot->databaseName||$r->template_database!==$templateDatabase)throw new ClaimConflict('Original provision target differs.');
                return $this->receipt($r);
            }
            $r=['id'=>(string)Str::uuid7(),'key_hash'=>$keyHash,'key_namespace'=>$key->namespace,'operation_key'=>$key->key,'intent_digest'=>$digest,'host_intent_ref'=>$intent->hostIntentRef,'resource_ref'=>$snapshot->resourceRef,'incarnation'=>$snapshot->incarnation,'cluster_id'=>$intent->clusterId,'template_id'=>$intent->templateId,'template_version'=>$intent->templateVersion,'manifest_hash'=>$intent->manifestHash,'template_database'=>$templateDatabase,'database_name'=>$snapshot->databaseName,'status'=>'accepted','attempt_generation'=>0,'receipt_version'=>1,'observed_effect'=>'none','cancel_requested'=>false];
            $this->connection->table('tenant_pg_provision_attempts')->insert($r);return $this->receipt((object)$r);
        });
    }

    /** @param Closure(Closure): TemplateClonePostgreSQLManager $clonerFactory Receives the session-fence assertion. */
    public function execute(string $id, Admission $admission, Closure $clonerFactory): OperationReceipt
    {
        $row=$this->find($id);$admission->assertAllowed('provision',$row->host_intent_ref);
        return $this->withFence($row,function(Closure $fence)use($id,$row,$clonerFactory):OperationReceipt{
            $fence();$this->verifyTemplate($row);$fence();$updated=$this->connection->table('tenant_pg_provision_attempts')->where('id',$id)->where('status','accepted')->update(['status'=>'running','attempt_generation'=>1,'receipt_version'=>2]);
            if($updated!==1)throw new ClaimUnavailable('Attempt already submitted; reconcile original operation.');
            try {
                $cloner=$clonerFactory($fence);if(! $cloner instanceof TemplateClonePostgreSQLManager || !$cloner->usesConnection($this->connection) || !$cloner->usesFence($fence))throw new ClaimUnavailable('DDL session mismatch.');
                $cloner->createOrVerify($this->snapshot($row),$row->template_database);$fence();($this->verifyBinding)($this->snapshot($row));$fence();
                $this->connection->transaction(function()use($id):void{
                    $current=$this->connection->table('tenant_pg_provision_attempts')->where('id',$id)->lockForUpdate()->first();
                    if($current===null || $current->status!=='running' || (int)$current->attempt_generation!==1)throw new ClaimUnavailable('Attempt changed before publication.');
                    $cancel=(bool)$current->cancel_requested;
                    $this->connection->table('tenant_pg_provision_attempts')->where('id',$id)->update(['status'=>$cancel?'cancelled':'succeeded','observed_effect'=>$cancel?'quarantined':'verified','receipt_version'=>(int)$current->receipt_version+1]);
                });
            } catch(\Throwable $failure) {
                // If the connection disappeared, a running row itself means unknown.
                try{$this->connection->table('tenant_pg_provision_attempts')->where('id',$id)->where('status','running')->where('attempt_generation',1)->update(['status'=>'unknown','receipt_version'=>3]);}catch(\Throwable){}
                throw $failure;
            }
            return $this->receipt($this->find($id));
        });
    }

    public function assertReference(string $namespace,string $id,string $resourceRef,string $incarnation,Admission $admission,string $purpose): void
    {
        $row=$this->find($id);$admission->assertAllowed($purpose,$row->host_intent_ref);
        if($row->key_namespace!==$namespace||$row->resource_ref!==$resourceRef||$row->incarnation!==$incarnation)throw new ClaimConflict('Operation reference does not match the original.');
    }

    public function query(string $id, Admission $admission): OperationReceipt
    {
        $row=$this->find($id);$admission->assertAllowed('query',$row->host_intent_ref);
        if($row->status==='succeeded'){
            $this->verifyTemplate($row);$this->integrity->verifyAccount($this->snapshot($row));($this->verifyBinding)($this->snapshot($row));
        }
        return $this->receipt($row);
    }

    /** A cancellation request is durable even while a physical backend holds the fence. */
    public function cancel(string $id, Admission $admission): OperationReceipt
    {
        $row=$this->find($id);$admission->assertAllowed('cancel',$row->host_intent_ref);
        return $this->connection->transaction(function()use($id):OperationReceipt{
            $row=$this->connection->table('tenant_pg_provision_attempts')->where('id',$id)->lockForUpdate()->first();
            if($row===null)throw new ClaimUnavailable('Operation not found.');
            if($row->status==='cancelled'||(bool)$row->cancel_requested)return $this->receipt($row);
            if($row->status==='succeeded')throw new ClaimUnavailable('Completed operation cannot be cancelled.');
            if(!in_array($row->status,['accepted','running','unknown'],true))throw new ClaimUnavailable('Operation cannot be cancelled.');
            $this->connection->table('tenant_pg_provision_attempts')->where('id',$id)->update(['status'=>$row->status==='accepted'?'cancelled':$row->status,'cancel_requested'=>true,'receipt_version'=>(int)$row->receipt_version+1]);
            return $this->receipt($this->find($id));
        });
    }

    /** Only verifies an existing original resource. Missing or unverified effects remain unknown. */
    public function reconcile(string $id, Admission $admission): OperationReceipt
    {
        $row=$this->find($id);$admission->assertAllowed('reconcile',$row->host_intent_ref);
        return $this->withFence($row,function(Closure $fence)use($id):OperationReceipt{
            $r=$this->find($id);if($r->status==='succeeded'){$fence();$this->verifyTemplate($r);$this->integrity->verifyAccount($this->snapshot($r));($this->verifyBinding)($this->snapshot($r));$fence();return $this->receipt($r);}
            if(!in_array($r->status,['running','unknown'],true))throw new ClaimUnavailable('No submitted attempt to reconcile.');
            try{$fence();$this->verifyTemplate($r);$this->integrity->verifyAccount($this->snapshot($r));($this->verifyBinding)($this->snapshot($r));$fence();}
            catch(\Throwable){$this->connection->table('tenant_pg_provision_attempts')->where('id',$id)->whereIn('status',['running','unknown'])->update(['status'=>'unknown']);return $this->receipt($this->find($id));}
            $this->connection->transaction(function()use($id,$r):void{
                $current=$this->connection->table('tenant_pg_provision_attempts')->where('id',$id)->lockForUpdate()->first();
                if($current===null||!in_array($current->status,['running','unknown'],true)||(int)$current->attempt_generation!==(int)$r->attempt_generation)throw new ClaimUnavailable('Attempt changed during reconciliation.');
                $cancel=(bool)$current->cancel_requested;
                $this->connection->table('tenant_pg_provision_attempts')->where('id',$id)->update(['status'=>$cancel?'cancelled':'succeeded','observed_effect'=>$cancel?'quarantined':'verified','receipt_version'=>(int)$current->receipt_version+1]);
            });
            return $this->receipt($this->find($id));
        });
    }

    private function withFence(object $row, Closure $work): mixed
    {
        if($this->connection->transactionLevel()!==0)throw new ClaimUnavailable('Physical operation requires a nontransactional session.');
        $pdo=$this->connection->getPdo();$pid=$this->connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;$key='tenant:physical:'.$row->database_name;
        $acquired=$this->connection->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS locked',[$key])->locked;
        if(!in_array($acquired,[true,'t',1],true))throw new ClaimUnavailable('Physical target is fenced by another backend.');
        $fence=function()use($pdo,$pid,$key):void{
            if($this->connection->getPdo()!==$pdo || $this->connection->selectOne('SELECT pg_backend_pid() AS pid')->pid!==$pid)throw new ClaimUnavailable('Physical session lost; outcome unknown.');
            $held=$this->connection->selectOne("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid() AND granted AND objsubid=1 AND classid=((hashtextextended(?,0) >> 32) & 4294967295)::oid AND objid=(hashtextextended(?,0) & 4294967295)::oid) AS held",[$key,$key])->held;
            if(!in_array($held,[true,'t',1],true))throw new ClaimUnavailable('Physical fence lost; outcome unknown.');
        };
        try{return $work($fence);}finally{try{if($this->connection->getPdo()===$pdo)$this->connection->select('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$key]);}catch(\Throwable){}}
    }
    public function usesConnection(Connection $connection): bool { return $this->connection === $connection; }

    private function verifyTemplate(object $r): void
    {
        $q=$this->integrity->qualify(new ClaimRequest('reconcile',$r->cluster_id,$r->template_id,$r->intent_digest));
        if($q->templateVersion!==(int)$r->template_version || !hash_equals($r->manifest_hash,$q->artifactDigest))throw new ClaimUnavailable('Frozen template proof changed.');
    }
    private function find(string $id):object{return $this->connection->table('tenant_pg_provision_attempts')->where('id',$id)->first() ?? throw new ClaimUnavailable('Operation not found.');}
    private function snapshot(object $r):ResourceSnapshot{return new ResourceSnapshot($r->resource_ref,$r->incarnation,(int)$r->template_version,$r->cluster_id,$r->database_name);}
    private function receipt(object $r):OperationReceipt{return new OperationReceipt($r->id,$r->intent_digest,$r->resource_ref,$r->incarnation,(int)$r->attempt_generation,$r->status,(int)$r->receipt_version,$r->observed_effect,(bool)$r->cancel_requested,new OperationKey($r->key_namespace,$r->operation_key));}
}
