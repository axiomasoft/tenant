<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Pool;

use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use Tenant\Postgres\Data\Provisioning\ResourceSnapshot;
use Tenant\Postgres\Exceptions\Claim\ClaimUnavailable;
use Tenant\Postgres\Internal\Database\DbRow;

/** @internal Captures frozen catalog binding snapshot. */
#[Internal]
final readonly class ResourceSnapshotReader
{
    public function __construct(private Connection $catalog) {}

    public function capture(string $resourceRef, string $incarnation): ResourceSnapshot
    {
        $r = $this->catalog->table('tenant_pg_resources')->where('resource_ref', $resourceRef)->first();
        if ($r === null
            || DbRow::raw($r, 'deleted_at') !== null
            || DbRow::str($r, 'status') === 'deleted'
            || DbRow::str($r, 'incarnation') !== $incarnation) {
            throw new ClaimUnavailable('Resource snapshot is missing or stale.');
        }

        return new ResourceSnapshot(
            DbRow::str($r, 'resource_ref'),
            DbRow::str($r, 'incarnation'),
            DbRow::int($r, 'template_version'),
            DbRow::str($r, 'cluster_id'),
            DbRow::str($r, 'database_name'),
        );
    }
}
