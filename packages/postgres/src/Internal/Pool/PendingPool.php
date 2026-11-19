<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Pool;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use Tenant\Postgres\Admission;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\Data\Provisioning\ClaimRequest;
use Tenant\Postgres\Data\Provisioning\ResourceSnapshot;
use Tenant\Postgres\Exceptions\Claim\ClaimUnavailable;
use Tenant\Postgres\Internal\Claim\OperationKeys;
use Tenant\Postgres\Internal\Claim\ProvisionJournal;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Database\TemplateClonePostgreSQLManager;
use Tenant\Postgres\Internal\Database\TemplateIntegrityVerifier;
use Tenant\Postgres\Internal\Recovery\AnchorHistory;
use Tenant\Postgres\OperationKey;
use Tenant\Postgres\OperationReceipt;
use Tenant\Postgres\ProvisionIntent;

/** Allocation is durable before physical creation; only verified clones become pending. */
#[Internal]
final readonly class PendingPool
{
    /** @param Closure(Closure(): void, ResourceSnapshot): TemplateClonePostgreSQLManager $clonerFactory
     * @param Closure(ResourceSnapshot): void $verifyBinding
     * @param Closure(string): mixed $databaseNameFor */
    public function __construct(private Connection $connection, private TemplateIntegrityVerifier $integrity, private ProvisionJournal $journal, private Closure $clonerFactory, private Closure $verifyBinding, private Closure $databaseNameFor, private ?RecoveryAnchor $anchor = null) {}

    public function leastLoadedCluster(int $templateVersion): ?string
    {
        $r = $this->connection->table('tenant_pg_clusters')->where('status', 'active')->where('template_version', $templateVersion)->whereColumn('database_count', '<', 'capacity')->orderBy('database_count')->orderBy('id')->first(['id']);

        return $r === null ? null : DbRow::optStr($r, 'id');
    }

    /** Shared replenishment is explicitly different from a cold claim allocation. */
    public function fill(ProvisionIntent $intent, OperationKey $key, Admission $admission): OperationReceipt
    {
        return $this->provision($intent, $key, $admission, false);
    }

    public function allocate(ProvisionIntent $intent, OperationKey $key, Admission $admission): OperationReceipt
    {
        return $this->provision($intent, $key, $admission, true);
    }

    private function provision(ProvisionIntent $intent, OperationKey $key, Admission $admission, bool $bindToKey): OperationReceipt
    {
        if (! $this->journal->usesConnection($this->connection)) {
            throw new ClaimUnavailable('Pool and journal must share the catalog connection.');
        }
        $admission->assertAllowed('provision', $intent->hostIntentRef);
        /** @var array{OperationReceipt, ResourceSnapshot} $pair */
        $pair = $this->connection->transaction(function () use ($intent, $key, $admission, $bindToKey): array {
            $hash = $key->storageKey();
            OperationKeys::lock($this->connection, $key, false);
            $old = $this->connection->table('tenant_pg_provision_attempts')->where('key_hash', $hash)->lockForUpdate()->first();
            if ($old !== null) {
                $snapshot = new ResourceSnapshot(
                    DbRow::str($old, 'resource_ref'),
                    DbRow::str($old, 'incarnation'),
                    DbRow::int($old, 'template_version'),
                    DbRow::str($old, 'cluster_id'),
                    DbRow::str($old, 'database_name'),
                );

                return [$this->journal->register($intent, $key, $snapshot, DbRow::str($old, 'template_database'), $admission), $snapshot];
            }
            $q = $this->integrity->qualify(new ClaimRequest($hash, $intent->clusterId, $intent->templateId, $intent->digest()));
            if ($q->templateVersion !== $intent->templateVersion || ! hash_equals($q->artifactDigest, $intent->manifestHash)) {
                throw new ClaimUnavailable('Frozen template proof changed.');
            }
            $cluster = $this->connection->table('tenant_pg_clusters')->where('id', $intent->clusterId)->where('status', 'active')->where('template_version', $intent->templateVersion)->lockForUpdate()->first();
            $template = $this->connection->table('tenant_pg_templates')->where('id', $intent->templateId)->where('version', $intent->templateVersion)->where('status', 'ready')->lockForUpdate()->first();
            if ($cluster === null || $template === null || DbRow::int($cluster, 'database_count') >= DbRow::int($cluster, 'capacity')) {
                throw new ClaimUnavailable('Cluster or template is unavailable.');
            }
            $templateDb = DbRow::str($template, 'db_name');
            $id = (string) Str::uuid7();
            $database = ($this->databaseNameFor)($id);
            if (! is_string($database) || preg_match('/^[A-Za-z0-9_]{1,63}$/D', $database) !== 1) {
                throw new ClaimUnavailable('Invalid allocated database name.');
            }
            $snapshot = new ResourceSnapshot('pg:'.$id, (string) Str::uuid7(), $intent->templateVersion, $intent->clusterId, $database);
            $this->connection->table('tenant_pg_resources')->insert(['id' => $id, 'cluster_id' => $intent->clusterId, 'template_version' => $intent->templateVersion, 'status' => 'provisioning', 'signup_key' => $bindToKey ? $hash : null, 'signup_request_digest' => $bindToKey ? $intent->digest() : null, 'signup_cluster_id' => $bindToKey ? $intent->clusterId : null, 'signup_template_id' => $bindToKey ? $intent->templateId : null, 'resource_ref' => $snapshot->resourceRef, 'incarnation' => $snapshot->incarnation, 'database_name' => $snapshot->databaseName, 'route_revision' => '1', 'recovery_generation' => $this->anchor === null ? (string) Str::uuid7() : AnchorHistory::assertCurrent($this->connection, $this->anchor)]);
            $this->connection->table('tenant_pg_clusters')->where('id', $intent->clusterId)->increment('database_count');

            return [$this->journal->register($intent, $key, $snapshot, $templateDb, $admission), $snapshot];
        });
        [$receipt, $snapshot] = $pair;
        if ($receipt->status === 'accepted') {
            $receipt = $this->journal->execute($receipt->operationId, $admission, fn (Closure $fence): TemplateClonePostgreSQLManager => ($this->clonerFactory)($fence, $snapshot));
        }
        if ($receipt->status === 'succeeded' && ! $bindToKey) {
            ($this->verifyBinding)($snapshot);
            $this->connection->table('tenant_pg_resources')->where('resource_ref', $snapshot->resourceRef)->where('incarnation', $snapshot->incarnation)->where('status', 'provisioning')->whereNull('signup_key')->update(['status' => 'pending']);
        }

        return $receipt;
    }
}
