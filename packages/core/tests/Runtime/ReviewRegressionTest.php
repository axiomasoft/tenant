<?php

declare(strict_types=1);
use TenantTests\ReviewRegression;

require_once __DIR__.'/../Support/ReviewRegression.php';

foreach (['identities', 'extensions', 'proofs', 'profileCapabilities'] as $case) {
    it('enforces independent review regression '.$case.' with default and decorator', function () use ($case): void {
        foreach ([false, true] as $decorated) {
            $records = ReviewRegression::$case($decorated);
            expect(count($records))->toBeGreaterThan(0);
        }
    });
}
