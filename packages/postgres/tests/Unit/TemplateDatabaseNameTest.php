<?php

declare(strict_types=1);

use Tenant\Postgres\Exceptions\Provisioning\TemplateIntegrityException;
use Tenant\Postgres\Internal\Database\TemplateIntegrityVerifier;

it('refuses a template database name before opening a connection', function (): void {
    $verifier = new ReflectionClass(TemplateIntegrityVerifier::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(TemplateIntegrityVerifier::class, 'actualHashForDatabase');

    expect(fn (): string => $method->invoke($verifier, 'bad"name'))
        ->toThrow(TemplateIntegrityException::class, 'Invalid PostgreSQL identifier.');
});
