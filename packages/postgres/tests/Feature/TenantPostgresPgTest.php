<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Laravel\PackmanMigration;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use Tenant\Exceptions\Context\ContextDenied;
use Tenant\Postgres\Admission;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\Contracts\Provisioning\ResourceBindings;
use Tenant\Postgres\Data\Provisioning\TemplateTrust;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Database\TemplateIntegrityLock;
use Tenant\Postgres\OperationKey;
use Tenant\Postgres\OperationReceipt;
use Tenant\Postgres\PostgresFactory;
use Tenant\Postgres\PostgresSchema;
use Tenant\Postgres\ProvisioningOwner;
use Tenant\Postgres\ProvisionIntent;
use Tenant\Postgres\Tests\Feature\TenantPostgresTestCase;

uses(TenantPostgresTestCase::class)->group('pg');

beforeEach(function (): void {
    TestDatabaseGuard::assertDestructiveSafe(SuiteDatabaseConnection::pgsqlIdentity());
    DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS public CASCADE');
    DB::connection('pgsql')->statement('CREATE SCHEMA public');
    DB::purge('pgsql');
});

it('migrates TenantPostgresSchemaSets via PackmanMigration onto PostgreSQL and runs catalog/runtime checks', function (): void {
    $migrationFiles = glob(PostgresSchema::migrationPath().'/*.php');
    expect($migrationFiles)->toBeArray()->toHaveCount(7);
    foreach ($migrationFiles ?: [] as $file) {
        $migration = require $file;
        expect($migration)->toBeInstanceOf(PackmanMigration::class);
    }

    $this->migrateSchemaSets(['axiomasoft/tenant-postgres:tenant-postgres'], 'pgsql');

    foreach ([
        'tenant_pg_clusters',
        'tenant_pg_templates',
        'tenant_pg_resources',
        'tenant_pg_operations',
        'tenant_pg_provision_attempts',
        'tenant_pg_control',
        'tenant_pg_lifecycle_operations',
        'tenant_pg_lifecycle_outbox',
    ] as $table) {
        expect(Schema::connection('pgsql')->hasTable($table))->toBeTrue();
    }
    expect(Schema::connection('pgsql')->hasColumn('tenant_pg_resources', 'capacity_released'))->toBeTrue();

    expect($this->app->make(SchemaMigrator::class)->migrateSets(['axiomasoft/tenant-postgres:tenant-postgres'], 'pgsql'))
        ->toBe([]);

    $templateId = '12121212-1212-4212-8212-121212121212';
    $clusterId = '11111111-1111-4111-8111-111111111111';
    $connection = DB::connection('pgsql');

    $connection->transaction(static function () use ($connection, $templateId): void {
        (new TemplateIntegrityLock)->acquire($connection, $templateId);
        $locked = $connection->selectOne(
            "SELECT count(*) AS cnt FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()",
        );
        expect((int) ($locked->cnt ?? 0))->toBeGreaterThanOrEqual(1);
    });

    $connection->table('tenant_pg_clusters')->insert([
        'id' => $clusterId,
        'status' => 'active',
        'template_version' => 7,
        'database_count' => 2,
        'capacity' => 10,
    ]);
    $connection->table('tenant_pg_control')->insert([
        'id' => 1,
        'recovery_generation' => 'gen-1',
    ]);

    $clusterRow = $connection->table('tenant_pg_clusters')->where('id', $clusterId)->first();
    expect($clusterRow)->not->toBeNull()
        ->and(DbRow::str($clusterRow, 'status'))->toBe('active')
        ->and(DbRow::int($clusterRow, 'template_version'))->toBe(7)
        ->and(DbRow::int($clusterRow, 'database_count'))->toBe(2)
        ->and(DbRow::int($clusterRow, 'capacity'))->toBe(10);

    $anchor = new class implements RecoveryAnchor
    {
        public function generation(): string
        {
            return 'gen-1';
        }

        /** @return array<string, array<string, mixed>> */
        public function entries(): array
        {
            return [];
        }

        /** @param array<string, mixed> $original */
        public function remember(string $keyHash, array $original): void {}

        public function advance(string $expectedGeneration): string
        {
            return $expectedGeneration;
        }
    };

    $bindings = PostgresFactory::bindings(
        $connection,
        static fn (object $row): array => [
            'driver' => 'pgsql',
            'host' => $connection->getConfig('host'),
            'port' => $connection->getConfig('port'),
            'database' => DbRow::str($row, 'database_name'),
        ],
        $anchor,
    );
    expect($bindings)->toBeInstanceOf(ResourceBindings::class);

    $owner = PostgresFactory::owner(
        $connection,
        $this->app->make('db'),
        new TemplateTrust([], []),
        static fn (object $row): array => [
            'driver' => 'pgsql',
            'host' => $connection->getConfig('host'),
            'port' => $connection->getConfig('port'),
            'database' => DbRow::str($row, 'database_name'),
        ],
        static fn (string $id): string => 'tenant_'.$id.'_test',
        'tenant_runtime',
        ['protocol' => 'tenant.binding/1', 'owner_id' => 'template', 'resource_ref' => 'template', 'incarnation' => 'template', 'recovery_generation' => 'template'],
        anchor: $anchor,
    );
    expect($owner)->toBeInstanceOf(ProvisioningOwner::class);

    $deny = new Admission(static fn (): bool => false);
    expect(static fn (): OperationReceipt => $owner->claim(
        new ProvisionIntent('host:test', $clusterId, $templateId, 7, str_repeat('a', 64), []),
        new OperationKey('ns', 'op-1'),
        $deny,
    ))->toThrow(ContextDenied::class);
});
