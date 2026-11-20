<?php

declare(strict_types=1);

namespace Tenant\Postgres\Tests\Support;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;
use Tenant\Postgres\Admission;
use Tenant\Postgres\ArchiveManifest;
use Tenant\Postgres\Contracts\Lifecycle\ArchiveStore;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Lifecycle\LifecycleJournal;
use Tenant\Postgres\OperationRef;

/** Catalog rows for a verified lifecycle publication without a physical DROP. */
final readonly class VerifiedDropCatalog
{
    public const string ClusterId = 'c1c1c1c1-c1c1-41c1-81c1-c1c1c1c1c1c1';

    public const string ResourceId = 'c2c2c2c2-c2c2-42c2-82c2-c2c2c2c2c2c2';

    public const string ExportOperationId = 'c3c3c3c3-c3c3-43c3-83c3-c3c3c3c3c3c3';

    public const string DeleteOperationId = 'c4c4c4c4-c4c4-44c4-84c4-c4c4c4c4c4c4';

    public const string ExportId = 'c5c5c5c5-c5c5-45c5-85c5-c5c5c5c5c5c5';

    public const string ResourceRef = 'pg:capacity-slot';

    public const string Incarnation = 'inc:capacity-slot';

    public const string AbsentDatabase = 'capacity_slot_absent';

    public const string Generation = 'gen-1';

    public const string Namespace = 'capacity';

    public const string RouteRevision = 'route:1';

    public const string DestinationRef = 'archive:lab';

    public const string PolicyRef = 'policy:lab';

    public function __construct(
        public LifecycleJournal $journal,
        public OperationRef $operation,
        public Admission $admission,
        public string $operationId,
    ) {}

    public static function seed(Connection $connection, string $scenario, int $databaseCount = 1, bool $cancelRequested = false, bool $withCluster = true): self
    {
        if (! in_array($scenario, ['dropped', 'archived', 'unobserved'], true)) {
            throw new InvalidArgumentException('Unknown verified-drop scenario.');
        }
        $databaseName = $scenario === 'unobserved' ? $connection->getDatabaseName() : self::AbsentDatabase;
        $existing = $connection->selectOne('SELECT oid FROM pg_database WHERE datname=?', [$databaseName]);
        if ($scenario === 'unobserved' && $existing === null) {
            throw new RuntimeException('Suite database is missing from pg_database.');
        }
        if ($scenario !== 'unobserved' && $existing !== null) {
            throw new RuntimeException('Refusing to treat an existing database as an absent drop target.');
        }

        $manifest = new ArchiveManifest(self::ExportId, self::ExportOperationId, self::ResourceRef, self::Incarnation, self::RouteRevision, self::Generation, self::DestinationRef, str_repeat('ab', 32), 3);
        $manifestJson = json_encode(get_object_vars($manifest), JSON_THROW_ON_ERROR);
        $exportRef = json_encode([
            'exportId' => self::ExportId,
            'operation' => [
                'namespace' => self::Namespace,
                'operationId' => self::ExportOperationId,
                'resourceRef' => self::ResourceRef,
                'incarnation' => self::Incarnation,
            ],
        ], JSON_THROW_ON_ERROR);

        $connection->table('tenant_pg_control')->insert([
            'id' => 1,
            'recovery_generation' => self::Generation,
        ]);
        if ($withCluster) {
            $connection->table('tenant_pg_clusters')->insert([
                'id' => self::ClusterId,
                'status' => 'active',
                'template_version' => 7,
                'database_count' => $databaseCount,
                'capacity' => 1,
            ]);
        }
        $connection->table('tenant_pg_resources')->insert([
            'id' => self::ResourceId,
            'cluster_id' => self::ClusterId,
            'template_version' => 7,
            'status' => $scenario === 'archived' ? 'exporting' : 'deleting',
            'resource_ref' => self::ResourceRef,
            'incarnation' => self::Incarnation,
            'database_name' => $databaseName,
            'route_revision' => self::RouteRevision,
            'recovery_generation' => self::Generation,
        ]);

        $exportRunning = $scenario === 'archived';
        $connection->table('tenant_pg_lifecycle_operations')->insert(self::operation(
            self::ExportOperationId,
            hash('sha256', 'verified-drop-capacity:export'),
            'export',
            $exportRunning ? 'running' : 'succeeded',
            $exportRunning ? 'none' : 'archived',
            $exportRunning && $cancelRequested,
            $databaseName,
            self::ExportId,
            null,
            $manifestJson,
        ));
        $operationId = self::ExportOperationId;
        if (! $exportRunning) {
            $operationId = self::DeleteOperationId;
            $connection->table('tenant_pg_lifecycle_operations')->insert(self::operation(
                self::DeleteOperationId,
                hash('sha256', 'verified-drop-capacity:delete'),
                'delete',
                'running',
                'none',
                $cancelRequested,
                $databaseName,
                null,
                $exportRef,
                null,
            ));
        }

        $archives = new class($manifest) implements ArchiveStore
        {
            public function __construct(private ArchiveManifest $manifest) {}

            public function write(object $resource, string $operationId, string $exportId, string $destinationRef): ArchiveManifest
            {
                throw new RuntimeException('Archive writes are not part of verified publication.');
            }

            public function verify(ArchiveManifest $manifest): bool
            {
                return true;
            }

            public function recover(string $exportId): ?ArchiveManifest
            {
                return $exportId === $this->manifest->exportId ? $this->manifest : null;
            }
        };
        $anchor = new class implements RecoveryAnchor
        {
            public function generation(): string
            {
                return VerifiedDropCatalog::Generation;
            }

            public function entries(): array
            {
                return [];
            }

            public function remember(string $keyHash, array $original): void {}

            public function advance(string $expectedGeneration): string
            {
                return $expectedGeneration;
            }
        };

        return new self(
            new LifecycleJournal(
                $connection,
                static function (object $row): void {},
                $archives,
                $anchor,
                static fn (string $kind, object $intent, object $resource): bool => true,
            ),
            new OperationRef(self::Namespace, $operationId, self::ResourceRef, self::Incarnation),
            new Admission(static fn (string $purpose, string $target): bool => $target === self::ResourceRef),
            $operationId,
        );
    }

    public static function databaseCount(Connection $connection): ?int
    {
        $row = $connection->table('tenant_pg_clusters')->where('id', self::ClusterId)->first();

        return $row === null ? null : DbRow::int($row, 'database_count');
    }

    public static function placeableClusterId(Connection $connection): ?string
    {
        $row = $connection->table('tenant_pg_clusters')->where('status', 'active')->where('template_version', 7)->whereColumn('database_count', '<', 'capacity')->orderBy('database_count')->orderBy('id')->first(['id']);

        return $row === null ? null : DbRow::optStr($row, 'id');
    }

    /** @return array<string, mixed> */
    private static function operation(string $id, string $keyHash, string $kind, string $status, string $effect, bool $cancel, string $databaseName, ?string $exportId, ?string $exportRef, ?string $manifest): array
    {
        return [
            'id' => $id,
            'key_hash' => $keyHash,
            'key_namespace' => self::Namespace,
            'operation_key' => $kind.'-capacity',
            'input_digest' => $keyHash,
            'intent_digest' => $keyHash,
            'kind' => $kind,
            'purpose_ref' => self::PolicyRef,
            'resource_ref' => self::ResourceRef,
            'incarnation' => self::Incarnation,
            'cluster_id' => self::ClusterId,
            'database_name' => $databaseName,
            'route_revision' => self::RouteRevision,
            'recovery_generation' => self::Generation,
            'status' => $status,
            'attempt_generation' => 1,
            'receipt_version' => 1,
            'observed_effect' => $effect,
            'cancel_requested' => $cancel,
            'export_id' => $exportId,
            'export_ref' => $exportRef,
            'destination_ref' => self::DestinationRef,
            'retention_ref' => self::PolicyRef,
            'manifest' => $manifest,
            'backend_pid' => null,
            'backend_start' => null,
            'database_oid' => null,
        ];
    }
}
