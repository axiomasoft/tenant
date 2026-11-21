<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Events\Dispatcher;
use Tenant\Laravel\Internal\Database\GuardedConnection;
use Tenant\Runtime\GenerationGuard;

it('keeps the lease when a committing listener interrupts a transaction', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $leases = 0;
    $guard = new GenerationGuard(static function (): void {}, static function (int $change) use (&$leases): void {
        $leases += $change;
    });
    $connection = new GuardedConnection($pdo, ':memory:', '', [], $guard);
    $events = new Dispatcher;
    $events->listen(TransactionCommitting::class, static function (): never {
        throw new RuntimeException('Commit interrupted.');
    });
    $connection->setEventDispatcher($events);

    expect(fn () => $connection->transaction(static fn (): null => null))
        ->toThrow(RuntimeException::class, 'Commit interrupted.')
        ->and($connection->transactionLevel())->toBe(1)
        ->and($pdo->inTransaction())->toBeTrue()
        ->and($leases)->toBe(1);

    $connection->rollBack();

    expect($pdo->inTransaction())->toBeFalse()
        ->and($leases)->toBe(0);
});
