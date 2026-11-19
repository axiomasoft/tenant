<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use Packman\Attributes\Api;

/** One catalog connection, Laravel migration repository and package-owned set. */
#[Api(since: '1.0')]
final class PostgresSchema
{
    public const string Connection = 'tenant_pg_catalog';

    public const string Repository = 'migrations';

    public static function migrationPath(): string
    {
        return __DIR__.'/../database/migrations';
    }
}
