<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Database;

use Closure;
use Illuminate\Database\PostgresConnection;
use Tenant\Internal\Runtime\GenerationGuard;

/** @internal Qualified SQL/query-builder operations. Escaped native PDO is outside the contract. */
final class GuardedConnection extends PostgresConnection
{
    private ?Closure $transactionRelease = null;
    /** @param array<string, mixed> $config */
    public function __construct(mixed $pdo, string $database, string $prefix, array $config, private readonly GenerationGuard $guard)
    {
        parent::__construct($pdo, $database, $prefix, $config);
    }
    /** @param array<mixed> $bindings */
    protected function run($query, $bindings, Closure $callback)
    {
        $this->guard->check();
        return parent::run($query, $bindings, $callback);
    }
    public function getPdo() { $this->guard->check(); return parent::getPdo(); }
    public function getReadPdo() { $this->guard->check(); return parent::getReadPdo(); }
    public function beginTransaction()
    {
        $this->guard->check();
        $release = $this->transactions === 0 ? $this->guard->acquire() : null;
        if ($release !== null) $this->transactionRelease = $release;
        try { parent::beginTransaction(); }
        catch (\Throwable $failure) {
            $release?->__invoke();
            if ($release !== null) $this->transactionRelease = null;
            throw $failure;
        }
    }
    protected function fireConnectionEvent($event)
    {
        if ($event === 'beganTransaction' && $this->transactions === 1 && $this->transactionsManager !== null) {
            // Laravel records the transaction before this event. Register first,
            // before host listeners: PDO commit must complete before the lease
            // closes, and the lease must close before user afterCommit callbacks.
            $this->transactionsManager->addCallback(function (): void {
                if ($this->transactions === 0) {
                    $release = $this->transactionRelease;
                    $this->transactionRelease = null;
                    $release?->__invoke();
                }
            });
        }
        return parent::fireConnectionEvent($event);
    }
    public function commit()
    {
        $this->guard->check();
        parent::commit();
        if ($this->transactions === 0) { ($this->transactionRelease)?->__invoke(); $this->transactionRelease = null; }
    }
    public function rollBack($toLevel = null)
    {
        $this->guard->check();
        parent::rollBack($toLevel);
        if ($this->transactions === 0) { ($this->transactionRelease)?->__invoke(); $this->transactionRelease = null; }
    }
    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->guard->check();
        try { return parent::transaction($callback, $attempts); }
        finally {
            // Laravel's transaction helper commits PDO directly rather than calling commit().
            if ($this->transactions === 0) { ($this->transactionRelease)?->__invoke(); $this->transactionRelease = null; }
        }
    }
    /**
     * @param array<mixed> $bindings
     * @param array<mixed> $fetchUsing
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->guard->check();
        if ($fetchUsing !== [] && count((new \ReflectionMethod(\Illuminate\Database\Connection::class, 'cursor'))->getParameters()) < 4) throw new \Tenant\Exceptions\TenantFailure('unsupported_capability');
        $release = $this->guard->acquire();
        try {
            foreach (parent::cursor($query, $bindings, $useReadPdo, ...($fetchUsing === [] ? [] : [$fetchUsing])) as $row) { $this->guard->check(); yield $row; }
        } finally { $release(); }
    }
}
