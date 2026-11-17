<?php

declare(strict_types=1);

namespace Tenant\Panels\Tests;

use Packman\PackmanServiceProvider;
use Packman\Panels\Contracts\Admission\PanelAdmissionGate;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Testkit\PackageTestCase;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Panels\Contracts\PanelScopeResolver;
use Tenant\Panels\TenantPanelsServiceProvider;
use Tenant\Panels\Tests\Fixtures\ControlPanel;
use Tenant\Panels\Tests\Fixtures\PanelHost;
use Tenant\Panels\Tests\Fixtures\WorkspacePanel;

abstract class PanelTestCase extends PackageTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PackmanServiceProvider::class, PanelsServiceProvider::class, TenantPanelsServiceProvider::class, WorkspacePanel::class, ControlPanel::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $host = new PanelHost;
        $app->instance(PanelHost::class, $host);
        $app->instance(PanelScopeResolver::class, $host);
        $app->instance(PanelAdmissionGate::class, $host);
        $app->instance(TenantRuntime::class, $host->rig->runtime);
    }
}
