<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/Scenarios.php';

it('EXT01-PLUGIN executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('EXT01-PLUGIN', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

it('EXT02-ORDER executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('EXT02-ORDER', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

it('EXT02-NEGATIVE executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('EXT02-NEGATIVE', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

