<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Database;

use Closure;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use RuntimeException;
use Tenant\Postgres\Data\Provisioning\ResourceSnapshot;

/**
 * Golden-template clone mechanics on the caller's management connection.
 * The caller must hold the operation/target fence and persist unknown outcomes.
 * Replay verification is deliberately separate from the stamp for a new clone.
 *
 * @internal
 */
#[Internal]
final readonly class TemplateClonePostgreSQLManager
{
    /** @param Closure(ResourceSnapshot): void $verifyBinding
     *  @param Closure(ResourceSnapshot): void $stampNewBinding
     *  @param (Closure(): void)|null $assertFence */
    public function __construct(
        private Connection $connection,
        private TemplateIntegrityVerifier $integrity,
        private Closure $verifyBinding,
        private Closure $stampNewBinding,
        private ?Closure $assertFence = null,
    ) {}

    public function createOrVerify(ResourceSnapshot $snapshot, string $templateDatabase): bool
    {
        $this->identifier($snapshot->databaseName);
        $this->identifier($templateDatabase);
        if ($snapshot->databaseName === $templateDatabase) {
            throw new RuntimeException('Template and target must differ.');
        }
        if ($this->connection->transactionLevel() !== 0) {
            throw new RuntimeException('Clone must run outside a transaction.');
        }

        $this->fence();
        $template = $this->integrity->assertCurrent($snapshot->templateVersion, $snapshot->clusterId);
        $lockKey = 'tenant-template-integrity:'.DbRow::str($template, 'id');
        // Hold the same upstream lock on the DDL session until verification completes.
        $this->connection->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$lockKey]);
        try {
            $template = $this->integrity->assertCurrent($snapshot->templateVersion, $snapshot->clusterId);
            if (DbRow::str($template, 'db_name') !== $templateDatabase) {
                throw new RuntimeException('Template binding changed.');
            }
            if ($this->databaseExists($snapshot->databaseName)) {
                $this->integrity->verifyAccount($snapshot);
                ($this->verifyBinding)($snapshot);

                return false;
            }

            // No automatic CREATE retry: a lost connection may conceal a committed DDL.
            $this->fence();
            $this->connection->getPdo()->exec('CREATE DATABASE "'.$snapshot->databaseName.'" TEMPLATE "'.$templateDatabase.'" STRATEGY WAL_LOG');
            $this->fence();
            ($this->stampNewBinding)($snapshot);
            $this->fence();
            $this->integrity->verifyAccount($snapshot);
            ($this->verifyBinding)($snapshot);

            return true;
        } finally {
            $this->connection->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lockKey]);
        }
    }

    /** @param Closure(): void $fence */
    public function usesFence(Closure $fence): bool
    {
        return $this->assertFence === $fence;
    }

    public function usesConnection(Connection $connection): bool
    {
        return $this->connection === $connection;
    }

    private function fence(): void
    {
        if ($this->assertFence !== null) {
            ($this->assertFence)();
        }
    }

    private function databaseExists(string $name): bool
    {
        return $this->connection->selectOne('SELECT datname FROM pg_database WHERE datname = ?', [$name]) !== null;
    }

    private function identifier(string $value): void
    {
        if (preg_match('/\A[a-zA-Z0-9_]+\z/D', $value) !== 1 || strlen($value) > 63) {
            throw new RuntimeException('Invalid PostgreSQL identifier.');
        }
    }
}
