<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Claim;

use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use Tenant\Postgres\Exceptions\Claim\ClaimConflict;
use Tenant\Postgres\OperationKey;

/** Shared key fence for the claim/provision cohort and optional lifecycle journal. */
#[Internal]
final class OperationKeys
{
    public static function lock(Connection $c, OperationKey $key, bool $lifecycle): void
    {
        $hash = $key->storageKey();
        $c->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['tenant:operation:'.$hash]);
        $tables = $lifecycle ? ['tenant_pg_operations', 'tenant_pg_provision_attempts'] : ['tenant_pg_lifecycle_operations'];
        foreach ($tables as $table) {
            if ($c->getSchemaBuilder()->hasTable($table) && $c->table($table)->where('key_hash', $hash)->exists()) {
                throw new ClaimConflict('Original operation key belongs to another kind.');
            }
        }
    }
}
