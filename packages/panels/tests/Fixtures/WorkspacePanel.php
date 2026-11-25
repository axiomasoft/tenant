<?php

declare(strict_types=1);

namespace Tenant\Panels\Tests\Fixtures;

use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider;
use Packman\Panels\Enums\Admission\AssignmentScopeMode;
use Packman\Panels\Enums\Admission\TenantMode;

final class WorkspacePanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'workspace';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(PanelUser::class)
            ->tenantMode(TenantMode::Required)
            ->scopeMode(AssignmentScopeMode::Inherit)
            ->scopeTypes(['team'])
            ->entry('workspace.enter');
    }
}
