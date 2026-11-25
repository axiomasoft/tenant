<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Recovery;

use Closure;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use Tenant\Exceptions\OperationUnknown;
use Tenant\Exceptions\ResourceQuarantined;
use Tenant\Postgres\Internal\Database\DbRow;
use Throwable;

/** Extracted unchanged target/session proof from the P4.1 provision journal. */
#[Internal]
final readonly class PhysicalFence
{
    public function __construct(private Connection $connection) {}

    /**
     * @template T
     *
     * @param  Closure(Closure(): void): T  $work
     * @return T
     */
    public function run(string $database, Closure $work): mixed
    {
        if ($this->connection->transactionLevel() !== 0) {
            throw new OperationUnknown('Physical operation requires a nontransactional session.');
        }
        $pdo = $this->connection->getPdo();
        $pid = DbRow::raw(DbRow::expect($this->connection->selectOne('SELECT pg_backend_pid() AS pid')), 'pid');
        $key = 'tenant:physical:'.$database;
        $acquired = DbRow::raw(DbRow::expect($this->connection->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS locked', [$key])), 'locked');
        if (! in_array($acquired, [true, 't', 1], true)) {
            throw new ResourceQuarantined('Physical target is fenced by another backend.');
        }
        $fence = function () use ($pdo, $pid, $key): void {
            if ($this->connection->getPdo() !== $pdo || DbRow::raw(DbRow::expect($this->connection->selectOne('SELECT pg_backend_pid() AS pid')), 'pid') !== $pid) {
                throw new OperationUnknown('Physical session lost; outcome unknown.');
            }
            $held = DbRow::raw(DbRow::expect($this->connection->selectOne("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid() AND granted AND objsubid=1 AND classid=((hashtextextended(?,0) >> 32) & 4294967295)::oid AND objid=(hashtextextended(?,0) & 4294967295)::oid) AS held", [$key, $key])), 'held');
            if (! in_array($held, [true, 't', 1], true)) {
                throw new OperationUnknown('Physical fence lost; outcome unknown.');
            }
        };
        try {
            $fence();

            return $work($fence);
        } finally {
            try {
                if ($this->connection->getPdo() === $pdo) {
                    $this->connection->select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$key]);
                }
            } catch (Throwable) {
            }
        }
    }
}
