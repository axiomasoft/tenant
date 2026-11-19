<?php

declare(strict_types=1);

namespace Tenant\Postgres\Database;

use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Tenant\Errors\TenantProblemDefinitions;
use Tenant\Postgres\PostgresSchema;

/**
 * Declarative Packman extension facet descriptor for `axiomasoft/tenant-postgres`.
 */
#[Api(since: '1.0')]
final class TenantPostgresExtension implements WithDiagnostics, WithSchema
{
    use OwnsSchema;
    use ProvidesDiagnostics;

    #[Override]
    public function tablePrefix(): string
    {
        return 'tenant_pg_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return [
            'clusters',
            'templates',
            'resources',
            'operations',
            'provision_attempts',
            'control',
            'lifecycle_operations',
            'lifecycle_outbox',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return PostgresSchema::migrationPath();
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [TenantPostgresSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [TenantProblemDefinitions::class];
    }
}
