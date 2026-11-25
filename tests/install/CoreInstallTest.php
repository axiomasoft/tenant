<?php

declare(strict_types=1);

require_once __DIR__.'/fixtures/Scenarios.php';

it('executes CoreInstall against the package', function (): void {
    coreScenario();
    expect(true)->toBeTrue();
});
