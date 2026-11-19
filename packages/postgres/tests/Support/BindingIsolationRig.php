<?php

declare(strict_types=1);

namespace Tenant\Postgres\Tests\Support;

use Illuminate\Database\Connection;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Postgres\Data\Binding\PhysicalAccess;
use Tenant\Postgres\Internal\Binding\PostgresBindingVerifier;
use Tenant\Postgres\Internal\Database\DbRow;

final readonly class BindingIsolationRig
{
    public string $role;

    public BindingVerificationInput $input;

    public PostgresBindingVerifier $verifier;

    public PostgresBindingVerifier $maintenance;

    public function __construct(public Connection $connection)
    {
        $this->role = 'tenant_binding_'.bin2hex(random_bytes(8)).'_test';
        $connection->statement('CREATE ROLE "'.$this->role.'" LOGIN PASSWORD \'binding-test-password\'');
        $connection->statement('CREATE TABLE tenant_binding (singleton boolean PRIMARY KEY CHECK(singleton), protocol text NOT NULL, owner_id text NOT NULL, resource_ref text NOT NULL, incarnation text NOT NULL, recovery_generation text NOT NULL)');
        $connection->table('tenant_binding')->insert([
            'singleton' => true,
            'protocol' => 'tenant.binding/1',
            'owner_id' => 'tenant-postgres',
            'resource_ref' => 'resource:binding-test',
            'incarnation' => 'incarnation:1',
            'recovery_generation' => 'generation:1',
        ]);
        $connection->statement('REVOKE ALL ON tenant_binding FROM PUBLIC');
        $connection->statement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        $connection->statement('GRANT USAGE ON SCHEMA public TO "'.$this->role.'"');
        $connection->statement('GRANT SELECT ON tenant_binding TO "'.$this->role.'"');
        $connection->table('tenant_pg_resources')->insert([
            'id' => '33333333-3333-4333-8333-333333333333',
            'cluster_id' => '11111111-1111-4111-8111-111111111111',
            'template_version' => 1,
            'status' => 'ready',
            'resource_ref' => 'resource:binding-test',
            'incarnation' => 'incarnation:1',
            'route_revision' => 'route:1',
            'database_name' => $connection->getDatabaseName(),
            'recovery_generation' => 'generation:1',
        ]);
        $route = [...DbRow::configMap($connection), 'username' => $this->role, 'password' => 'binding-test-password'];
        $this->input = new BindingVerificationInput(
            new BindingExpectation('resource:binding-test', 'incarnation:1', 'route:1'),
            new PhysicalAccess($route),
        );
        $this->verifier = new PostgresBindingVerifier($connection, static fn (object $row): array => $route);
        $this->maintenance = new PostgresBindingVerifier($connection, static fn (object $row): array => $route, maintenance: true);
    }

    public function status(string $status): void
    {
        $this->connection->table('tenant_pg_resources')->where('resource_ref', 'resource:binding-test')->update(['status' => $status]);
    }

    public function close(): void
    {
        $this->connection->statement('DROP OWNED BY "'.$this->role.'"');
        $this->connection->statement('DROP ROLE "'.$this->role.'"');
    }
}
