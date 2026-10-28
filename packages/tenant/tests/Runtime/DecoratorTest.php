<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/Scenarios.php';

it('EXT05-OWNER executes original synthetic contract through default and decorator', function (): void {
    foreach ([false, true] as $decorated) {
        $result = \TenantTests\Scenarios::execute('EXT05-OWNER', $decorated);
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});

