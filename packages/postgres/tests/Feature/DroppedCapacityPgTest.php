<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use Tenant\Postgres\Exceptions\Claim\ClaimUnavailable;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Tests\Feature\TenantPostgresTestCase;
use Tenant\Postgres\Tests\Support\VerifiedDropCatalog;

uses(TenantPostgresTestCase::class)->group('pg');

beforeEach(function (): void {
    TestDatabaseGuard::assertDestructiveSafe(SuiteDatabaseConnection::pgsqlIdentity());
    DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS public CASCADE');
    DB::connection('pgsql')->statement('CREATE SCHEMA public');
    DB::purge('pgsql');
    $this->migrateSchemaSets(['axiomasoft/tenant-postgres:tenant-postgres'], 'pgsql');
});

it('returns database_count from 1 to 0 when a verified drop is published', function (bool $cancelled): void {
    $connection = DB::connection('pgsql');
    $fixture = VerifiedDropCatalog::seed($connection, 'dropped', cancelRequested: $cancelled);

    expect(VerifiedDropCatalog::databaseCount($connection))->toBe(1)
        ->and(VerifiedDropCatalog::placeableClusterId($connection))->toBeNull();

    $receipt = $fixture->journal->reconcile($fixture->operation, $fixture->admission);

    expect($receipt->status)->toBe($cancelled ? 'cancelled' : 'succeeded')
        ->and($receipt->observedEffect)->toBe('dropped')
        ->and($receipt->cancellationRequested)->toBe($cancelled)
        ->and(VerifiedDropCatalog::databaseCount($connection))->toBe(0)
        ->and(VerifiedDropCatalog::placeableClusterId($connection))->toBe(VerifiedDropCatalog::ClusterId);
    $resource = $connection->table('tenant_pg_resources')->where('id', VerifiedDropCatalog::ResourceId)->first();
    expect($resource)->not->toBeNull();
    expect(DbRow::str($resource, 'status'))->toBe('deleted');
    expect(DbRow::raw($resource, 'deleted_at'))->not->toBeNull();
    $outbox = $connection->table('tenant_pg_lifecycle_outbox')->where('operation_id', $fixture->operationId)->first();
    expect($outbox)->not->toBeNull();
    expect(DbRow::str($outbox, 'effect'))->toBe('dropped');
})->with([
    'succeeded' => [false],
    'cancelled' => [true],
]);

it('does not release cluster capacity when the published effect is not a drop', function (): void {
    $connection = DB::connection('pgsql');
    $fixture = VerifiedDropCatalog::seed($connection, 'archived');

    $receipt = $fixture->journal->reconcile($fixture->operation, $fixture->admission);

    expect($receipt->status)->toBe('succeeded')
        ->and($receipt->observedEffect)->toBe('archived')
        ->and(VerifiedDropCatalog::databaseCount($connection))->toBe(1)
        ->and(VerifiedDropCatalog::placeableClusterId($connection))->toBeNull();
});

it('does not release cluster capacity when the drop has not been observed', function (): void {
    $connection = DB::connection('pgsql');
    $fixture = VerifiedDropCatalog::seed($connection, 'unobserved');

    $receipt = $fixture->journal->reconcile($fixture->operation, $fixture->admission);

    expect($receipt->status)->toBe('unknown')
        ->and($receipt->observedEffect)->toBe('quarantined')
        ->and(VerifiedDropCatalog::databaseCount($connection))->toBe(1);
    $resource = $connection->table('tenant_pg_resources')->where('id', VerifiedDropCatalog::ResourceId)->first();
    expect($resource)->not->toBeNull();
    expect(DbRow::str($resource, 'status'))->toBe('quarantined');
});

it('rolls the publication back when cluster database_count is already zero', function (): void {
    $connection = DB::connection('pgsql');
    $fixture = VerifiedDropCatalog::seed($connection, 'dropped', 0);

    try {
        $fixture->journal->reconcile($fixture->operation, $fixture->admission);
        throw new RuntimeException('Publication should have rolled back.');
    } catch (ClaimUnavailable $exception) {
        expect($exception::class)->toBe(ClaimUnavailable::class);
    }

    expect(VerifiedDropCatalog::databaseCount($connection))->toBe(0);
    $operation = $connection->table('tenant_pg_lifecycle_operations')->where('id', $fixture->operationId)->first();
    expect($operation)->not->toBeNull();
    expect(DbRow::str($operation, 'status'))->toBe('running');
    expect($connection->table('tenant_pg_lifecycle_outbox')->where('operation_id', $fixture->operationId)->exists())->toBeFalse();
    $resource = $connection->table('tenant_pg_resources')->where('id', VerifiedDropCatalog::ResourceId)->first();
    expect($resource)->not->toBeNull();
    expect(DbRow::str($resource, 'status'))->toBe('deleting');
    expect(DbRow::raw($resource, 'deleted_at'))->toBeNull();
});

it('rolls the publication back when the cluster row is missing', function (): void {
    $connection = DB::connection('pgsql');
    $fixture = VerifiedDropCatalog::seed($connection, 'dropped', withCluster: false);

    try {
        $fixture->journal->reconcile($fixture->operation, $fixture->admission);
        throw new RuntimeException('Publication should have rolled back.');
    } catch (ClaimUnavailable $exception) {
        expect($exception::class)->toBe(ClaimUnavailable::class);
    }

    expect(VerifiedDropCatalog::databaseCount($connection))->toBeNull();
    $operation = $connection->table('tenant_pg_lifecycle_operations')->where('id', $fixture->operationId)->first();
    expect($operation)->not->toBeNull();
    expect(DbRow::str($operation, 'status'))->toBe('running');
    expect($connection->table('tenant_pg_lifecycle_outbox')->where('operation_id', $fixture->operationId)->exists())->toBeFalse();
    $resource = $connection->table('tenant_pg_resources')->where('id', VerifiedDropCatalog::ResourceId)->first();
    expect($resource)->not->toBeNull();
    expect(DbRow::str($resource, 'status'))->toBe('deleting');
    expect(DbRow::raw($resource, 'deleted_at'))->toBeNull();
});

it('does not release capacity when the resource update does not match one row', function (): void {
    $connection = DB::connection('pgsql');
    $fixture = VerifiedDropCatalog::seed($connection, 'dropped');
    $connection->unprepared(<<<'SQL'
        CREATE FUNCTION block_resource_capacity_update() RETURNS trigger
        LANGUAGE plpgsql AS $$
        BEGIN
            RETURN NULL;
        END;
        $$
        SQL);
    $connection->unprepared(<<<'SQL'
        CREATE TRIGGER block_resource_capacity_update
        BEFORE UPDATE ON tenant_pg_resources
        FOR EACH ROW
        EXECUTE FUNCTION block_resource_capacity_update()
        SQL);

    $receipt = $fixture->journal->reconcile($fixture->operation, $fixture->admission);

    expect($receipt->status)->toBe('succeeded')
        ->and($receipt->observedEffect)->toBe('dropped')
        ->and(VerifiedDropCatalog::databaseCount($connection))->toBe(1)
        ->and(VerifiedDropCatalog::placeableClusterId($connection))->toBeNull();
    $resource = $connection->table('tenant_pg_resources')->where('id', VerifiedDropCatalog::ResourceId)->first();
    expect($resource)->not->toBeNull();
    expect(DbRow::str($resource, 'status'))->toBe('deleting');
    expect(DbRow::raw($resource, 'deleted_at'))->toBeNull();
});
