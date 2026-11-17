<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Lifecycle;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use Tenant\Exceptions\ArchiveUnverified;
use Tenant\Exceptions\Context\ContextUnavailable;
use Tenant\Exceptions\OriginalOperationMissing;
use Tenant\Exceptions\RecoveryUnsupported;
use Tenant\Exceptions\ResourceQuarantined;
use Tenant\Exceptions\RetentionHistoryMissing;
use Tenant\Exceptions\Verification\StaleIncarnation;
use Tenant\Exceptions\Verification\StaleRoute;
use Tenant\Postgres\Admission;
use Tenant\Postgres\ArchiveManifest;
use Tenant\Postgres\Contracts\Lifecycle\ArchiveStore;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\DeleteIntent;
use Tenant\Postgres\Exceptions\Claim\ClaimConflict;
use Tenant\Postgres\Exceptions\Claim\ClaimUnavailable;
use Tenant\Postgres\ExportIntent;
use Tenant\Postgres\ExportReceipt;
use Tenant\Postgres\ExportRef;
use Tenant\Postgres\Internal\Claim\CanonicalIntent;
use Tenant\Postgres\Internal\Claim\OperationKeys;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Recovery\AnchorHistory;
use Tenant\Postgres\Internal\Recovery\PhysicalFence;
use Tenant\Postgres\OperationKey;
use Tenant\Postgres\OperationReceipt;
use Tenant\Postgres\OperationRef;
use Throwable;

/** Export/verify/DROP sequence using original receipts and pinned DDL fencing. */
#[Internal]
final readonly class LifecycleJournal
{
    /** @param Closure(object):void $verifyBinding Exact marker/role verification through the owner.
     * @param ?Closure(string,object,object):bool $policy Trusted retention/maintenance policy, no default entitlement.
     * @param ?Closure(string,object):void $checkpoint Fault observation seam; never an effect executor. */
    public function __construct(private Connection $connection, private Closure $verifyBinding, private ?ArchiveStore $archives = null, private ?RecoveryAnchor $anchor = null, private ?Closure $policy = null, private ?Closure $checkpoint = null) {}

    public function request(ExportIntent|DeleteIntent $intent, OperationKey $key, Admission $admission): OperationReceipt
    {
        $kind = $intent instanceof ExportIntent ? 'export' : 'delete';
        $admission->assertAllowed($kind, $intent->resourceRef);
        $this->configured();
        $receipt = $this->register($intent, $key, $admission, $kind);
        if ($receipt->status !== 'accepted') {
            return $this->query($this->reference($receipt), $admission);
        }

        return $this->execute($receipt->operationId, $admission);
    }

    public function hasOperation(string $id): bool
    {
        return $this->table()->where('id', $id)->exists();
    }

    public function query(OperationRef $ref, Admission $admission): OperationReceipt
    {
        $r = $this->referenced($ref, $admission, 'query');
        $this->history();
        $kind = DbRow::str($r, 'kind');
        $status = DbRow::str($r, 'status');
        $this->resource($r, allowDeleted: $kind === 'delete' && in_array($status, ['succeeded', 'cancelled'], true));
        if ($kind === 'export' && $status === 'succeeded') {
            $this->verifiedArchive($r);
        }

        return $this->receipt($r);
    }

    public function queryExport(ExportRef $ref, Admission $admission): ExportReceipt
    {
        $r = $this->referenced($ref->operation, $admission, 'query');
        $this->history();
        if (DbRow::str($r, 'kind') !== 'export' || DbRow::optStr($r, 'export_id') !== $ref->exportId) {
            throw new OriginalOperationMissing('Original export reference mismatch.');
        }
        $status = DbRow::str($r, 'status');
        $manifest = $status === 'succeeded' ? $this->verifiedArchive($r) : null;

        return new ExportReceipt($ref, $status === 'succeeded' ? 'ready' : $status, $manifest);
    }

    public function cancel(OperationRef $ref, Admission $admission): OperationReceipt
    {
        $row = $this->referenced($ref, $admission, 'cancel');
        $rowId = DbRow::str($row, 'id');
        $this->history();

        return $this->connection->transaction(function () use ($rowId): OperationReceipt {
            $r = $this->table()->where('id', $rowId)->lockForUpdate()->first();
            if ($r === null) {
                throw new OriginalOperationMissing('Original operation not found');
            }
            $status = DbRow::str($r, 'status');
            if ($status === 'succeeded') {
                throw new ClaimUnavailable('Completed operation cannot be cancelled.');
            }
            if (DbRow::bool($r, 'cancel_requested')) {
                return $this->receipt($r);
            }
            $this->table()->where('id', $rowId)->update(['cancel_requested' => true, 'status' => $status === 'accepted' ? 'cancelled' : $status, 'receipt_version' => DbRow::int($r, 'receipt_version') + 1]);

            return $this->receipt($this->find($rowId));
        });
    }

    public function reconcile(OperationRef $ref, Admission $admission): OperationReceipt
    {
        $r = $this->referenced($ref, $admission, 'reconcile');
        $this->configured();
        $this->history();

        return new PhysicalFence($this->connection)->run(DbRow::str($r, 'database_name'), function (Closure $fence) use ($ref, $admission): OperationReceipt {
            $r = $this->referenced($ref, $admission, 'reconcile');
            $this->guard($r, $fence, allowDeleted: true);
            $status = DbRow::str($r, 'status');
            if (in_array($status, ['succeeded', 'cancelled'], true)) {
                return $this->query($ref, $admission);
            }
            if (! in_array($status, ['running', 'unknown'], true)) {
                throw new ClaimUnavailable('Only an original submitted attempt can be reconciled.');
            }
            // A backend may release its advisory lock while a delayed command is still running.
            if ($this->connection->selectOne('SELECT pid FROM pg_stat_activity WHERE pid=? AND backend_start::text=?', [DbRow::raw($r, 'backend_pid'), DbRow::optStr($r, 'backend_start')]) !== null) {
                throw new ResourceQuarantined('Original physical backend still exists; quarantine until excluded.');
            }
            if (DbRow::str($r, 'kind') === 'export') {
                $archives = $this->archives ?? throw new ArchiveUnverified('Archive store required.');
                $manifest = $archives->recover(DbRow::str($r, 'export_id'));
                if ($manifest === null) {
                    return $this->unknown($r);
                }
                $this->assertArchive($r, $manifest);
                $fence();

                return $this->publish($r, $manifest, 'archived');
            }
            // Absence is accepted only after the original attempt was submitted and old backend excluded.
            if ($this->database(DbRow::str($r, 'database_name')) !== null) {
                return $this->unknown($r);
            }
            $this->verifiedDeleteArchive($r);
            $this->assertPolicy($r);
            $fence();

            return $this->publish($r, null, 'dropped');
        });
    }

    public function verifiedArchiveFor(ExportRef $ref, Admission $admission): ArchiveManifest
    {
        $receipt = $this->queryExport($ref, $admission);

        return $receipt->manifest ?? throw new ArchiveUnverified('archive_unverified: original export is not ready');
    }

    public function assertHistory(): string
    {
        return $this->history();
    }

    public function recoveryAnchor(): RecoveryAnchor
    {
        return $this->anchor ?? throw new RecoveryUnsupported('recovery_unsupported: external anchor required');
    }

    private function register(ExportIntent|DeleteIntent $intent, OperationKey $key, Admission $admission, string $kind): OperationReceipt
    {
        return $this->connection->transaction(function () use ($intent, $key, $admission, $kind): OperationReceipt {
            OperationKeys::lock($this->connection, $key, true);
            $generation = $this->history();
            $old = $this->table()->where('key_hash', $key->storageKey())->lockForUpdate()->first();
            if ($old !== null) {
                if (DbRow::str($old, 'key_namespace') !== $key->namespace
                    || DbRow::str($old, 'operation_key') !== $key->key
                    || ! hash_equals(DbRow::str($old, 'input_digest'), $intent->digest())
                    || DbRow::str($old, 'kind') !== $kind) {
                    throw new ClaimConflict('Original operation intent differs, including after tombstone.');
                }
                $admission->assertAllowed($kind, DbRow::str($old, 'resource_ref'));

                return $this->receipt($old);
            }
            $resource = $this->connection->table('tenant_pg_resources')->where('resource_ref', $intent->resourceRef)->lockForUpdate()->first();
            if ($resource === null || DbRow::raw($resource, 'deleted_at') !== null) {
                throw new ContextUnavailable;
            }
            $resourceIncarnation = DbRow::str($resource, 'incarnation');
            if ($resourceIncarnation !== $intent->incarnation) {
                throw new StaleIncarnation;
            }
            if (! in_array(DbRow::str($resource, 'status'), ['ready', 'active', 'exported', 'exporting'], true) || DbRow::str($resource, 'recovery_generation') !== $generation) {
                throw new ResourceQuarantined;
            }
            ($this->verifyBinding)($resource);
            $resourceRef = DbRow::str($resource, 'resource_ref');
            if ($this->table()->where('resource_ref', $resourceRef)->whereIn('status', ['accepted', 'running', 'unknown'])->exists()) {
                throw new ResourceQuarantined('Original target has an unresolved operation.');
            }
            if ($this->policy === null || ($this->policy)($kind, $intent, $resource) !== true) {
                throw new RetentionHistoryMissing('retention_history_missing: host policy refuses operation');
            }
            $r = [
                'id' => (string) Str::uuid7(),
                'key_hash' => $key->storageKey(),
                'key_namespace' => $key->namespace,
                'operation_key' => $key->key,
                'input_digest' => $intent->digest(),
                'kind' => $kind,
                'purpose_ref' => $intent instanceof DeleteIntent ? $intent->policyIntentRef : $intent->retentionProfileRef,
                'resource_ref' => $resourceRef,
                'incarnation' => $resourceIncarnation,
                'cluster_id' => DbRow::str($resource, 'cluster_id'),
                'database_name' => DbRow::str($resource, 'database_name'),
                'route_revision' => DbRow::str($resource, 'route_revision'),
                'recovery_generation' => $generation,
                'status' => 'accepted',
                'attempt_generation' => 0,
                'receipt_version' => 1,
                'observed_effect' => 'none',
                'cancel_requested' => false,
                'export_id' => $intent instanceof ExportIntent ? (string) Str::uuid7() : null,
                'export_ref' => $intent instanceof DeleteIntent && $intent->exportRef !== null
                    ? json_encode(['exportId' => $intent->exportRef->exportId, 'operation' => get_object_vars($intent->exportRef->operation)], JSON_THROW_ON_ERROR)
                    : null,
                'destination_ref' => $intent instanceof ExportIntent ? $intent->destinationRef : null,
                'retention_ref' => $intent instanceof ExportIntent ? $intent->retentionProfileRef : null,
                'manifest' => null,
                'backend_pid' => null,
                'backend_start' => null,
                'database_oid' => null,
            ];
            $r['intent_digest'] = CanonicalIntent::digest(['inputDigest' => $r['input_digest'], 'clusterBinding' => $r['cluster_id'], 'database' => $r['database_name'], 'routeRevision' => $r['route_revision'], 'recoveryGeneration' => $generation]);
            if ($kind === 'delete') {
                $this->verifiedDeleteArchive((object) $r);
            }
            // Anchor is write-ahead: an interrupted registration leaves an orphan that fails closed.
            $this->recoveryAnchor()->remember($r['key_hash'], ['operationId' => $r['id'], 'intentDigest' => $r['intent_digest'], 'resourceRef' => $r['resource_ref'], 'incarnation' => $r['incarnation'], 'generation' => $generation]);
            $this->table()->insert($r);

            return $this->receipt((object) $r);
        });
    }

    private function execute(string $id, Admission $admission): OperationReceipt
    {
        $row = $this->find($id);

        return new PhysicalFence($this->connection)->run(DbRow::str($row, 'database_name'), function (Closure $fence) use ($id, $admission): OperationReceipt {
            $r = $this->find($id);
            $this->guard($r, $fence);
            $rKind = DbRow::str($r, 'kind');
            $rResourceRef = DbRow::str($r, 'resource_ref');
            $rIncarnation = DbRow::str($r, 'incarnation');
            $rDatabaseName = DbRow::str($r, 'database_name');
            $admission->assertAllowed($rKind, $rResourceRef);
            if (DbRow::str($r, 'status') !== 'accepted') {
                return $this->receipt($r);
            }
            ($this->verifyBinding)($this->resource($r));
            if ($rKind === 'delete') {
                $this->verifiedDeleteArchive($r);
            }
            $database = $this->database($rDatabaseName) ?? throw new ClaimUnavailable('Physical target missing before submission.');
            $databaseOidRaw = DbRow::raw($database, 'oid');
            $databaseOid = (is_int($databaseOidRaw) || is_string($databaseOidRaw)) ? (string) $databaseOidRaw : throw new ClaimUnavailable('Database OID missing.');
            $backend = $this->connection->selectOne('SELECT pid,backend_start::text AS started FROM pg_stat_activity WHERE pid=pg_backend_pid()');
            if (! is_object($backend)) {
                throw new ClaimUnavailable('Backend identity unavailable.');
            }
            $rId = DbRow::str($r, 'id');
            $this->connection->transaction(function () use ($rId, $rResourceRef, $rIncarnation, $rKind, $databaseOid, $backend): void {
                if ($this->table()->where('id', $rId)->where('status', 'accepted')->where('cancel_requested', false)->update(['status' => 'running', 'attempt_generation' => 1, 'receipt_version' => 2, 'database_oid' => $databaseOid, 'backend_pid' => DbRow::raw($backend, 'pid'), 'backend_start' => DbRow::str($backend, 'started')]) !== 1) {
                    throw new ClaimUnavailable('Attempt changed before submission.');
                }
                $this->connection->table('tenant_pg_resources')->where('resource_ref', $rResourceRef)->where('incarnation', $rIncarnation)->update(['status' => $rKind === 'export' ? 'exporting' : 'deleting']);
            });
            $r = $this->find($id);
            try {
                $this->checkpoint('before-effect', $r);
                $this->guard($r, $fence);
                $admission->assertAllowed($rKind, $rResourceRef);
                $this->assertPolicy($r);
                if (DbRow::bool($this->find($id), 'cancel_requested')) {
                    return $this->publish($r, null, 'none');
                }
                if ($rKind === 'export') {
                    $archives = $this->archives ?? throw new ArchiveUnverified('Archive store required.');
                    $manifest = $archives->write($this->resource($r), $id, DbRow::str($r, 'export_id'), DbRow::str($r, 'destination_ref'));
                    $this->checkpoint('after-export', $r);
                    $this->guard($r, $fence);
                    $this->assertArchive($r, $manifest);

                    return $this->publish($r, $manifest, 'archived');
                }
                $this->verifiedDeleteArchive($r);
                ($this->verifyBinding)($this->resource($r));
                $this->guard($r, $fence);
                $now = $this->database($rDatabaseName);
                $nowOid = $now === null ? null : DbRow::raw($now, 'oid');
                if ($now === null || (! is_int($nowOid) && ! is_string($nowOid)) || (string) $nowOid !== DbRow::optStr($r, 'database_oid')) {
                    throw new ClaimUnavailable('Original database changed before DROP.');
                }
                // Direct pinned PDO: Illuminate must never reconnect/retry a lost DDL reply.
                $pdo = $this->connection->getPdo();
                $fence();
                $pdo->exec('DROP DATABASE "'.$rDatabaseName.'"');
                $this->checkpoint('after-drop', $r);
                $this->guard($r, $fence);
                if ($this->database($rDatabaseName) !== null) {
                    throw new ClaimUnavailable('DROP has not been observed.');
                }

                return $this->publish($r, null, 'dropped');
            } catch (Throwable $e) {
                try {
                    $this->unknown($this->find($id));
                } catch (Throwable) {
                }
                throw $e;
            }
        });
    }

    private function publish(object $r, ?ArchiveManifest $manifest, string $effect): OperationReceipt
    {
        $this->history();
        $id = DbRow::str($r, 'id');
        $expectedAttemptGeneration = DbRow::int($r, 'attempt_generation');
        $resourceRef = DbRow::str($r, 'resource_ref');
        $incarnation = DbRow::str($r, 'incarnation');

        return $this->connection->transaction(function () use ($r, $id, $expectedAttemptGeneration, $resourceRef, $incarnation, $manifest, $effect): OperationReceipt {
            $current = $this->table()->where('id', $id)->lockForUpdate()->first();
            $this->resource($r, allowDeleted: true);
            if ($current === null || ! in_array(DbRow::str($current, 'status'), ['running', 'unknown'], true) || DbRow::int($current, 'attempt_generation') !== $expectedAttemptGeneration) {
                throw new ClaimUnavailable('Attempt changed before publication.');
            }
            $cancel = DbRow::bool($current, 'cancel_requested');
            $status = $cancel ? 'cancelled' : 'succeeded';
            $receiptVersion = DbRow::int($current, 'receipt_version') + 1;
            $changes = ['status' => $status, 'observed_effect' => $effect, 'receipt_version' => $receiptVersion];
            if ($manifest !== null) {
                $changes['manifest'] = json_encode(get_object_vars($manifest), JSON_THROW_ON_ERROR);
            }
            $this->table()->where('id', $id)->update($changes);
            $target = ['status' => $effect === 'dropped' ? 'deleted' : ($cancel ? 'quarantined' : 'exported')];
            if ($effect === 'dropped') {
                $target['deleted_at'] = new DateTimeImmutable;
            }
            $this->connection->table('tenant_pg_resources')->where('resource_ref', $resourceRef)->where('incarnation', $incarnation)->update($target);
            $this->connection->table('tenant_pg_lifecycle_outbox')->insert(['operation_id' => $id, 'resource_ref' => $resourceRef, 'incarnation' => $incarnation, 'effect' => $effect, 'receipt_version' => $receiptVersion]);

            return $this->receipt($this->find($id));
        });
    }

    private function unknown(object $r): OperationReceipt
    {
        $id = DbRow::str($r, 'id');
        $resourceRef = DbRow::str($r, 'resource_ref');
        $incarnation = DbRow::str($r, 'incarnation');
        $this->connection->transaction(function () use ($id, $resourceRef, $incarnation): void {
            $current = $this->table()->where('id', $id)->lockForUpdate()->first();
            if ($current !== null && in_array(DbRow::str($current, 'status'), ['running', 'unknown'], true)) {
                $this->table()->where('id', $id)->update(['status' => 'unknown', 'observed_effect' => 'quarantined', 'receipt_version' => DbRow::int($current, 'receipt_version') + 1]);
                $this->connection->table('tenant_pg_resources')->where('resource_ref', $resourceRef)->where('incarnation', $incarnation)->update(['status' => 'quarantined']);
            }
        });

        return $this->receipt($this->find($id));
    }

    private function verifiedDeleteArchive(object $r): ArchiveManifest
    {
        $exportRefRaw = DbRow::optStr($r, 'export_ref');
        if ($exportRefRaw === null) {
            throw new ArchiveUnverified('archive_unverified: retention requires original export');
        }
        $ref = DbRow::exportRef($exportRefRaw);
        $export = $this->find($ref->operation->operationId);
        $rRef = DbRow::str($r, 'resource_ref');
        $rIncarnation = DbRow::str($r, 'incarnation');
        if (DbRow::str($export, 'kind') !== 'export'
            || DbRow::str($export, 'status') !== 'succeeded'
            || DbRow::optStr($export, 'export_id') !== $ref->exportId
            || DbRow::str($export, 'key_namespace') !== $ref->operation->namespace
            || DbRow::str($export, 'resource_ref') !== $rRef
            || DbRow::str($export, 'incarnation') !== $rIncarnation
            || $ref->operation->resourceRef !== $rRef
            || $ref->operation->incarnation !== $rIncarnation
            || DbRow::str($export, 'route_revision') !== DbRow::str($r, 'route_revision')
            || DbRow::str($export, 'recovery_generation') !== DbRow::str($r, 'recovery_generation')) {
            throw new ArchiveUnverified('archive_unverified: original export binding differs');
        }
        // Preserve the source guarantee: a newer interrupted export invalidates older readiness.
        if ($this->table()->where('resource_ref', $rRef)->where('incarnation', $rIncarnation)->where('kind', 'export')->where('id', '>', DbRow::str($export, 'id'))->exists()) {
            throw new ArchiveUnverified('archive_unverified: newer export supersedes original readiness');
        }

        return $this->verifiedArchive($export);
    }

    private function verifiedArchive(object $r): ArchiveManifest
    {
        $manifestJson = DbRow::optStr($r, 'manifest');
        if ($manifestJson === null) {
            throw new ArchiveUnverified('archive_unverified: manifest unavailable');
        }
        $m = DbRow::manifest($manifestJson);
        $this->assertArchive($r, $m);

        return $m;
    }

    private function assertArchive(object $r, ArchiveManifest $m): void
    {
        if ($m->operationId !== DbRow::str($r, 'id')
            || $m->exportId !== DbRow::optStr($r, 'export_id')
            || $m->resourceRef !== DbRow::str($r, 'resource_ref')
            || $m->incarnation !== DbRow::str($r, 'incarnation')
            || $m->routeRevision !== DbRow::str($r, 'route_revision')
            || $m->recoveryGeneration !== DbRow::str($r, 'recovery_generation')
            || $m->destinationRef !== DbRow::optStr($r, 'destination_ref')
            || $this->archives === null
            || ! $this->archives->verify($m)) {
            throw new ArchiveUnverified('archive_unverified: bytes or original ownership differ');
        }
    }

    private function resource(object $r, bool $allowDeleted = false): object
    {
        $resource = $this->connection->table('tenant_pg_resources')->where('resource_ref', DbRow::str($r, 'resource_ref'))->first();
        if ($resource === null || (! $allowDeleted && DbRow::raw($resource, 'deleted_at') !== null)) {
            throw new ContextUnavailable;
        }
        if (DbRow::str($resource, 'incarnation') !== DbRow::str($r, 'incarnation')) {
            throw new StaleIncarnation;
        }
        if (DbRow::str($resource, 'route_revision') !== DbRow::str($r, 'route_revision')) {
            throw new StaleRoute;
        }
        if (DbRow::str($resource, 'cluster_id') !== DbRow::str($r, 'cluster_id')
            || DbRow::str($resource, 'database_name') !== DbRow::str($r, 'database_name')
            || DbRow::str($resource, 'recovery_generation') !== DbRow::str($r, 'recovery_generation')) {
            throw new ResourceQuarantined;
        }

        return $resource;
    }

    /** @param Closure(): void $fence */
    private function guard(object $r, Closure $fence, bool $allowDeleted = false): void
    {
        $fence();
        $generation = $this->history();
        if ($generation !== DbRow::str($r, 'recovery_generation')) {
            throw new ResourceQuarantined('Stale recovery generation.');
        }
        $this->resource($r, $allowDeleted);
        $fence();
    }

    private function referenced(OperationRef $ref, Admission $admission, string $purpose): object
    {
        $admission->assertAllowed($purpose, $ref->resourceRef);
        $r = $this->find($ref->operationId);
        if (DbRow::str($r, 'key_namespace') !== $ref->namespace || DbRow::str($r, 'resource_ref') !== $ref->resourceRef || DbRow::str($r, 'incarnation') !== $ref->incarnation) {
            throw new OriginalOperationMissing('Original operation reference differs.');
        }

        return $r;
    }

    private function assertPolicy(object $r): void
    {
        $kind = DbRow::str($r, 'kind');
        if ($kind === 'export') {
            $intent = new ExportIntent(DbRow::str($r, 'resource_ref'), DbRow::str($r, 'incarnation'), DbRow::str($r, 'destination_ref'), DbRow::str($r, 'retention_ref'));
        } else {
            $exportRefJson = DbRow::optStr($r, 'export_ref');
            $ref = $exportRefJson === null ? null : DbRow::exportRef($exportRefJson);
            $intent = new DeleteIntent(DbRow::str($r, 'resource_ref'), DbRow::str($r, 'incarnation'), $ref, DbRow::str($r, 'purpose_ref'));
        }
        if ($this->policy === null || ($this->policy)($kind, $intent, $this->resource($r, allowDeleted: true)) !== true) {
            throw new RetentionHistoryMissing('retention_history_missing: fresh policy refused');
        }
    }

    private function configured(): void
    {
        if ($this->archives === null || $this->policy === null) {
            throw new ArchiveUnverified('Qualified archive writer/verifier and explicit retention policy required.');
        }
        $this->history();
    }

    private function history(): string
    {
        return AnchorHistory::assertCurrent($this->connection, $this->recoveryAnchor());
    }

    private function table(): Builder
    {
        return $this->connection->table('tenant_pg_lifecycle_operations');
    }

    private function find(string $id): object
    {
        return $this->table()->where('id', $id)->first() ?? throw new OriginalOperationMissing('Original operation not found');
    }

    private function database(string $database): ?object
    {
        if (preg_match('/^[A-Za-z0-9_]{1,63}$/D', $database) !== 1) {
            throw new ClaimUnavailable('Invalid physical identifier.');
        }

        $row = $this->connection->selectOne('SELECT oid FROM pg_database WHERE datname=?', [$database]);

        return is_object($row) ? $row : null;
    }

    private function reference(OperationReceipt $r): OperationRef
    {
        $key = $r->key ?? throw new OriginalOperationMissing('Operation key is missing.');

        return new OperationRef($key->namespace, $r->operationId, $r->resourceRef, $r->incarnation);
    }

    private function receipt(object $r): OperationReceipt
    {
        $kind = DbRow::str($r, 'kind');
        $keyNamespace = DbRow::str($r, 'key_namespace');
        $id = DbRow::str($r, 'id');
        $resourceRef = DbRow::str($r, 'resource_ref');
        $incarnation = DbRow::str($r, 'incarnation');
        $ref = $kind === 'export' ? new ExportRef(DbRow::str($r, 'export_id'), new OperationRef($keyNamespace, $id, $resourceRef, $incarnation)) : null;

        return new OperationReceipt(
            $id,
            DbRow::str($r, 'intent_digest'),
            $resourceRef,
            $incarnation,
            DbRow::int($r, 'attempt_generation'),
            DbRow::str($r, 'status'),
            DbRow::int($r, 'receipt_version'),
            DbRow::str($r, 'observed_effect'),
            DbRow::bool($r, 'cancel_requested'),
            new OperationKey($keyNamespace, DbRow::str($r, 'operation_key')),
            $ref,
        );
    }

    private function checkpoint(string $point, object $r): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($point, $r);
        }
    }
}
