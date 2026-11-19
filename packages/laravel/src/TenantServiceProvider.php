<?php

declare(strict_types=1);

namespace Tenant\Laravel;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Tenant\Laravel\Registrars\TenantFacilitiesRegistrar;
use Tenant\Laravel\Registrars\TenantQueueRegistrar;
use Tenant\Laravel\Registrars\TenantRuntimeRegistrar;

/** Registers a single Tenant runtime; host supplies resolver, verifier, and optional composition. */
#[Api(since: '1.0')]
final class TenantServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        TenantRuntimeRegistrar::class,
        TenantFacilitiesRegistrar::class,
        TenantQueueRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        if (! $this->app->bound('config') || is_array($this->app->make('config')->get('tenant', []))) {
            $this->mergeConfigFrom(__DIR__.'/../config/tenant.php', 'tenant');
        }
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->bootRegistrars();
    }
}
