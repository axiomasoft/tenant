<?php

declare(strict_types=1);

namespace Tenant\Postgres\Tests\Feature;

use Override;
use Packman\PackmanServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Schema\Testing\MigratesSchemaSets;
use Packman\Testkit\PackageTestCase;
use Tenant\Postgres\TenantPostgresServiceProvider;

abstract class TenantPostgresTestCase extends PackageTestCase
{
    use MigratesSchemaSets {
        migrateSchemaSets as public;
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            SchemaServiceProvider::class,
            TenantPostgresServiceProvider::class,
        ];
    }
}
