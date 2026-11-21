<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Database;

use Closure;
use Illuminate\Database\Connectors\ConnectionFactory;
use Packman\Attributes\Internal;
use RuntimeException;
use Tenant\Postgres\Data\Provisioning\ResourceSnapshot;

/** Stamps only a just-created clone whose template marker matches the trusted source. */
#[Internal]
final readonly class MarkerWriter
{
    /** @param array<string, mixed> $managementConfig
     *  @param array<string, string> $expectedTemplateMarker
     *  @param Closure(): void $assertFence */
    public function __construct(private ConnectionFactory $factory, private array $managementConfig, private string $runtimeRole, private string $generation, private array $expectedTemplateMarker, private Closure $assertFence) {}

    public function stamp(ResourceSnapshot $snapshot): void
    {
        if (! PostgresIdentifier::isValid($snapshot->databaseName) || ! PostgresIdentifier::isValid($this->runtimeRole) || $this->generation === '') {
            throw new RuntimeException('Invalid physical binding.');
        }
        ($this->assertFence)();
        $config = $this->managementConfig;
        $config['database'] = $snapshot->databaseName;
        if (! PostgresRoute::isDirect($config)) {
            throw new RuntimeException('Invalid management route.');
        }
        $connection = $this->factory->make($config, 'tenant-pg-marker-writer');
        try {
            $connection->statement('SET search_path TO public');
            $connection->transaction(function () use ($connection, $snapshot): void {
                ($this->assertFence)();
                $rows = $connection->select('SELECT protocol,owner_id,resource_ref,incarnation,recovery_generation FROM public.tenant_binding FOR UPDATE');
                if (count($rows) !== 1 || (array) $rows[0] !== $this->expectedTemplateMarker) {
                    throw new RuntimeException('Unexpected template marker.');
                }
                $count = $connection->table('public.tenant_binding')->where('singleton', true)->update(['protocol' => 'tenant.binding/1', 'owner_id' => 'tenant-postgres', 'resource_ref' => $snapshot->resourceRef, 'incarnation' => $snapshot->incarnation, 'recovery_generation' => $this->generation]);
                if ($count !== 1) {
                    throw new RuntimeException('Marker update failed.');
                }
                $role = PostgresIdentifier::quote($this->runtimeRole);
                $connection->statement('REVOKE ALL ON public.tenant_binding FROM PUBLIC');
                $connection->statement('REVOKE ALL ON public.tenant_binding FROM '.$role);
                $connection->statement('GRANT SELECT ON public.tenant_binding TO '.$role);
                ($this->assertFence)();
            });
            ($this->assertFence)();
        } finally {
            $connection->disconnect();
        }
    }
}
