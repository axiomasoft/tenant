<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\Str;
use Packman\Attributes\Api;
use PDO;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Exceptions\ArchiveUnverified;
use Tenant\Exceptions\ResourceQuarantined;
use Tenant\Postgres\Data\Binding\PhysicalAccess;
use Tenant\Postgres\Data\Provisioning\ResourceSnapshot;
use Tenant\Postgres\Internal\Binding\PostgresBindingVerifier;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Database\MarkerWriter;
use Tenant\Postgres\Internal\Lifecycle\LifecycleJournal;
use Tenant\Postgres\Internal\Recovery\AnchorHistory;
use Tenant\Postgres\Internal\Recovery\PhysicalFence;

/** Privileged operator control. Executes no pg_restore, CREATE, DROP, or automatic replay. */
#[Api(since: '1.0')]
final readonly class RecoveryOperator
{
    /**
     * @param  Closure(object): array<string, mixed>  $managementRoute  Owner-only route, restricted to the catalog server.
     * @param  (Closure(object): array<string, mixed>)|null  $runtimeRoute
     */
    private function __construct(private Connection $catalog, private LifecycleJournal $journal, private Closure $managementRoute, private string $runtimeRole, private ?Closure $runtimeRoute = null) {}

    public function admit(RecoveryIntent $intent, Admission $admission): RecoveryPlan
    {
        $admission->assertAllowed($intent->purpose, $intent->targetResourceRef);
        $generation = $this->journal->assertHistory();
        $archive = $this->journal->verifiedArchiveFor($intent->archiveRef, $admission);
        if (! hash_equals($archive->sha256, $intent->archiveHash)) {
            throw new ArchiveUnverified('archive_unverified');
        }
        $target = $this->target($intent);
        if (($intent->kind === 'restore') !== (DbRow::str($target, 'resource_ref') === $archive->resourceRef)) {
            throw new ResourceQuarantined('Restore keeps logical resource; clone requires a new resource.');
        }

        return new RecoveryPlan($intent->digest(), $generation, $intent, $archive, ['close admission and stop workers', 'exclude every old physical backend under target fences', 'retain original receipts/tombstones outside restored backup', 'advance independent recovery anchor before restoring control plane', 'restore archive into the frozen isolated target using the operator tool', 'verify original marker, stamp new incarnation and generation, verify through owner before reopening']);
    }

    /** Operator step before IO. All admissions close before a new global generation is committed. */
    public function quarantine(RecoveryPlan $plan, Admission $admission): string
    {
        $admission->assertAllowed($plan->intent->purpose, $plan->intent->targetResourceRef);
        if ($plan->intentDigest !== $plan->intent->digest() || $plan->externalGeneration !== $this->journal->assertHistory() || serialize($this->admit($plan->intent, $admission)) !== serialize($plan)) {
            throw new ResourceQuarantined('Stale or altered recovery plan.');
        }
        $this->target($plan->intent);
        $names = $this->catalogDatabaseNames();

        return $this->fenced($names, 0, function () use ($plan): string {
            // An advisory fence alone is insufficient: exclude known live delayed backends.
            foreach (['tenant_pg_lifecycle_operations', 'tenant_pg_provision_attempts'] as $table) {
                if ($this->catalog->table($table)->whereIn('status', ['accepted', 'running', 'unknown'])->exists()) {
                    throw new ResourceQuarantined('Original operation unresolved; reconcile before recovery.');
                }
            }
            $this->catalog->transaction(fn () => $this->catalog->table('tenant_pg_resources')->whereNull('deleted_at')->update(['status' => 'quarantined']));
            // A crash between anchor advancement and catalog update leaves admission fail-closed.
            $generation = $this->journal->recoveryAnchor()->advance($plan->externalGeneration);
            $this->catalog->table('tenant_pg_control')->where('id', 1)->update(['recovery_generation' => $generation]);

            return $generation;
        });
    }

    /** After operator restore, verifies the old archive marker, then stamps a replacement identity.
     * Caller retains original operation history. Other resources remain quarantined pending reconciliation. */
    public function complete(RecoveryPlan $plan, string $generation, Admission $admission): ResourceRecovery
    {
        if ($this->runtimeRoute === null) {
            throw new ResourceQuarantined('Recovery requires actual runtime verification.');
        }
        $intent = $plan->intent;
        $admission->assertAllowed($intent->purpose, $intent->targetResourceRef);
        if ($plan->intentDigest !== $intent->digest() || $generation === $plan->externalGeneration || $generation !== $this->journal->assertHistory()) {
            throw new ResourceQuarantined('Recovery generation not advanced.');
        }
        $currentArchive = $this->journal->verifiedArchiveFor($intent->archiveRef, $admission);
        if (get_object_vars($currentArchive) !== get_object_vars($plan->archive)) {
            throw new ArchiveUnverified('archive_unverified: original archive changed');
        }

        return new PhysicalFence($this->catalog)->run($intent->targetDatabase, function (Closure $fence) use ($plan, $intent, $generation): ResourceRecovery {
            $row = $this->target($intent);
            if (! in_array(DbRow::str($row, 'status'), ['deleted', 'quarantined', 'recovering'], true)) {
                throw new ResourceQuarantined('Target admission must be closed.');
            }
            $route = ($this->managementRoute)($row);
            $central = DbRow::configMap($this->catalog);
            $rowDatabase = DbRow::str($row, 'database_name');
            $routePort = $route['port'] ?? 5432;
            $centralPort = $central['port'] ?? 5432;
            if (($route['driver'] ?? null) !== 'pgsql'
                || ($route['database'] ?? null) !== $rowDatabase
                || ($route['host'] ?? null) !== ($central['host'] ?? null)
                || (! is_int($routePort) && ! is_string($routePort))
                || (! is_int($centralPort) && ! is_string($centralPort))
                || (int) $routePort !== (int) $centralPort
                || isset($route['url']) || isset($route['read']) || isset($route['write'])
                || preg_match('/^[A-Za-z0-9_]{1,63}$/D', $this->runtimeRole) !== 1) {
                throw new ResourceQuarantined('Recovery route mismatch.');
            }
            $pdo = (new PostgresConnector)->connect($route);
            $fence();
            $marker = ['protocol' => 'tenant.binding/1', 'owner_id' => 'tenant-postgres', 'resource_ref' => $plan->archive->resourceRef, 'incarnation' => $plan->archive->incarnation, 'recovery_generation' => $plan->archive->recoveryGeneration];
            $beforeStmt = $pdo->query('SELECT protocol,owner_id,resource_ref,incarnation,recovery_generation FROM tenant_binding');
            if ($beforeStmt === false || $beforeStmt->fetchAll(PDO::FETCH_ASSOC) !== [$marker]) {
                throw new ResourceQuarantined('Restored marker differs from original archive.');
            }
            $incarnation = (string) Str::uuid7();
            $revision = (string) Str::uuid7();
            $rowRef = DbRow::str($row, 'resource_ref');
            $rowIncarnation = DbRow::str($row, 'incarnation');
            // Durable claim before the marker changes; partial completion stays quarantined.
            if ($this->catalog->table('tenant_pg_resources')->where('resource_ref', $rowRef)->where('incarnation', $rowIncarnation)->update(['status' => 'recovering', 'incarnation' => $incarnation, 'route_revision' => $revision, 'recovery_generation' => $generation]) !== 1) {
                throw new ResourceQuarantined('Recovery target changed.');
            }
            // Reuse the P4.1 exact-marker CAS writer; restore is a different expected
            // source marker, not a second stamping engine.
            $writer = new MarkerWriter(new ConnectionFactory(new Container), $route, $this->runtimeRole, $generation, $marker, $fence);
            $writer->stamp(new ResourceSnapshot($rowRef, $incarnation, DbRow::int($row, 'template_version'), DbRow::str($row, 'cluster_id'), $rowDatabase));
            $expected = ['protocol' => 'tenant.binding/1', 'owner_id' => 'tenant-postgres', 'resource_ref' => $rowRef, 'incarnation' => $incarnation, 'recovery_generation' => $generation];
            $afterStmt = $pdo->query('SELECT protocol,owner_id,resource_ref,incarnation,recovery_generation FROM tenant_binding');
            if ($afterStmt === false || $afterStmt->fetchAll(PDO::FETCH_ASSOC) !== [$expected]) {
                throw new ResourceQuarantined('Replacement marker verification failed.');
            }
            $fence();
            $this->journal->assertHistory();
            $this->catalog->table('tenant_pg_resources')->where('resource_ref', $rowRef)->where('incarnation', $incarnation)->update(['status' => 'quarantined', 'deleted_at' => null]);
            $recovery = new ResourceRecovery($rowRef, $incarnation, $revision, $generation);
            $this->verifyReplacement($recovery);

            return $recovery;
        });
    }

    /** Explicit separate operator admission after complete() and host checks; no implicit grant. */
    public function reopen(ResourceRecovery $recovery, Admission $admission): void
    {
        $admission->assertAllowed('recovery:reopen', $recovery->resourceRef);
        $this->journal->assertHistory();
        $row = $this->catalog->table('tenant_pg_resources')->where('resource_ref', $recovery->resourceRef)->first() ?? throw new ResourceQuarantined('Unknown recovery target.');
        new PhysicalFence($this->catalog)->run(DbRow::str($row, 'database_name'), function (Closure $fence) use ($recovery): void {
            $this->verifyReplacement($recovery);
            $fence();
            if ($this->catalog->table('tenant_pg_resources')->where('resource_ref', $recovery->resourceRef)->where('incarnation', $recovery->incarnation)->where('route_revision', $recovery->routeRevision)->where('recovery_generation', $recovery->recoveryGeneration)->where('status', 'quarantined')->update(['status' => 'ready']) !== 1) {
                throw new ResourceQuarantined('Recovery admission CAS failed.');
            }
        });
    }

    /** After a control-plane backup is restored, old generation stays closed until all retained
     * receipts and old backends are reconciled. Missing history cannot be waived here. */
    public function reconcileCatalogGeneration(string $generation, Admission $admission): void
    {
        $admission->assertAllowed('recovery:catalog', 'catalog');
        $anchor = $this->journal->recoveryAnchor();
        if ($generation !== $anchor->generation()) {
            throw new ResourceQuarantined('External recovery generation differs.');
        }
        AnchorHistory::assertComplete($this->catalog, $anchor);
        $names = $this->catalogDatabaseNames();
        $this->fenced($names, 0, function () use ($generation): void {
            if ($this->catalog->table('tenant_pg_lifecycle_operations')->whereIn('status', ['accepted', 'running', 'unknown'])->exists() || $this->catalog->table('tenant_pg_provision_attempts')->whereIn('status', ['accepted', 'running', 'unknown'])->exists()) {
                throw new ResourceQuarantined('Restored operations need reconciliation.');
            }
            $this->catalog->transaction(function () use ($generation): void {
                $this->catalog->table('tenant_pg_resources')->whereNull('deleted_at')->update(['status' => 'quarantined']);
                $this->catalog->table('tenant_pg_control')->where('id', 1)->update(['recovery_generation' => $generation]);
            });
        });
    }

    private function verifyReplacement(ResourceRecovery $r): void
    {
        if ($this->runtimeRoute === null) {
            throw new ResourceQuarantined('Recovery requires actual runtime verification.');
        }
        $row = $this->catalog->table('tenant_pg_resources')->where('resource_ref', $r->resourceRef)->first();
        if ($row === null || DbRow::str($row, 'recovery_generation') !== $r->recoveryGeneration || $r->recoveryGeneration !== $this->journal->assertHistory()) {
            throw new ResourceQuarantined('Replacement generation differs.');
        }
        $verifier = new PostgresBindingVerifier($this->catalog, $this->runtimeRoute, $this->journal->recoveryAnchor(), maintenance: true);
        $verifier->verify(new BindingVerificationInput(new BindingExpectation($r->resourceRef, $r->incarnation, $r->routeRevision), new PhysicalAccess(($this->runtimeRoute)($row))));
    }

    private function target(RecoveryIntent $intent): object
    {
        $row = $this->catalog->table('tenant_pg_resources')->where('resource_ref', $intent->targetResourceRef)->first();
        if ($row === null
            || DbRow::str($row, 'incarnation') !== $intent->targetIncarnation
            || DbRow::str($row, 'cluster_id') !== $intent->targetClusterId
            || DbRow::str($row, 'database_name') !== $intent->targetDatabase) {
            throw new ResourceQuarantined('Frozen recovery target changed.');
        }

        return $row;
    }

    /** @return list<string> */
    private function catalogDatabaseNames(): array
    {
        return array_values(array_map(
            static fn (mixed $name): string => is_string($name) ? $name : throw new ResourceQuarantined('Invalid catalog database name.'),
            $this->catalog->table('tenant_pg_resources')->orderBy('database_name')->pluck('database_name')->all(),
        ));
    }

    /**
     * @template T
     *
     * @param  list<string>  $names
     * @param  Closure(): T  $work
     * @return T
     */
    private function fenced(array $names, int $offset, Closure $work): mixed
    {
        if ($offset === count($names)) {
            return $work();
        }

        return new PhysicalFence($this->catalog)->run($names[$offset], fn (): mixed => $this->fenced($names, $offset + 1, $work));
    }
}
