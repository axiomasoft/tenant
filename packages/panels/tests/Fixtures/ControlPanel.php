<?php

declare(strict_types=1);

namespace Tenant\Panels\Tests\Fixtures;

use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider;

final class ControlPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'control';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(PanelUser::class);
    }
}
