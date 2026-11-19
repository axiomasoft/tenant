<?php

declare(strict_types=1);

namespace Tenant\Panels;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Tenant\Panels\Registrars\TenantPanelsRegistrar;

#[Api(since: '1.0')]
final class TenantPanelsServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [TenantPanelsRegistrar::class];
}
