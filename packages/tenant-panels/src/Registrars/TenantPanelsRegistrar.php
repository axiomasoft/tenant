<?php

declare(strict_types=1);

namespace Tenant\Panels\Registrars;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;
use Tenant\Panels\PanelTenant;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class TenantPanelsRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        // Resolve host-owned runtime, scope mapping and admission only when the adapter is used.
        $app->singletonIf(PanelTenant::class);
    }
}
