<?php

declare(strict_types=1);
namespace Tenant\Postgres\Internal;
use Illuminate\Database\Connection;
use Tenant\Postgres\Contracts\RecoveryAnchor;

final class AnchorHistory
{
    public static function assertCurrent(Connection $connection, RecoveryAnchor $anchor): string
    {
        $generation=$anchor->generation();
        if ($connection->table('tenant_pg_control')->where('id',1)->value('recovery_generation') !== $generation) throw new \RuntimeException('recovery_generation_mismatch: catalog is quarantined');
        self::assertComplete($connection,$anchor);
        if ($anchor->generation() !== $generation) throw new \RuntimeException('recovery_generation_mismatch');
        return $generation;
    }
    public static function assertComplete(Connection $connection, RecoveryAnchor $anchor): void
    {
        foreach ($anchor->entries() as $key=>$original) {
            $row=$connection->table('tenant_pg_lifecycle_operations')->where('key_hash',$key)->first();
            if ($row===null || $row->id !== ($original['operationId']??null) || $row->intent_digest !== ($original['intentDigest']??null) || $row->resource_ref !== ($original['resourceRef']??null) || $row->incarnation !== ($original['incarnation']??null)) throw new \RuntimeException('retention_history_missing: original operation unavailable');
        }
    }
}
