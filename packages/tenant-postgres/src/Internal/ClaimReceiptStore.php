<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Tenant\Postgres\{Admission,OperationKey,OperationReceipt,ProvisionIntent};use Tenant\Postgres\Data\{ClaimRequest};
use Tenant\Postgres\Exceptions\{ClaimConflict,ClaimUnavailable};

/** Durable claim result for a previously provisioned and verified pool resource. */
final readonly class ClaimReceiptStore
{
    /** @param Closure(object): void $verifyCurrentBinding A fresh physical verifier, not a wire-supplied callback. */
    public function __construct(private Connection $connection, private ClaimResource $claims, private Closure $verifyCurrentBinding) {}

    public function claim(ProvisionIntent $intent, OperationKey $key, Admission $admission): OperationReceipt
    {
        if (! $this->claims->usesConnection($this->connection)) throw new ClaimUnavailable('Claim and receipt must use the same connection.');
        $admission->assertAllowed('provision', $intent->hostIntentRef);
        $digest=$intent->digest(); $storageKey=$key->storageKey();
        return $this->connection->transaction(function () use ($intent,$key,$admission,$digest,$storageKey): OperationReceipt {
            OperationKeys::lock($this->connection, $key, false);
            $existing=$this->connection->table('tenant_pg_operations')->where('key_hash',$storageKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->intent_digest,$digest) || $existing->key_namespace !== $key->namespace || $existing->operation_key !== $key->key) throw new ClaimConflict('Operation key already binds a different intent.');
                $admission->assertAllowed('provision', $existing->host_intent_ref);
                return $this->verified($existing);
            }
            $claim=$this->claims->claim(new ClaimRequest($storageKey,$intent->clusterId,$intent->templateId,$digest,$intent->templateVersion,$intent->manifestHash));
            $resource=$this->connection->table('tenant_pg_resources')->where('id',$claim->resourceId)->lockForUpdate()->first();
            if ($resource === null || $resource->deleted_at !== null || ! is_string($resource->resource_ref) || ! is_string($resource->incarnation) || ! is_string($resource->database_name) || ! is_string($resource->route_revision) || ! is_string($resource->recovery_generation)) throw new ClaimUnavailable('Pool resource has no complete binding.');
            ($this->verifyCurrentBinding)($resource);
            $provision=$this->connection->table('tenant_pg_provision_attempts')->where('key_hash',$storageKey)->lockForUpdate()->first();
            if($provision!==null && (!hash_equals($provision->intent_digest,$digest)||$provision->status!=='succeeded'||$provision->resource_ref!==$resource->resource_ref||$provision->incarnation!==$resource->incarnation||(bool)$provision->cancel_requested)) throw new ClaimConflict('Original physical operation differs from claim.');
            $row=['id'=>$provision?->id ?? (string)Str::uuid7(),'key_hash'=>$storageKey,'key_namespace'=>$key->namespace,'operation_key'=>$key->key,'host_intent_ref'=>$intent->hostIntentRef,'intent_digest'=>$digest,'resource_id'=>$resource->id,'resource_ref'=>$resource->resource_ref,'incarnation'=>$resource->incarnation,'status'=>'succeeded','attempt_generation'=>$provision?->attempt_generation ?? 1,'receipt_version'=>$provision?->receipt_version ?? 1,'observed_effect'=>$provision?->observed_effect ?? 'verified_pool'];
            $this->connection->table('tenant_pg_operations')->insert($row);
            return $this->receipt((object)$row);
        });
    }

    public function hasOperation(string $operationId): bool
    {
        return $this->connection->table('tenant_pg_operations')->where('id',$operationId)->exists();
    }

    public function query(string $operationId, Admission $admission): OperationReceipt
    {
        $row=$this->connection->table('tenant_pg_operations')->where('id',$operationId)->first();
        if ($row === null) throw new ClaimUnavailable('Operation not found.');
        $admission->assertAllowed('query', $row->host_intent_ref);
        return $this->verified($row);
    }

    private function verified(object $row): OperationReceipt
    {
        $resource=$this->connection->table('tenant_pg_resources')->where('id',$row->resource_id)->first();
        if ($resource === null || $resource->deleted_at !== null || $resource->status === 'deleted' || $resource->resource_ref !== $row->resource_ref || $resource->incarnation !== $row->incarnation) throw new ClaimConflict('Original resource is unavailable or changed.');
        ($this->verifyCurrentBinding)($resource);
        return $this->receipt($row);
    }

    private function receipt(object $row): OperationReceipt
    {
        return new OperationReceipt($row->id,$row->intent_digest,$row->resource_ref,$row->incarnation,(int)$row->attempt_generation,$row->status,(int)$row->receipt_version,$row->observed_effect,false,new OperationKey($row->key_namespace,$row->operation_key));
    }
}
