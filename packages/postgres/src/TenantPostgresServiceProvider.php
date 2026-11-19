<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Tenant\Postgres\Registrars\TenantPostgresRegistrar;

/**
 * Registers the PostgreSQL catalog schema sets and physical isolation metadata.
 */
#[Api(since: '1.0')]
final class TenantPostgresServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        TenantPostgresRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->bootRegistrars();
    }
}
