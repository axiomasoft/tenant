<?php

declare(strict_types=1);

namespace Tenant\Postgres;

/** One catalog connection, Laravel migration repository and package-owned set. */
final class PostgresSchema
{
    public const string Connection = 'tenant_pg_catalog';
    public const string Repository = 'migrations';

    public static function migrationPath(): string
    {
        return dirname(__DIR__).'/database/migrations';
    }
}
