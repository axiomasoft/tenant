<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Claim;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use Tenant\Exceptions\Context\ContextUnavailable;
use Tenant\Exceptions\OriginalOperationMissing;
use Tenant\Exceptions\ResourceQuarantined;
use Tenant\Exceptions\Verification\StaleIncarnation;
use Tenant\Postgres\Admission;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\Data\Provisioning\ClaimRequest;
use Tenant\Postgres\Exceptions\Claim\ClaimConflict;
use Tenant\Postgres\Exceptions\Claim\ClaimUnavailable;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Recovery\AnchorHistory;
use Tenant\Postgres\OperationKey;
use Tenant\Postgres\OperationReceipt;
use Tenant\Postgres\ProvisionIntent;

/** Durable claim result for a previously provisioned and verified pool resource. */
#[Internal]
final readonly class ClaimReceiptStore
{
    /** @param Closure(object): void $verifyCurrentBinding A fresh physical verifier, not a wire-supplied callback. */
    public function __construct(private Connection $connection, private ClaimResource $claims, private Closure $verifyCurrentBinding, private ?RecoveryAnchor $anchor = null) {}

    public function claim(ProvisionIntent $intent, OperationKey $key, Admission $admission): OperationReceipt
    {
        if (! $this->claims->usesConnection($this->connection)) {
            throw new ClaimUnavailable('Claim and receipt must use the same connection.');
        }
        $admission->assertAllowed('provision', $intent->hostIntentRef);
        $digest = $intent->digest();
        $storageKey = $key->storageKey();

        return $this->connection->transaction(function () use ($intent, $key, $admission, $digest, $storageKey): OperationReceipt {
            OperationKeys::lock($this->connection, $key, false);
            if ($this->anchor !== null) {
                AnchorHistory::assertCurrent($this->connection, $this->anchor);
            }
            $existing = $this->connection->table('tenant_pg_operations')->where('key_hash', $storageKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals(DbRow::str($existing, 'intent_digest'), $digest)
                    || DbRow::str($existing, 'key_namespace') !== $key->namespace
                    || DbRow::str($existing, 'operation_key') !== $key->key) {
                    throw new ClaimConflict('Operation key already binds a different intent.');
                }
                $admission->assertAllowed('provision', DbRow::str($existing, 'host_intent_ref'));

                return $this->verified($existing);
            }
            // Inspect the original physical operation before touching any general pool row.
            // A retry must reveal its receipt even if another pending resource is available.
            $provision = $this->connection->table('tenant_pg_provision_attempts')->where('key_hash', $storageKey)->lockForUpdate()->first();
            $originalResourceId = null;
            if ($provision !== null) {
                if (DbRow::str($provision, 'key_namespace') !== $key->namespace
                    || DbRow::str($provision, 'operation_key') !== $key->key
                    || ! hash_equals(DbRow::str($provision, 'intent_digest'), $digest)) {
                    throw new ClaimConflict('Original provision intent differs.');
                }
                $admission->assertAllowed('provision', DbRow::str($provision, 'host_intent_ref'));
                if (DbRow::str($provision, 'status') !== 'succeeded' || DbRow::bool($provision, 'cancel_requested')) {
                    return $this->provisionReceipt($provision);
                }
                $original = $this->connection->table('tenant_pg_resources')->where('resource_ref', DbRow::str($provision, 'resource_ref'))->lockForUpdate()->first();
                if ($original === null || DbRow::raw($original, 'deleted_at') !== null) {
                    throw new ContextUnavailable;
                }
                if (DbRow::str($original, 'incarnation') !== DbRow::str($provision, 'incarnation')) {
                    throw new StaleIncarnation;
                }
                $signupKey = DbRow::optStr($original, 'signup_key');
                if ($signupKey !== null && $signupKey !== $storageKey) {
                    throw new ResourceQuarantined('Original allocation belongs to another key.');
                }
                $originalResourceId = DbRow::str($original, 'id');
            }
            $claim = $this->claims->claim(new ClaimRequest($storageKey, $intent->clusterId, $intent->templateId, $digest, $intent->templateVersion, $intent->manifestHash), $originalResourceId);
            $resource = $this->connection->table('tenant_pg_resources')->where('id', $claim->resourceId)->lockForUpdate()->first();
            if ($resource === null
                || DbRow::raw($resource, 'deleted_at') !== null
                || ! is_string(DbRow::raw($resource, 'resource_ref'))
                || ! is_string(DbRow::raw($resource, 'incarnation'))
                || ! is_string(DbRow::raw($resource, 'database_name'))
                || ! is_string(DbRow::raw($resource, 'route_revision'))
                || ! is_string(DbRow::raw($resource, 'recovery_generation'))) {
                throw new ClaimUnavailable('Pool resource has no complete binding.');
            }
            $resourceRef = DbRow::str($resource, 'resource_ref');
            $resourceIncarnation = DbRow::str($resource, 'incarnation');
            $resourceId = DbRow::str($resource, 'id');
            ($this->verifyCurrentBinding)($resource);
            if ($provision !== null && (DbRow::str($provision, 'resource_ref') !== $resourceRef || DbRow::str($provision, 'incarnation') !== $resourceIncarnation)) {
                throw new ClaimConflict('Original physical operation differs from claim.');
            }
            $row = [
                'id' => $provision !== null ? DbRow::str($provision, 'id') : (string) Str::uuid7(),
                'key_hash' => $storageKey,
                'key_namespace' => $key->namespace,
                'operation_key' => $key->key,
                'host_intent_ref' => $intent->hostIntentRef,
                'intent_digest' => $digest,
                'resource_id' => $resourceId,
                'resource_ref' => $resourceRef,
                'incarnation' => $resourceIncarnation,
                'status' => 'succeeded',
                'attempt_generation' => $provision !== null ? DbRow::int($provision, 'attempt_generation') : 1,
                'receipt_version' => $provision !== null ? DbRow::int($provision, 'receipt_version') : 1,
                'observed_effect' => $provision !== null ? DbRow::str($provision, 'observed_effect') : 'verified_pool',
            ];
            // Technical availability is owned here, never by a host product grant.
            // Publish it atomically with the verified original claim receipt.
            $this->connection->table('tenant_pg_resources')->where('id', $resourceId)
                ->where('status', 'provisioning')->update(['status' => 'ready']);
            if ($this->anchor !== null) {
                AnchorHistory::remember($this->connection, $this->anchor, 'tenant_pg_operations', $row);
            }
            $this->connection->table('tenant_pg_operations')->insert($row);

            return $this->receipt((object) $row);
        });
    }

    public function hasOperation(string $operationId): bool
    {
        return $this->connection->table('tenant_pg_operations')->where('id', $operationId)->exists();
    }

    public function query(string $operationId, Admission $admission): OperationReceipt
    {
        $row = $this->connection->table('tenant_pg_operations')->where('id', $operationId)->first();
        if ($row === null) {
            throw new OriginalOperationMissing('Operation not found.');
        }
        $admission->assertAllowed('query', DbRow::str($row, 'host_intent_ref'));

        return $this->verified($row);
    }

    private function verified(object $row): OperationReceipt
    {
        if ($this->anchor !== null) {
            AnchorHistory::assertCurrent($this->connection, $this->anchor);
        }
        $resource = $this->connection->table('tenant_pg_resources')->where('id', DbRow::str($row, 'resource_id'))->first();
        if ($resource === null || DbRow::raw($resource, 'deleted_at') !== null || DbRow::str($resource, 'status') === 'deleted') {
            throw new ContextUnavailable;
        }
        if (DbRow::str($resource, 'resource_ref') !== DbRow::str($row, 'resource_ref') || DbRow::str($resource, 'incarnation') !== DbRow::str($row, 'incarnation')) {
            throw new StaleIncarnation;
        }
        ($this->verifyCurrentBinding)($resource);

        return $this->receipt($row);
    }

    private function provisionReceipt(object $r): OperationReceipt
    {
        return new OperationReceipt(
            DbRow::str($r, 'id'),
            DbRow::str($r, 'intent_digest'),
            DbRow::str($r, 'resource_ref'),
            DbRow::str($r, 'incarnation'),
            DbRow::int($r, 'attempt_generation'),
            DbRow::str($r, 'status'),
            DbRow::int($r, 'receipt_version'),
            DbRow::str($r, 'observed_effect'),
            DbRow::bool($r, 'cancel_requested'),
            new OperationKey(DbRow::str($r, 'key_namespace'), DbRow::str($r, 'operation_key')),
        );
    }

    private function receipt(object $row): OperationReceipt
    {
        return new OperationReceipt(
            DbRow::str($row, 'id'),
            DbRow::str($row, 'intent_digest'),
            DbRow::str($row, 'resource_ref'),
            DbRow::str($row, 'incarnation'),
            DbRow::int($row, 'attempt_generation'),
            DbRow::str($row, 'status'),
            DbRow::int($row, 'receipt_version'),
            DbRow::str($row, 'observed_effect'),
            false,
            new OperationKey(DbRow::str($row, 'key_namespace'), DbRow::str($row, 'operation_key')),
        );
    }
}
