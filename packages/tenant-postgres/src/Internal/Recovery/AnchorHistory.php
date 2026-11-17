<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Recovery;

use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use Tenant\Exceptions\ResourceQuarantined;
use Tenant\Exceptions\RetentionHistoryMissing;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\Internal\Database\DbRow;

#[Internal]
final class AnchorHistory
{
    public static function assertCurrent(Connection $connection, RecoveryAnchor $anchor): string
    {
        $generation = $anchor->generation();
        if ($connection->table('tenant_pg_control')->where('id', 1)->value('recovery_generation') !== $generation) {
            throw new ResourceQuarantined('recovery_generation_mismatch: catalog is quarantined');
        }
        self::assertComplete($connection, $anchor);
        if ($anchor->generation() !== $generation) {
            throw new ResourceQuarantined('recovery_generation_mismatch');
        }

        return $generation;
    }

    /** @param array<string, mixed> $r */
    public static function remember(Connection $c, RecoveryAnchor $anchor, string $table, array $r): void
    {
        $keyHash = $r['key_hash'] ?? null;
        $operationId = $r['id'] ?? null;
        $intentDigest = $r['intent_digest'] ?? null;
        $resourceRef = $r['resource_ref'] ?? null;
        $incarnation = $r['incarnation'] ?? null;
        if (! is_string($keyHash) || ! is_string($operationId) || ! is_string($intentDigest) || ! is_string($resourceRef) || ! is_string($incarnation)) {
            throw new RetentionHistoryMissing('Invalid retained operation payload.');
        }

        $generation = self::assertCurrent($c, $anchor);
        $entries = $anchor->entries();
        if (isset($entries[$keyHash])) {
            $old = $entries[$keyHash];
            if (($old['operationId'] ?? null) !== $operationId || ($old['intentDigest'] ?? null) !== $intentDigest || ($old['resourceRef'] ?? null) !== $resourceRef || ($old['incarnation'] ?? null) !== $incarnation) {
                throw new RetentionHistoryMissing('retention_history_missing: original receipt differs');
            }

            return;
        }
        $anchor->remember($keyHash, ['operationId' => $operationId, 'intentDigest' => $intentDigest, 'resourceRef' => $resourceRef, 'incarnation' => $incarnation, 'generation' => $generation, 'table' => $table]);
    }

    public static function assertComplete(Connection $connection, RecoveryAnchor $anchor): void
    {
        foreach ($anchor->entries() as $key => $original) {
            $table = $original['table'] ?? 'tenant_pg_lifecycle_operations';
            if (! is_string($table) || ! in_array($table, ['tenant_pg_lifecycle_operations', 'tenant_pg_operations', 'tenant_pg_provision_attempts'], true)) {
                throw new RetentionHistoryMissing('Invalid retained operation table.');
            }
            $row = $connection->table($table)->where('key_hash', $key)->first();
            if ($row === null
                || DbRow::str($row, 'id') !== ($original['operationId'] ?? null)
                || DbRow::str($row, 'intent_digest') !== ($original['intentDigest'] ?? null)
                || DbRow::str($row, 'resource_ref') !== ($original['resourceRef'] ?? null)
                || DbRow::str($row, 'incarnation') !== ($original['incarnation'] ?? null)) {
                throw new RetentionHistoryMissing('retention_history_missing: original operation unavailable');
            }
        }
    }
}
