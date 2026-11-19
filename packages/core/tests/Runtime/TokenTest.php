<?php

declare(strict_types=1);
use TenantTests\Scenarios;

require_once __DIR__.'/../Support/Scenarios.php';

it('STATE-06 executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = Scenarios::execute('STATE-06', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});
