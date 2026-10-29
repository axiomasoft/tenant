<?php

declare(strict_types=1);
namespace Tenant\Postgres\Internal;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Tenant\Postgres\{Admission,ArchiveManifest,DeleteIntent,ExportIntent,ExportReceipt,ExportRef,OperationKey,OperationReceipt,OperationRef};
use Tenant\Postgres\Contracts\{ArchiveStore,RecoveryAnchor};
use Tenant\Postgres\Exceptions\{ClaimConflict,ClaimUnavailable};

/** CoreX export/verify/DROP sequence adapted to original receipts and pinned DDL fencing. */
final readonly class LifecycleJournal
{
    /** @param Closure(object):void $verifyBinding Exact marker/role verification through the owner.
     * @param ?Closure(string,object,object):bool $policy Trusted retention/maintenance policy, no default entitlement.
     * @param ?Closure(string,object):void $checkpoint Fault observation seam; never an effect executor. */
    public function __construct(private Connection $connection, private Closure $verifyBinding, private ?ArchiveStore $archives=null, private ?RecoveryAnchor $anchor=null, private ?Closure $policy=null, private ?Closure $checkpoint=null) {}

    public function request(ExportIntent|DeleteIntent $intent, OperationKey $key, Admission $admission): OperationReceipt
    {
        $kind=$intent instanceof ExportIntent?'export':'delete';$admission->assertAllowed($kind,$intent->resourceRef);
        $this->configured();
        $receipt=$this->register($intent,$key,$admission,$kind);
        if ($receipt->status !== 'accepted') return $this->query($this->reference($receipt),$admission);
        return $this->execute($receipt->operationId,$admission);
    }
    public function hasOperation(string $id): bool { return $this->table()->where('id',$id)->exists(); }
    public function query(OperationRef $ref, Admission $admission): OperationReceipt
    {
        $r=$this->referenced($ref,$admission,'query');$this->history();
        $this->resource($r,allowDeleted:$r->kind==='delete' && in_array($r->status,['succeeded','cancelled'],true));
        if ($r->kind==='export' && $r->status==='succeeded') $this->verifiedArchive($r);
        return $this->receipt($r);
    }
    public function queryExport(ExportRef $ref, Admission $admission): ExportReceipt
    {
        $r=$this->referenced($ref->operation,$admission,'query');$this->history();
        if ($r->kind!=='export' || $r->export_id!==$ref->exportId) throw new \Tenant\Exceptions\OriginalOperationMissing('Original export reference mismatch.');
        $manifest=$r->status==='succeeded'?$this->verifiedArchive($r):null;
        return new ExportReceipt($ref,$r->status==='succeeded'?'ready':$r->status,$manifest);
    }
    public function cancel(OperationRef $ref, Admission $admission): OperationReceipt
    {
        $row=$this->referenced($ref,$admission,'cancel');$this->history();
        return $this->connection->transaction(function()use($row):OperationReceipt {
            $r=$this->table()->where('id',$row->id)->lockForUpdate()->first();
            if ($r->status==='succeeded') throw new ClaimUnavailable('Completed operation cannot be cancelled.');
            if ($r->cancel_requested) return $this->receipt($r);
            $this->table()->where('id',$r->id)->update(['cancel_requested'=>true,'status'=>$r->status==='accepted'?'cancelled':$r->status,'receipt_version'=>(int)$r->receipt_version+1]);
            return $this->receipt($this->find($r->id));
        });
    }
    public function reconcile(OperationRef $ref, Admission $admission): OperationReceipt
    {
        $r=$this->referenced($ref,$admission,'reconcile');$this->configured();$this->history();
        return (new PhysicalFence($this->connection))->run($r->database_name,function(Closure $fence)use($r,$ref,$admission):OperationReceipt {
            $r=$this->referenced($ref,$admission,'reconcile');$this->guard($r,$fence,allowDeleted:true);
            if (in_array($r->status,['succeeded','cancelled'],true)) return $this->query($ref,$admission);
            if (!in_array($r->status,['running','unknown'],true)) throw new ClaimUnavailable('Only an original submitted attempt can be reconciled.');
            // A backend may release its advisory lock while a delayed command is still running.
            if ($this->connection->selectOne('SELECT pid FROM pg_stat_activity WHERE pid=? AND backend_start::text=?',[$r->backend_pid,$r->backend_start])!==null) throw new \Tenant\Exceptions\ResourceQuarantined('Original physical backend still exists; quarantine until excluded.');
            if ($r->kind==='export') {
                $manifest=$this->archives->recover($r->export_id);
                if ($manifest===null) return $this->unknown($r);
                $this->assertArchive($r,$manifest);$fence();return $this->publish($r,$manifest,'archived');
            }
            // Absence is accepted only after the original attempt was submitted and old backend excluded.
            if ($this->database($r->database_name)!==null) return $this->unknown($r);
            $this->verifiedDeleteArchive($r);$this->assertPolicy($r);$fence();return $this->publish($r,null,'dropped');
        });
    }
    public function verifiedArchiveFor(ExportRef $ref, Admission $admission): ArchiveManifest
    {
        $receipt=$this->queryExport($ref,$admission);
        return $receipt->manifest ?? throw new \Tenant\Exceptions\ArchiveUnverified('archive_unverified: original export is not ready');
    }
    public function assertHistory(): string { return $this->history(); }
    public function recoveryAnchor(): RecoveryAnchor { return $this->anchor ?? throw new \Tenant\Exceptions\RecoveryUnsupported('recovery_unsupported: external anchor required'); }

    private function register(ExportIntent|DeleteIntent $intent, OperationKey $key, Admission $admission, string $kind): OperationReceipt
    {
        return $this->connection->transaction(function()use($intent,$key,$admission,$kind):OperationReceipt {
            OperationKeys::lock($this->connection,$key,true);$generation=$this->history();
            $old=$this->table()->where('key_hash',$key->storageKey())->lockForUpdate()->first();
            if ($old!==null) {
                if ($old->key_namespace!==$key->namespace || $old->operation_key!==$key->key || !hash_equals($old->input_digest,$intent->digest()) || $old->kind!==$kind) throw new ClaimConflict('Original operation intent differs, including after tombstone.');
                $admission->assertAllowed($kind,$old->resource_ref);return $this->receipt($old);
            }
            $resource=$this->connection->table('tenant_pg_resources')->where('resource_ref',$intent->resourceRef)->lockForUpdate()->first();
            if ($resource===null || $resource->deleted_at!==null) throw new \Tenant\Exceptions\ContextUnavailable();
            if ($resource->incarnation!==$intent->incarnation) throw new \Tenant\Exceptions\StaleIncarnation();
            if (!in_array($resource->status,['ready','active','exported','exporting'],true) || $resource->recovery_generation!==$generation) throw new \Tenant\Exceptions\ResourceQuarantined();
            ($this->verifyBinding)($resource);
            if ($this->table()->where('resource_ref',$resource->resource_ref)->whereIn('status',['accepted','running','unknown'])->exists()) throw new \Tenant\Exceptions\ResourceQuarantined('Original target has an unresolved operation.');
            if (($this->policy)($kind,$intent,$resource)!==true) throw new \Tenant\Exceptions\RetentionHistoryMissing('retention_history_missing: host policy refuses operation');
            $r=['id'=>(string)Str::uuid7(),'key_hash'=>$key->storageKey(),'key_namespace'=>$key->namespace,'operation_key'=>$key->key,'input_digest'=>$intent->digest(),'kind'=>$kind,'purpose_ref'=>$kind==='delete'?$intent->policyIntentRef:$intent->retentionProfileRef,
                'resource_ref'=>$resource->resource_ref,'incarnation'=>$resource->incarnation,'cluster_id'=>$resource->cluster_id,'database_name'=>$resource->database_name,'route_revision'=>$resource->route_revision,'recovery_generation'=>$generation,
                'status'=>'accepted','attempt_generation'=>0,'receipt_version'=>1,'observed_effect'=>'none','cancel_requested'=>false,'export_id'=>$kind==='export'?(string)Str::uuid7():null,'export_ref'=>$kind==='delete' && $intent->exportRef!==null?json_encode(['exportId'=>$intent->exportRef->exportId,'operation'=>get_object_vars($intent->exportRef->operation)],JSON_THROW_ON_ERROR):null,
                'destination_ref'=>$kind==='export'?$intent->destinationRef:null,'retention_ref'=>$kind==='export'?$intent->retentionProfileRef:null,'manifest'=>null,'backend_pid'=>null,'backend_start'=>null,'database_oid'=>null];
            $r['intent_digest']=CanonicalIntent::digest(['inputDigest'=>$r['input_digest'],'clusterBinding'=>$r['cluster_id'],'database'=>$r['database_name'],'routeRevision'=>$r['route_revision'],'recoveryGeneration'=>$generation]);
            if ($kind==='delete') $this->verifiedDeleteArchive((object)$r);
            // Anchor is write-ahead: an interrupted registration leaves an orphan that fails closed.
            $this->anchor->remember($r['key_hash'],['operationId'=>$r['id'],'intentDigest'=>$r['intent_digest'],'resourceRef'=>$r['resource_ref'],'incarnation'=>$r['incarnation'],'generation'=>$generation]);
            $this->table()->insert($r);return $this->receipt((object)$r);
        });
    }
    private function execute(string $id, Admission $admission): OperationReceipt
    {
        $row=$this->find($id);
        return (new PhysicalFence($this->connection))->run($row->database_name,function(Closure $fence)use($id,$admission):OperationReceipt {
            $r=$this->find($id);$this->guard($r,$fence);$admission->assertAllowed($r->kind,$r->resource_ref);
            if ($r->status!=='accepted') return $this->receipt($r);
            ($this->verifyBinding)($this->resource($r));
            if ($r->kind==='delete') $this->verifiedDeleteArchive($r);
            $database=$this->database($r->database_name) ?? throw new ClaimUnavailable('Physical target missing before submission.');
            $backend=$this->connection->selectOne('SELECT pid,backend_start::text AS started FROM pg_stat_activity WHERE pid=pg_backend_pid()');
            $this->connection->transaction(function()use($r,$database,$backend):void {
                if ($this->table()->where('id',$r->id)->where('status','accepted')->where('cancel_requested',false)->update(['status'=>'running','attempt_generation'=>1,'receipt_version'=>2,'database_oid'=>(string)$database->oid,'backend_pid'=>$backend->pid,'backend_start'=>$backend->started])!==1) throw new ClaimUnavailable('Attempt changed before submission.');
                $this->connection->table('tenant_pg_resources')->where('resource_ref',$r->resource_ref)->where('incarnation',$r->incarnation)->update(['status'=>$r->kind==='export'?'exporting':'deleting']);
            });
            $r=$this->find($id);
            try {
                $this->checkpoint('before-effect',$r);$this->guard($r,$fence);$admission->assertAllowed($r->kind,$r->resource_ref);
                $this->assertPolicy($r);
                if ($this->find($id)->cancel_requested) return $this->publish($r,null,'none');
                if ($r->kind==='export') {
                    $manifest=$this->archives->write($this->resource($r),$id,$r->export_id,$r->destination_ref);
                    $this->checkpoint('after-export',$r);$this->guard($r,$fence);$this->assertArchive($r,$manifest);
                    return $this->publish($r,$manifest,'archived');
                }
                $this->verifiedDeleteArchive($r);($this->verifyBinding)($this->resource($r));$this->guard($r,$fence);
                $now=$this->database($r->database_name);
                if ($now===null || (string)$now->oid!==$r->database_oid) throw new ClaimUnavailable('Original database changed before DROP.');
                // Direct pinned PDO: Illuminate must never reconnect/retry a lost DDL reply.
                $pdo=$this->connection->getPdo();$fence();$pdo->exec('DROP DATABASE "'.$r->database_name.'"');
                $this->checkpoint('after-drop',$r);$this->guard($r,$fence);
                if ($this->database($r->database_name)!==null) throw new ClaimUnavailable('DROP has not been observed.');
                return $this->publish($r,null,'dropped');
            } catch (\Throwable $e) {
                try { $this->unknown($this->find($id)); } catch (\Throwable) {}
                throw $e;
            }
        });
    }
    private function publish(object $r, ?ArchiveManifest $manifest, string $effect): OperationReceipt
    {
        $this->history();
        return $this->connection->transaction(function()use($r,$manifest,$effect):OperationReceipt {
            $current=$this->table()->where('id',$r->id)->lockForUpdate()->first();$this->resource($r,allowDeleted:true);
            if (!in_array($current->status,['running','unknown'],true) || (int)$current->attempt_generation!==(int)$r->attempt_generation) throw new ClaimUnavailable('Attempt changed before publication.');
            $cancel=(bool)$current->cancel_requested;$status=$cancel?'cancelled':'succeeded';
            $changes=['status'=>$status,'observed_effect'=>$effect,'receipt_version'=>(int)$current->receipt_version+1];
            if ($manifest!==null) $changes['manifest']=json_encode(get_object_vars($manifest),JSON_THROW_ON_ERROR);
            $this->table()->where('id',$r->id)->update($changes);
            $target=['status'=>$effect==='dropped'?'deleted':($cancel?'quarantined':'exported')];
            if ($effect==='dropped') $target['deleted_at']=new \DateTimeImmutable();
            $this->connection->table('tenant_pg_resources')->where('resource_ref',$r->resource_ref)->where('incarnation',$r->incarnation)->update($target);
            $this->connection->table('tenant_pg_lifecycle_outbox')->insert(['operation_id'=>$r->id,'resource_ref'=>$r->resource_ref,'incarnation'=>$r->incarnation,'effect'=>$effect,'receipt_version'=>$changes['receipt_version']]);
            return $this->receipt($this->find($r->id));
        });
    }
    private function unknown(object $r): OperationReceipt
    {
        $this->connection->transaction(function()use($r):void {
            $current=$this->table()->where('id',$r->id)->lockForUpdate()->first();
            if ($current!==null && in_array($current->status,['running','unknown'],true)) {
                $this->table()->where('id',$r->id)->update(['status'=>'unknown','observed_effect'=>'quarantined','receipt_version'=>(int)$current->receipt_version+1]);
                $this->connection->table('tenant_pg_resources')->where('resource_ref',$r->resource_ref)->where('incarnation',$r->incarnation)->update(['status'=>'quarantined']);
            }
        });return $this->receipt($this->find($r->id));
    }
    private function verifiedDeleteArchive(object $r): ArchiveManifest
    {
        if ($r->export_ref===null) throw new \Tenant\Exceptions\ArchiveUnverified('archive_unverified: retention requires original export');
        $ref=json_decode($r->export_ref,true,flags:JSON_THROW_ON_ERROR);$export=$this->find($ref['operation']['operationId']);
        if ($export->kind!=='export' || $export->status!=='succeeded' || $export->export_id!==$ref['exportId'] || $export->key_namespace!==$ref['operation']['namespace'] || $export->resource_ref!==$r->resource_ref || $export->incarnation!==$r->incarnation || $ref['operation']['resourceRef']!==$r->resource_ref || $ref['operation']['incarnation']!==$r->incarnation || $export->route_revision!==$r->route_revision || $export->recovery_generation!==$r->recovery_generation) throw new \Tenant\Exceptions\ArchiveUnverified('archive_unverified: original export binding differs');
        // Preserve the source guarantee: a newer interrupted export invalidates older readiness.
        if ($this->table()->where('resource_ref',$r->resource_ref)->where('incarnation',$r->incarnation)->where('kind','export')->where('id','>',$export->id)->exists()) throw new \Tenant\Exceptions\ArchiveUnverified('archive_unverified: newer export supersedes original readiness');
        return $this->verifiedArchive($export);
    }
    private function verifiedArchive(object $r): ArchiveManifest
    {
        if ($r->manifest===null) throw new \Tenant\Exceptions\ArchiveUnverified('archive_unverified: manifest unavailable');
        $m=new ArchiveManifest(...json_decode($r->manifest,true,flags:JSON_THROW_ON_ERROR));$this->assertArchive($r,$m);return $m;
    }
    private function assertArchive(object $r, ArchiveManifest $m): void
    {
        if ($m->operationId!==$r->id || $m->exportId!==$r->export_id || $m->resourceRef!==$r->resource_ref || $m->incarnation!==$r->incarnation || $m->routeRevision!==$r->route_revision || $m->recoveryGeneration!==$r->recovery_generation || $m->destinationRef!==$r->destination_ref || $this->archives===null || !$this->archives->verify($m)) throw new \Tenant\Exceptions\ArchiveUnverified('archive_unverified: bytes or original ownership differ');
    }
    private function resource(object $r, bool $allowDeleted=false): object
    {
        $resource=$this->connection->table('tenant_pg_resources')->where('resource_ref',$r->resource_ref)->first();
        if ($resource===null || (!$allowDeleted && $resource->deleted_at!==null)) throw new \Tenant\Exceptions\ContextUnavailable();
        if ($resource->incarnation!==$r->incarnation) throw new \Tenant\Exceptions\StaleIncarnation();
        if ($resource->route_revision!==$r->route_revision) throw new \Tenant\Exceptions\StaleRoute();
        if ($resource->cluster_id!==$r->cluster_id || $resource->database_name!==$r->database_name || $resource->recovery_generation!==$r->recovery_generation) throw new \Tenant\Exceptions\ResourceQuarantined();
        return $resource;
    }
    private function guard(object $r, Closure $fence, bool $allowDeleted=false): void
    {
        $fence();$generation=$this->history();if ($generation!==$r->recovery_generation) throw new \Tenant\Exceptions\ResourceQuarantined('Stale recovery generation.');$this->resource($r,$allowDeleted);$fence();
    }
    private function referenced(OperationRef $ref, Admission $admission, string $purpose): object
    {
        $admission->assertAllowed($purpose,$ref->resourceRef);$r=$this->find($ref->operationId);
        if ($r->key_namespace!==$ref->namespace || $r->resource_ref!==$ref->resourceRef || $r->incarnation!==$ref->incarnation) throw new \Tenant\Exceptions\OriginalOperationMissing('Original operation reference differs.');return $r;
    }
    private function assertPolicy(object $r): void
    {
        if ($r->kind==='export') $intent=new ExportIntent($r->resource_ref,$r->incarnation,$r->destination_ref,$r->retention_ref);
        else {
            $e=$r->export_ref===null?null:json_decode($r->export_ref,true,flags:JSON_THROW_ON_ERROR);
            $ref=$e===null?null:new ExportRef($e['exportId'],new OperationRef(...$e['operation']));
            $intent=new DeleteIntent($r->resource_ref,$r->incarnation,$ref,$r->purpose_ref);
        }
        if ($this->policy===null || ($this->policy)($r->kind,$intent,$this->resource($r,allowDeleted:true))!==true) throw new \Tenant\Exceptions\RetentionHistoryMissing('retention_history_missing: fresh policy refused');
    }
    private function configured(): void { if ($this->archives===null || $this->policy===null) throw new \Tenant\Exceptions\ArchiveUnverified('Qualified archive writer/verifier and explicit retention policy required.');$this->history(); }
    private function history(): string { return AnchorHistory::assertCurrent($this->connection,$this->recoveryAnchor()); }
    private function table(): \Illuminate\Database\Query\Builder { return $this->connection->table('tenant_pg_lifecycle_operations'); }
    private function find(string $id): object { return $this->table()->where('id',$id)->first() ?? throw new \Tenant\Exceptions\OriginalOperationMissing('Original operation not found'); }
    private function database(string $database): ?object
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,63}$/D',$database)) throw new ClaimUnavailable('Invalid physical identifier.');return $this->connection->selectOne('SELECT oid FROM pg_database WHERE datname=?',[$database]);
    }
    private function reference(OperationReceipt $r): OperationRef { return new OperationRef($r->key->namespace,$r->operationId,$r->resourceRef,$r->incarnation); }
    private function receipt(object $r): OperationReceipt
    {
        $ref=$r->kind==='export'?new ExportRef($r->export_id,new OperationRef($r->key_namespace,$r->id,$r->resource_ref,$r->incarnation)):null;
        return new OperationReceipt($r->id,$r->intent_digest,$r->resource_ref,$r->incarnation,(int)$r->attempt_generation,$r->status,(int)$r->receipt_version,$r->observed_effect,(bool)$r->cancel_requested,new OperationKey($r->key_namespace,$r->operation_key),$ref);
    }
    private function checkpoint(string $point, object $r): void { if ($this->checkpoint!==null) ($this->checkpoint)($point,$r); }
}
