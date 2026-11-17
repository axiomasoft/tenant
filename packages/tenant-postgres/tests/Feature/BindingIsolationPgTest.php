<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Data\Verification\VerifiedBinding;
use Tenant\Exceptions\Verification\BindingMismatch;
use Tenant\Postgres\Data\Binding\PhysicalAccess;
use Tenant\Postgres\Internal\Binding\PostgresBindingVerifier;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Tests\Feature\TenantPostgresTestCase;
use Tenant\Postgres\Tests\Support\BindingIsolationRig;

uses(TenantPostgresTestCase::class)->group('pg');

beforeEach(function (): void {
    TestDatabaseGuard::assertDestructiveSafe(SuiteDatabaseConnection::pgsqlIdentity());
    DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS public CASCADE');
    DB::connection('pgsql')->statement('CREATE SCHEMA public');
    DB::purge('pgsql');
    $this->migrateSchemaSets(['axiomasoft/tenant-postgres:tenant-postgres'], 'pgsql');
});

it('opens ordinary bindings only after technical readiness while allowing owner verification during provisioning', function (): void {
    $rig = new BindingIsolationRig(DB::connection('pgsql'));
    try {
        foreach (['ready', 'active'] as $status) {
            $rig->status($status);
            expect($rig->verifier->verify($rig->input)->recoveryGeneration)->toBe('generation:1');
        }
        foreach (['pending', 'provisioning', 'exporting', 'exported', 'deleting', 'quarantined', 'recovering', 'deleted', 'unknown', ''] as $status) {
            $rig->status($status);
            expect(fn (): VerifiedBinding => $rig->verifier->verify($rig->input))->toThrow(BindingMismatch::class);
        }
        $rig->status('provisioning');
        expect($rig->maintenance->verify($rig->input)->recoveryGeneration)->toBe('generation:1');
    } finally {
        $rig->close();
    }
});

it('refuses marker mutation through column privileges even without table privileges', function (): void {
    $rig = new BindingIsolationRig(DB::connection('pgsql'));
    try {
        foreach (['INSERT', 'UPDATE', 'REFERENCES'] as $privilege) {
            $rig->connection->statement('GRANT '.$privilege.' (resource_ref) ON tenant_binding TO "'.$rig->role.'"');
            $table = $rig->connection->selectOne("SELECT has_table_privilege(?, 'tenant_binding', ?) AS permitted", [$rig->role, $privilege]);
            expect($table->permitted)->toBeFalse();
            expect(fn (): VerifiedBinding => $rig->verifier->verify($rig->input))->toThrow(BindingMismatch::class);
            expect(fn (): VerifiedBinding => $rig->maintenance->verify($rig->input))->toThrow(BindingMismatch::class);
            $rig->connection->statement('REVOKE '.$privilege.' (resource_ref) ON tenant_binding FROM "'.$rig->role.'"');
        }
        expect($rig->verifier->verify($rig->input)->recoveryGeneration)->toBe('generation:1');
    } finally {
        $rig->close();
    }
});

it('refuses marker owners even after ordinary write privileges have been revoked', function (): void {
    $rig = new BindingIsolationRig(DB::connection('pgsql'));
    try {
        $rig->connection->statement('ALTER TABLE tenant_binding OWNER TO "'.$rig->role.'"');
        $rig->connection->statement('REVOKE ALL ON tenant_binding FROM "'.$rig->role.'"');
        $rig->connection->statement('GRANT SELECT ON tenant_binding TO "'.$rig->role.'"');
        $table = $rig->connection->selectOne("SELECT has_table_privilege(?, 'tenant_binding', 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER') AS permitted", [$rig->role]);
        expect($table->permitted)->toBeFalse();
        expect(fn (): VerifiedBinding => $rig->verifier->verify($rig->input))->toThrow(BindingMismatch::class);
    } finally {
        $rig->close();
    }
});

it('refuses connector aliases that connect to a different database or port than the declared route', function (): void {
    $rig = new BindingIsolationRig(DB::connection('pgsql'));
    try {
        $base = [...DbRow::configMap($rig->connection), 'username' => $rig->role, 'password' => 'binding-test-password'];
        foreach ([
            ['database' => 'tenant_binding_not_the_declared_target_test', 'connect_via_database' => $rig->connection->getDatabaseName()],
            ['port' => 1, 'connect_via_port' => $base['port']],
        ] as $overrides) {
            $route = [...$base, ...$overrides];
            $rig->connection->table('tenant_pg_resources')->update(['database_name' => $route['database']]);
            $input = new BindingVerificationInput($rig->input->expected, new PhysicalAccess($route));
            $verifier = new PostgresBindingVerifier($rig->connection, static fn (object $row): array => $route);
            expect(fn (): VerifiedBinding => $verifier->verify($input))->toThrow(BindingMismatch::class);
        }
    } finally {
        $rig->close();
    }
});

it('refuses schema owners even when their current create privilege is revoked', function (): void {
    $rig = new BindingIsolationRig(DB::connection('pgsql'));
    try {
        $rig->connection->statement('ALTER SCHEMA public OWNER TO "'.$rig->role.'"');
        $rig->connection->statement('REVOKE CREATE ON SCHEMA public FROM "'.$rig->role.'"');
        $schema = $rig->connection->selectOne("SELECT has_schema_privilege(?, 'public', 'CREATE') AS permitted", [$rig->role]);
        expect($schema->permitted)->toBeFalse();
        expect(fn (): VerifiedBinding => $rig->verifier->verify($rig->input))->toThrow(BindingMismatch::class);
    } finally {
        $rig->connection->statement('ALTER SCHEMA public OWNER TO CURRENT_USER');
        $rig->close();
    }
});

it('refuses a privileged login hidden behind a restricted current role', function (): void {
    $rig = new BindingIsolationRig(DB::connection('pgsql'));
    try {
        $route = [...DbRow::configMap($rig->connection), 'server_options' => ['role' => $rig->role]];
        $input = new BindingVerificationInput($rig->input->expected, new PhysicalAccess($route));
        $verifier = new PostgresBindingVerifier($rig->connection, static fn (object $row): array => $route);
        expect(fn (): VerifiedBinding => $verifier->verify($input))->toThrow(BindingMismatch::class);
    } finally {
        $rig->close();
    }
});

it('refuses write privileges reachable through noninherited role membership', function (string $privilege): void {
    $rig = new BindingIsolationRig(DB::connection('pgsql'));
    $writer = 'tenant_writer_'.bin2hex(random_bytes(8)).'_test';
    $rig->connection->statement('CREATE ROLE "'.$writer.'"');
    try {
        $rig->connection->statement('ALTER ROLE "'.$rig->role.'" NOINHERIT');
        $rig->connection->statement('GRANT "'.$writer.'" TO "'.$rig->role.'"');
        $grant = match ($privilege) {
            'schema' => 'CREATE ON SCHEMA public',
            'database' => 'CREATE ON DATABASE "'.str_replace('"', '""', $rig->connection->getDatabaseName()).'"',
            default => $privilege.' ON tenant_binding',
        };
        $rig->connection->statement('GRANT '.$grant.' TO "'.$writer.'"');
        $table = $rig->connection->selectOne("SELECT has_table_privilege(?, 'tenant_binding', 'UPDATE') AS table_write, has_any_column_privilege(?, 'tenant_binding', 'UPDATE') AS column_write", [$rig->role, $rig->role]);
        expect($table->table_write)->toBeFalse()->and($table->column_write)->toBeFalse();
        $objects = $rig->connection->selectOne("SELECT has_schema_privilege(?, 'public', 'CREATE') AS schema_create, has_database_privilege(?, current_database(), 'CREATE') AS database_create", [$rig->role, $rig->role]);
        expect($objects->schema_create)->toBeFalse()->and($objects->database_create)->toBeFalse();
        expect(fn (): VerifiedBinding => $rig->verifier->verify($rig->input))->toThrow(BindingMismatch::class);
    } finally {
        $rig->connection->statement('DROP OWNED BY "'.$writer.'"');
        $rig->connection->statement('DROP ROLE "'.$writer.'"');
        $rig->close();
    }
})->with(['UPDATE', 'UPDATE (resource_ref)', 'schema', 'database']);
