<?php

declare(strict_types=1);

use Tenant\Postgres\Internal\Database\PostgresIdentifier;

it('quotes only allowlisted PostgreSQL identifiers', function (): void {
    expect(PostgresIdentifier::quote('archive_test'))->toBe('"archive_test"')
        ->and(PostgresIdentifier::isValid('A_1'))->toBeTrue()
        ->and(PostgresIdentifier::isValid(str_repeat('a', 63)))->toBeTrue()
        ->and(PostgresIdentifier::isValid(''))->toBeFalse()
        ->and(PostgresIdentifier::isValid(str_repeat('a', 64)))->toBeFalse()
        ->and(PostgresIdentifier::isValid('archive"test'))->toBeFalse()
        ->and(PostgresIdentifier::isValid('archive-test'))->toBeFalse()
        ->and(PostgresIdentifier::isValid("archive\0"))->toBeFalse();

    expect(fn (): string => PostgresIdentifier::quote('archive"test'))
        ->toThrow(RuntimeException::class, 'Invalid PostgreSQL identifier.');
});

it('does not interpolate raw identifiers into physical DDL', function (): void {
    $root = dirname(__DIR__, 2).'/src';
    $clone = (string) file_get_contents($root.'/Internal/Database/TemplateClonePostgreSQLManager.php');
    $journal = (string) file_get_contents($root.'/Internal/Lifecycle/LifecycleJournal.php');
    $marker = (string) file_get_contents($root.'/Internal/Database/MarkerWriter.php');

    expect($clone)->not->toContain('CREATE DATABASE "')
        ->and($journal)->not->toContain('DROP DATABASE "')
        ->and($marker)->not->toContain('runtimeRole.\'"');
});
