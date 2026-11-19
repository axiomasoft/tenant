<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Claim;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use Tenant\Exceptions\OriginalOperationMissing;
use Tenant\Postgres\Admission;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\Data\Provisioning\ClaimRequest;
use Tenant\Postgres\Data\Provisioning\ResourceSnapshot;
use Tenant\Postgres\Exceptions\Claim\ClaimConflict;
use Tenant\Postgres\Exceptions\Claim\ClaimUnavailable;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Database\TemplateClonePostgreSQLManager;
use Tenant\Postgres\Internal\Database\TemplateIntegrityVerifier;
use Tenant\Postgres\Internal\Recovery\AnchorHistory;
use Tenant\Postgres\Internal\Recovery\PhysicalFence;
use Tenant\Postgres\OperationKey;
use Tenant\Postgres\OperationReceipt;
use Tenant\Postgres\ProvisionIntent;
use Throwable;

/** A single original provision attempt; unknown DDL is never automatically repeated. */
#[Internal]
final readonly class ProvisionJournal
{
    /** @param Closure(ResourceSnapshot): void $verifyBinding */
    public function __construct(private Connection $connection, private TemplateIntegrityVerifier $integrity, private Closure $verifyBinding, private ?RecoveryAnchor $anchor = null) {}

    public function register(ProvisionIntent $intent, OperationKey $key, ResourceSnapshot $snapshot, string $templateDatabase, Admission $admission): OperationReceipt
    {
        $admission->assertAllowed('provision', $intent->hostIntentRef);
        if ($snapshot->clusterId !== $intent->clusterId || $snapshot->templateVersion !== $intent->templateVersion) {
            throw new ClaimConflict('Snapshot differs from intent.');
        }
        foreach ([$snapshot->databaseName, $templateDatabase] as $identifier) {
            if (preg_match('/^[A-Za-z0-9_]{1,63}$/D', $identifier) !== 1) {
                throw new ClaimConflict('Invalid database identifier.');
            }
        }
        $keyHash = $key->storageKey();
        $digest = $intent->digest();

        return $this->connection->transaction(function () use ($intent, $key, $snapshot, $templateDatabase, $keyHash, $digest): OperationReceipt {
            OperationKeys::lock($this->connection, $key, false);
            if ($this->anchor !== null) {
                AnchorHistory::assertCurrent($this->connection, $this->anchor);
            }
            $r = $this->connection->table('tenant_pg_provision_attempts')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($r !== null) {
                if (DbRow::str($r, 'key_namespace') !== $key->namespace
                    || DbRow::str($r, 'operation_key') !== $key->key
                    || ! hash_equals(DbRow::str($r, 'intent_digest'), $digest)
                    || DbRow::str($r, 'resource_ref') !== $snapshot->resourceRef
                    || DbRow::str($r, 'incarnation') !== $snapshot->incarnation
                    || DbRow::str($r, 'database_name') !== $snapshot->databaseName
                    || DbRow::str($r, 'template_database') !== $templateDatabase) {
                    throw new ClaimConflict('Original provision target differs.');
                }

                return $this->receipt($r);
            }
            $r = ['id' => (string) Str::uuid7(), 'key_hash' => $keyHash, 'key_namespace' => $key->namespace, 'operation_key' => $key->key, 'intent_digest' => $digest, 'host_intent_ref' => $intent->hostIntentRef, 'resource_ref' => $snapshot->resourceRef, 'incarnation' => $snapshot->incarnation, 'cluster_id' => $intent->clusterId, 'template_id' => $intent->templateId, 'template_version' => $intent->templateVersion, 'manifest_hash' => $intent->manifestHash, 'template_database' => $templateDatabase, 'database_name' => $snapshot->databaseName, 'status' => 'accepted', 'attempt_generation' => 0, 'receipt_version' => 1, 'observed_effect' => 'none', 'cancel_requested' => false];
            if ($this->anchor !== null) {
                AnchorHistory::remember($this->connection, $this->anchor, 'tenant_pg_provision_attempts', $r);
            }
            $this->connection->table('tenant_pg_provision_attempts')->insert($r);

            return $this->receipt((object) $r);
        });
    }

    /** @param Closure(Closure(): void): mixed $clonerFactory Receives the session-fence assertion. */
    public function execute(string $id, Admission $admission, Closure $clonerFactory): OperationReceipt
    {
        $row = $this->find($id);
        $admission->assertAllowed('provision', DbRow::str($row, 'host_intent_ref'));
        $templateDatabase = DbRow::str($row, 'template_database');

        return $this->withFence($row, function (Closure $fence) use ($id, $row, $templateDatabase, $clonerFactory): OperationReceipt {
            $fence();
            $this->verifyTemplate($row);
            $fence();
            $updated = $this->connection->table('tenant_pg_provision_attempts')->where('id', $id)->where('status', 'accepted')->update(['status' => 'running', 'attempt_generation' => 1, 'receipt_version' => 2]);
            if ($updated !== 1) {
                throw new ClaimUnavailable('Attempt already submitted; reconcile original operation.');
            }
            try {
                $cloner = $clonerFactory($fence);
                if (! $cloner instanceof TemplateClonePostgreSQLManager || ! $cloner->usesConnection($this->connection) || ! $cloner->usesFence($fence)) {
                    throw new ClaimUnavailable('DDL session mismatch.');
                }
                $cloner->createOrVerify($this->snapshot($row), $templateDatabase);
                $fence();
                ($this->verifyBinding)($this->snapshot($row));
                $fence();
                $this->connection->transaction(function () use ($id): void {
                    $current = $this->connection->table('tenant_pg_provision_attempts')->where('id', $id)->lockForUpdate()->first();
                    if ($current === null || DbRow::str($current, 'status') !== 'running' || DbRow::int($current, 'attempt_generation') !== 1) {
                        throw new ClaimUnavailable('Attempt changed before publication.');
                    }
                    $cancel = DbRow::bool($current, 'cancel_requested');
                    $this->connection->table('tenant_pg_provision_attempts')->where('id', $id)->update(['status' => $cancel ? 'cancelled' : 'succeeded', 'observed_effect' => $cancel ? 'quarantined' : 'verified', 'receipt_version' => DbRow::int($current, 'receipt_version') + 1]);
                });
            } catch (Throwable $failure) {
                // If the connection disappeared, a running row itself means unknown.
                try {
                    $this->connection->table('tenant_pg_provision_attempts')->where('id', $id)->where('status', 'running')->where('attempt_generation', 1)->update(['status' => 'unknown', 'receipt_version' => 3]);
                } catch (Throwable) {
                }
                throw $failure;
            }

            return $this->receipt($this->find($id));
        });
    }

    public function assertReference(string $namespace, string $id, string $resourceRef, string $incarnation, Admission $admission, string $purpose): void
    {
        $row = $this->find($id);
        $admission->assertAllowed($purpose, DbRow::str($row, 'host_intent_ref'));
        if (DbRow::str($row, 'key_namespace') !== $namespace || DbRow::str($row, 'resource_ref') !== $resourceRef || DbRow::str($row, 'incarnation') !== $incarnation) {
            throw new OriginalOperationMissing('Operation reference does not match the original.');
        }
    }

    public function query(string $id, Admission $admission): OperationReceipt
    {
        $row = $this->find($id);
        $admission->assertAllowed('query', DbRow::str($row, 'host_intent_ref'));
        if (DbRow::str($row, 'status') === 'succeeded') {
            $this->verifyTemplate($row);
            $this->integrity->verifyAccount($this->snapshot($row));
            ($this->verifyBinding)($this->snapshot($row));
        }

        return $this->receipt($row);
    }

    /** A cancellation request is durable even while a physical backend holds the fence. */
    public function cancel(string $id, Admission $admission): OperationReceipt
    {
        $row = $this->find($id);
        $admission->assertAllowed('cancel', DbRow::str($row, 'host_intent_ref'));

        return $this->connection->transaction(function () use ($id): OperationReceipt {
            $row = $this->connection->table('tenant_pg_provision_attempts')->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                throw new OriginalOperationMissing('Operation not found.');
            }
            $status = DbRow::str($row, 'status');
            if ($status === 'cancelled' || DbRow::bool($row, 'cancel_requested')) {
                return $this->receipt($row);
            }
            if ($status === 'succeeded') {
                throw new ClaimUnavailable('Completed operation cannot be cancelled.');
            }
            if (! in_array($status, ['accepted', 'running', 'unknown'], true)) {
                throw new ClaimUnavailable('Operation cannot be cancelled.');
            }
            $this->connection->table('tenant_pg_provision_attempts')->where('id', $id)->update(['status' => $status === 'accepted' ? 'cancelled' : $status, 'cancel_requested' => true, 'receipt_version' => DbRow::int($row, 'receipt_version') + 1]);

            return $this->receipt($this->find($id));
        });
    }

    /** Only verifies an existing original resource. Missing or unverified effects remain unknown. */
    public function reconcile(string $id, Admission $admission): OperationReceipt
    {
        $row = $this->find($id);
        $admission->assertAllowed('reconcile', DbRow::str($row, 'host_intent_ref'));

        return $this->withFence($row, function (Closure $fence) use ($id): OperationReceipt {
            $r = $this->find($id);
            $status = DbRow::str($r, 'status');
            if ($status === 'succeeded') {
                $fence();
                $this->verifyTemplate($r);
                $this->integrity->verifyAccount($this->snapshot($r));
                ($this->verifyBinding)($this->snapshot($r));
                $fence();

                return $this->receipt($r);
            }
            if (! in_array($status, ['running', 'unknown'], true)) {
                throw new ClaimUnavailable('No submitted attempt to reconcile.');
            }
            try {
                $fence();
                $this->verifyTemplate($r);
                $this->integrity->verifyAccount($this->snapshot($r));
                ($this->verifyBinding)($this->snapshot($r));
                $fence();
            } catch (Throwable) {
                $this->connection->table('tenant_pg_provision_attempts')->where('id', $id)->whereIn('status', ['running', 'unknown'])->update(['status' => 'unknown']);

                return $this->receipt($this->find($id));
            }
            $expectedAttemptGeneration = DbRow::int($r, 'attempt_generation');
            $this->connection->transaction(function () use ($id, $expectedAttemptGeneration): void {
                $current = $this->connection->table('tenant_pg_provision_attempts')->where('id', $id)->lockForUpdate()->first();
                if ($current === null || ! in_array(DbRow::str($current, 'status'), ['running', 'unknown'], true) || DbRow::int($current, 'attempt_generation') !== $expectedAttemptGeneration) {
                    throw new ClaimUnavailable('Attempt changed during reconciliation.');
                }
                $cancel = DbRow::bool($current, 'cancel_requested');
                $this->connection->table('tenant_pg_provision_attempts')->where('id', $id)->update(['status' => $cancel ? 'cancelled' : 'succeeded', 'observed_effect' => $cancel ? 'quarantined' : 'verified', 'receipt_version' => DbRow::int($current, 'receipt_version') + 1]);
            });

            return $this->receipt($this->find($id));
        });
    }

    /**
     * @template T
     *
     * @param  Closure(Closure(): void): T  $work
     * @return T
     */
    private function withFence(object $row, Closure $work): mixed
    {
        return new PhysicalFence($this->connection)->run(DbRow::str($row, 'database_name'), $work);
    }

    public function usesConnection(Connection $connection): bool
    {
        return $this->connection === $connection;
    }

    private function verifyTemplate(object $r): void
    {
        if ($this->anchor !== null) {
            $generation = AnchorHistory::assertCurrent($this->connection, $this->anchor);
            if ($this->connection->table('tenant_pg_resources')->where('resource_ref', DbRow::str($r, 'resource_ref'))->value('recovery_generation') !== $generation) {
                throw new ClaimUnavailable('Stale recovery generation.');
            }
        }
        $q = $this->integrity->qualify(new ClaimRequest('reconcile', DbRow::str($r, 'cluster_id'), DbRow::str($r, 'template_id'), DbRow::str($r, 'intent_digest')));
        if ($q->templateVersion !== DbRow::int($r, 'template_version') || ! hash_equals(DbRow::str($r, 'manifest_hash'), $q->artifactDigest)) {
            throw new ClaimUnavailable('Frozen template proof changed.');
        }
    }

    private function find(string $id): object
    {
        return $this->connection->table('tenant_pg_provision_attempts')->where('id', $id)->first() ?? throw new OriginalOperationMissing('Operation not found.');
    }

    private function snapshot(object $r): ResourceSnapshot
    {
        return new ResourceSnapshot(
            DbRow::str($r, 'resource_ref'),
            DbRow::str($r, 'incarnation'),
            DbRow::int($r, 'template_version'),
            DbRow::str($r, 'cluster_id'),
            DbRow::str($r, 'database_name'),
        );
    }

    private function receipt(object $r): OperationReceipt
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
}
