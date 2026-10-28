<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/Scenarios.php';

it('STATE-04 executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('STATE-04', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

