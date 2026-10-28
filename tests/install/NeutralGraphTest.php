<?php

declare(strict_types=1);

require_once __DIR__.'/fixtures/Scenarios.php';

it('executes NeutralGraph against the package', function (): void { graphScenario(); expect(true)->toBeTrue(); });
