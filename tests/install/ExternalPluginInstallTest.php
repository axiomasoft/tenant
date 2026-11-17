<?php

declare(strict_types=1);

require_once __DIR__.'/fixtures/Scenarios.php';

it('executes ExternalPluginInstall against the package', function (): void {
    pluginScenario();
    expect(true)->toBeTrue();
});
