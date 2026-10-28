<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Tenant\Postgres\{Admission,OperationKey,OperationReceipt,ProvisionIntent};use Tenant\Postgres\Data\{ClaimRequest,ResourceSnapshot};
use Tenant\Postgres\Exceptions\ClaimUnavailable;
use Tenant\Postgres\Internal\Database\TemplateIntegrityVerifier;

/** Allocation is durable before physical creation; only verified clones become pending. */
final readonly class PendingPool
{
    /** @param Closure(Closure, ResourceSnapshot): \Tenant\Postgres\Internal\Database\TemplateClonePostgreSQLManager $clonerFactory
     * @param Closure(ResourceSnapshot): void $verifyBinding */
    public function __construct(private Connection $connection, private TemplateIntegrityVerifier $integrity, private ProvisionJournal $journal, private Closure $clonerFactory, private Closure $verifyBinding, private Closure $databaseNameFor, private ?\Tenant\Postgres\Contracts\RecoveryAnchor $anchor=null) {}

    public function leastLoadedCluster(int $templateVersion): ?string
    {
        $r=$this->connection->table('tenant_pg_clusters')->where('status','active')->where('template_version',$templateVersion)->whereColumn('database_count','<','capacity')->orderBy('database_count')->orderBy('id')->first(['id']);
        return $r?->id;
    }

    public function fill(ProvisionIntent $intent, OperationKey $key, Admission $admission): OperationReceipt
    {
        if(! $this->journal->usesConnection($this->connection))throw new ClaimUnavailable('Pool and journal must share the catalog connection.');
        $admission->assertAllowed('provision',$intent->hostIntentRef);
        $pair=$this->connection->transaction(function()use($intent,$key,$admission):array{
            $hash=$key->storageKey();OperationKeys::lock($this->connection,$key,false);
            $old=$this->connection->table('tenant_pg_provision_attempts')->where('key_hash',$hash)->lockForUpdate()->first();
            if($old!==null){
                $snapshot=new ResourceSnapshot($old->resource_ref,$old->incarnation,(int)$old->template_version,$old->cluster_id,$old->database_name);
                return [$this->journal->register($intent,$key,$snapshot,$old->template_database,$admission),$snapshot];
            }
            $q=$this->integrity->qualify(new ClaimRequest($hash,$intent->clusterId,$intent->templateId,$intent->digest()));
            if($q->templateVersion!==$intent->templateVersion||!hash_equals($q->artifactDigest,$intent->manifestHash))throw new ClaimUnavailable('Frozen template proof changed.');
            $cluster=$this->connection->table('tenant_pg_clusters')->where('id',$intent->clusterId)->where('status','active')->where('template_version',$intent->templateVersion)->lockForUpdate()->first();
            $template=$this->connection->table('tenant_pg_templates')->where('id',$intent->templateId)->where('version',$intent->templateVersion)->where('status','ready')->lockForUpdate()->first();
            if($cluster===null||$template===null||!is_string($template->db_name)||(int)$cluster->database_count >= (int)$cluster->capacity)throw new ClaimUnavailable('Cluster or template is unavailable.');
            $id=(string)Str::uuid7();$database=($this->databaseNameFor)($id);
            if(!is_string($database)||preg_match('/^[A-Za-z0-9_]{1,63}$/D',$database)!==1)throw new ClaimUnavailable('Invalid allocated database name.');
            $snapshot=new ResourceSnapshot('pg:'.$id,(string)Str::uuid7(),$intent->templateVersion,$intent->clusterId,$database);
            $this->connection->table('tenant_pg_resources')->insert(['id'=>$id,'cluster_id'=>$intent->clusterId,'template_version'=>$intent->templateVersion,'status'=>'provisioning','resource_ref'=>$snapshot->resourceRef,'incarnation'=>$snapshot->incarnation,'database_name'=>$snapshot->databaseName,'route_revision'=>'1','recovery_generation'=>$this->anchor===null?(string)Str::uuid7():AnchorHistory::assertCurrent($this->connection,$this->anchor)]);
            $this->connection->table('tenant_pg_clusters')->where('id',$intent->clusterId)->increment('database_count');
            return [$this->journal->register($intent,$key,$snapshot,$template->db_name,$admission),$snapshot];
        });
        [$receipt,$snapshot]=$pair;
        if($receipt->status==='accepted')$receipt=$this->journal->execute($receipt->operationId,$admission,fn(Closure $fence)=>($this->clonerFactory)($fence,$snapshot));
        if($receipt->status==='succeeded'){
            ($this->verifyBinding)($snapshot);
            $this->connection->table('tenant_pg_resources')->where('resource_ref',$snapshot->resourceRef)->where('incarnation',$snapshot->incarnation)->where('status','provisioning')->update(['status'=>'pending']);
        }
        return $receipt;
    }
}
