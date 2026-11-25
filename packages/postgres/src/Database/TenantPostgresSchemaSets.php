<?php

declare(strict_types=1);

namespace Tenant\Postgres\Database;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;

/**
 * Declares the central PostgreSQL catalog migration set owned by `axiomasoft/tenant-postgres`.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class TenantPostgresSchemaSets implements SchemaSetProvider
{
    /** @return list<SchemaSet> */
    #[Override]
    public function sets(): array
    {
        return [
            new SchemaSet(
                package: 'axiomasoft/tenant-postgres',
                name: 'tenant-postgres',
                scope: SchemaScope::Central,
                path: dirname(__DIR__, 2).'/database/migrations',
                version: 1,
                drivers: ['pgsql'],
                lifecycle: SchemaLifecycle::Platform,
            ),
        ];
    }
}
