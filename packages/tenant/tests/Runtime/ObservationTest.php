<?php

declare(strict_types=1);
use TenantTests\Scenarios;

require_once __DIR__.'/../Support/Scenarios.php';

it('EXT04-EVENTS executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = Scenarios::execute('EXT04-EVENTS', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

it('EVENT-TRACE-01 executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = Scenarios::execute('EVENT-TRACE-01', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});
