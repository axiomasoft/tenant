<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/Scenarios.php';

it('AUTH-01 executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('AUTH-01', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

it('AUTH-02 executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('AUTH-02', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

it('STATE-01 executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('STATE-01', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

it('SELECT-01 executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('SELECT-01', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

