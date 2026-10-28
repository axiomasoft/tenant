<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/Scenarios.php';

it('EXT03-SNAPSHOTS executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('EXT03-SNAPSHOTS', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

it('JOURNAL-01 executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('JOURNAL-01', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

