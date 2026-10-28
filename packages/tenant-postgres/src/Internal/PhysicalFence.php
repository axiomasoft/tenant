<?php

declare(strict_types=1);
namespace Tenant\Postgres\Internal;
use Closure;
use Illuminate\Database\Connection;
use Tenant\Postgres\Exceptions\ClaimUnavailable;

/** Extracted unchanged target/session proof from the P4.1 provision journal. */
final readonly class PhysicalFence
{
    public function __construct(private Connection $connection) {}
    public function run(string $database, Closure $work): mixed
    {
        if ($this->connection->transactionLevel() !== 0) throw new ClaimUnavailable('Physical operation requires a nontransactional session.');
        $pdo=$this->connection->getPdo();$pid=$this->connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;$key='tenant:physical:'.$database;
        $acquired=$this->connection->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS locked',[$key])->locked;
        if (!in_array($acquired,[true,'t',1],true)) throw new ClaimUnavailable('Physical target is fenced by another backend.');
        $fence=function()use($pdo,$pid,$key):void {
            if ($this->connection->getPdo() !== $pdo || $this->connection->selectOne('SELECT pg_backend_pid() AS pid')->pid !== $pid) throw new ClaimUnavailable('Physical session lost; outcome unknown.');
            $held=$this->connection->selectOne("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid() AND granted AND objsubid=1 AND classid=((hashtextextended(?,0) >> 32) & 4294967295)::oid AND objid=(hashtextextended(?,0) & 4294967295)::oid) AS held",[$key,$key])->held;
            if (!in_array($held,[true,'t',1],true)) throw new ClaimUnavailable('Physical fence lost; outcome unknown.');
        };
        try { $fence(); return $work($fence); }
        finally { try { if ($this->connection->getPdo() === $pdo) $this->connection->select('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$key]); } catch (\Throwable) {} }
    }
}
