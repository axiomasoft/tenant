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
    public static function remember(Connection $c, RecoveryAnchor $anchor, string $table, array $r): void
    {
        $generation=self::assertCurrent($c,$anchor);$entries=$anchor->entries();
        if (isset($entries[$r['key_hash']])) {
            $old=$entries[$r['key_hash']];
            if ($old['operationId']!==$r['id'] || $old['intentDigest']!==$r['intent_digest'] || $old['resourceRef']!==$r['resource_ref'] || $old['incarnation']!==$r['incarnation']) throw new \RuntimeException('retention_history_missing: original receipt differs');
            return;
        }
        $anchor->remember($r['key_hash'],['operationId'=>$r['id'],'intentDigest'=>$r['intent_digest'],'resourceRef'=>$r['resource_ref'],'incarnation'=>$r['incarnation'],'generation'=>$generation,'table'=>$table]);
    }
    public static function assertComplete(Connection $connection, RecoveryAnchor $anchor): void
    {
        foreach ($anchor->entries() as $key=>$original) {
            $table=$original['table']??'tenant_pg_lifecycle_operations';
            if (!in_array($table,['tenant_pg_lifecycle_operations','tenant_pg_operations','tenant_pg_provision_attempts'],true)) throw new \RuntimeException('Invalid retained operation table.');
            $row=$connection->table($table)->where('key_hash',$key)->first();
            if ($row===null || $row->id !== ($original['operationId']??null) || $row->intent_digest !== ($original['intentDigest']??null) || $row->resource_ref !== ($original['resourceRef']??null) || $row->incarnation !== ($original['incarnation']??null)) throw new \RuntimeException('retention_history_missing: original operation unavailable');
        }
    }
}
