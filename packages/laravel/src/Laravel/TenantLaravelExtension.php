<?php

declare(strict_types=1);

namespace Tenant\Laravel\Laravel;

use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Tenant\Errors\TenantProblemDefinitions;
use Tenant\Laravel\Configuration\TenantOptions;
use Tenant\Laravel\Diagnostics\TenantDoctorCheck;

/**
 * Declarative Packman extension facet descriptor for `axiomasoft/tenant-laravel`.
 */
#[Api(since: '1.0')]
final class TenantLaravelExtension implements WithConfiguration, WithDiagnostics
{
    use Configurable;
    use ProvidesDiagnostics;

    #[Override]
    public function configRoot(): string
    {
        return 'tenant';
    }

    #[Override]
    public function optionsClass(): string
    {
        return TenantOptions::class;
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [TenantDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [TenantProblemDefinitions::class];
    }
}
