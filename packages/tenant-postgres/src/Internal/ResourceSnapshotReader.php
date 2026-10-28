<?php

declare(strict_types=1);
namespace Tenant\Postgres\Internal;
use Illuminate\Database\Connection;use Tenant\Postgres\Data\ResourceSnapshot;use Tenant\Postgres\Exceptions\ClaimUnavailable;
/** Fresh neutral replacement for CoreX AccountTemplateSnapshot. */
final readonly class ResourceSnapshotReader
{
    public function __construct(private Connection $catalog) {}
    public function capture(string $resourceRef,string $incarnation):ResourceSnapshot
    {
        $r=$this->catalog->table('tenant_pg_resources')->where('resource_ref',$resourceRef)->first();
        if($r===null||$r->deleted_at!==null||$r->status==='deleted'||$r->incarnation!==$incarnation||!is_string($r->database_name)||!is_string($r->cluster_id))throw new ClaimUnavailable('Resource snapshot is missing or stale.');
        return new ResourceSnapshot($r->resource_ref,$r->incarnation,(int)$r->template_version,$r->cluster_id,$r->database_name);
    }
}
