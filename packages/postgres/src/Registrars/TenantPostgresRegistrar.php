<?php

declare(strict_types=1);

namespace Tenant\Postgres\Registrars;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Laravel\ProblemDefinitions;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Schema\Laravel\SchemaExtensions;
use Tenant\Errors\TenantProblemDefinitions;
use Tenant\Postgres\Database\TenantPostgresExtension;

/**
 * Registers PostgreSQL central catalog schema sets, schema ownership, and extension diagnostics.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class TenantPostgresRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $tenantManifest = ExtensionFacetRegistrar::discoverComposerManifest(TenantProblemDefinitions::class)
            ?? dirname(__DIR__, 3).'/tenant/composer.json';
        ProblemDefinitions::register($app, $tenantManifest, TenantProblemDefinitions::class);

        $app->singletonIf(TenantPostgresExtension::class);

        ExtensionFacetRegistrar::register(
            $app,
            TenantPostgresExtension::class,
            dirname(__DIR__, 2).'/composer.json',
        );

        SchemaExtensions::register(
            $app,
            TenantPostgresExtension::class,
            'axiomasoft/tenant-postgres',
        );
    }
}
