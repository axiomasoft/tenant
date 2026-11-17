<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use ReflectionMethod;
use Tenant\Exceptions\TenantFailure;
use Tenant\Runtime\GenerationGuard;
use Throwable;

/** @internal Qualified SQL/query-builder operations. Escaped native PDO is outside the contract. */
#[Internal]
final class GuardedConnection extends PostgresConnection
{
    /** @var (Closure(): void)|null */
    private ?Closure $transactionRelease = null;

    /** @param array<string, mixed> $config */
    public function __construct(mixed $pdo, string $database, string $prefix, array $config, private readonly GenerationGuard $guard)
    {
        parent::__construct($pdo, $database, $prefix, $config);
    }

    /**
     * @param  array<mixed>  $bindings
     * @param  Closure(string, array<mixed>): mixed  $callback
     */
    protected function run($query, $bindings, Closure $callback)
    {
        $this->guard->check();

        return parent::run($query, $bindings, $callback);
    }

    public function getPdo()
    {
        $this->guard->check();

        return parent::getPdo();
    }

    public function getReadPdo()
    {
        $this->guard->check();

        return parent::getReadPdo();
    }

    public function beginTransaction(): void
    {
        $this->guard->check();
        $release = $this->transactions === 0 ? $this->guard->acquire() : null;
        if ($release !== null) {
            $this->transactionRelease = $release;
        }
        try {
            parent::beginTransaction();
        } catch (Throwable $failure) {
            $release?->__invoke();
            if ($release !== null) {
                $this->transactionRelease = null;
            }
            throw $failure;
        }
    }

    /** @return array<mixed>|null */
    protected function fireConnectionEvent($event)
    {
        if ($event === 'beganTransaction' && $this->transactionsManager !== null) {
            // Laravel records the transaction before this event. Register first,
            // before host listeners: PDO commit must complete before the lease
            // closes, and the lease must close before user afterCommit callbacks.
            // Laravel executes child records before their parent. Every savepoint
            // needs this first callback, while only the outermost commit releases.
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

    public function commit(): void
    {
        $this->guard->check();
        parent::commit();
        if ($this->transactions === 0) {
            ($this->transactionRelease)?->__invoke();
            $this->transactionRelease = null;
        }
    }

    public function rollBack($toLevel = null): void
    {
        $this->guard->check();
        parent::rollBack($toLevel);
        if ($this->transactions === 0) {
            ($this->transactionRelease)?->__invoke();
            $this->transactionRelease = null;
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(static): TReturn  $callback
     * @param  int  $attempts
     * @return TReturn
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->guard->check();
        if ($attempts < 1) {
            throw new InvalidArgumentException('Transaction attempts must be at least 1.');
        }
        try {
            return parent::transaction($callback, $attempts);
        } finally {
            // Laravel's transaction helper commits PDO directly rather than calling commit().
            if ($this->transactions === 0) {
                ($this->transactionRelease)?->__invoke();
                $this->transactionRelease = null;
            }
        }
    }

    /**
     * @param  array<mixed>  $bindings
     * @param  array<mixed>  $fetchUsing
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->guard->check();
        if ($fetchUsing !== [] && count(new ReflectionMethod(Connection::class, 'cursor')->getParameters()) < 4) {
            throw new TenantFailure('unsupported_capability');
        }
        $release = $this->guard->acquire();
        try {
            foreach (parent::cursor($query, $bindings, $useReadPdo, ...($fetchUsing === [] ? [] : [$fetchUsing])) as $row) {
                $this->guard->check();
                yield $row;
            }
        } finally {
            $release();
        }
    }
}
