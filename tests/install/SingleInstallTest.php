<?php

declare(strict_types=1);

require_once __DIR__.'/fixtures/Scenarios.php';

it('executes SingleInstall against the package', function (): void { singleScenario(); expect(true)->toBeTrue(); });
