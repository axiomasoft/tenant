<?php

declare(strict_types=1);

/** Package-owned whitebox fixture setup. Consumer source never accesses these internals.
 * Reuses the exact P4 pool/register implementations; no test executor/verifier is substituted.
 */
final class ConsumerPostgresFixtures
{
    public static function fill(Tenant\Postgres\ProvisioningOwner $owner, Tenant\Postgres\ProvisionIntent $intent, Tenant\Postgres\OperationKey $key, Tenant\Postgres\Admission $admission): Tenant\Postgres\OperationReceipt
    {
        $claims = (new ReflectionProperty($owner, 'claims'))->getValue($owner);
        $pool = (new ReflectionProperty($claims, 'pool'))->getValue($claims);
        return $pool->fill($intent, $key, $admission);
    }

    public static function accepted(Tenant\Postgres\ProvisioningOwner $owner, Illuminate\Database\Connection $catalog,
        Tenant\Postgres\ProvisionIntent $intent, Tenant\Postgres\OperationKey $key, string $database, string $templateDatabase,
        Tenant\Postgres\Contracts\RecoveryAnchor $anchor, Tenant\Postgres\Admission $admission): Tenant\Postgres\OperationReceipt
    {
        // Explicit reserved catalog input, no physical DB. Never borrow a prior attempt's DB name.
        $id = (string) Illuminate\Support\Str::uuid7();
        $snapshot = new Tenant\Postgres\Data\ResourceSnapshot('pg:'.$id, (string) Illuminate\Support\Str::uuid7(), 7, $intent->clusterId, $database);
        $catalog->table('tenant_pg_resources')->insert(['id' => $id, 'cluster_id' => $intent->clusterId, 'template_version' => 7,
            'status' => 'provisioning', 'resource_ref' => $snapshot->resourceRef, 'incarnation' => $snapshot->incarnation,
            'database_name' => $database, 'route_revision' => '1', 'recovery_generation' => $anchor->generation(),
            'signup_key' => $key->storageKey(), 'signup_request_digest' => $intent->digest(), 'signup_cluster_id' => $intent->clusterId, 'signup_template_id' => $intent->templateId]);
        $catalog->table('tenant_pg_clusters')->where('id', $intent->clusterId)->increment('database_count');
        $journal = (new ReflectionProperty($owner, 'journal'))->getValue($owner);
        return $journal->register($intent, $key, $snapshot, $templateDatabase, $admission);
    }
}
