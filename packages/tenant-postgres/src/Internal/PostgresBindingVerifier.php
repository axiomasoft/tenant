<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\PostgresConnector;
use PDO;
use Tenant\Contracts\BindingVerifier;
use Tenant\Data\{BindingVerificationInput,VerifiedBinding};
use Tenant\Exceptions\BindingMismatch;
use Tenant\Postgres\Data\PhysicalAccess;

/** PG owner adaptation of CoreX tenant.binding/1 verification. */
final readonly class PostgresBindingVerifier implements BindingVerifier
{
    /** @param Closure(object): array<string, mixed> $routeFor Current owner route, never caller-provided credentials. */
    public function __construct(private Connection $catalog, private Closure $routeFor, private ?\Tenant\Postgres\Contracts\RecoveryAnchor $anchor=null, private bool $maintenance=false) {}

    public function verify(BindingVerificationInput $input): VerifiedBinding
    {
        try {
            if (! $input->access instanceof PhysicalAccess) throw new \RuntimeException('Invalid physical access.');
            $expected=$input->expected;
            $row=$this->catalog->table('tenant_pg_resources')->where('resource_ref',$expected->resourceRef)->first();
            if ($row === null || $row->deleted_at !== null || $row->incarnation !== $expected->incarnation || $row->route_revision !== $expected->routeRevision || ! is_string($row->recovery_generation)) throw new \RuntimeException('Stale catalog binding.');
            if (!$this->maintenance && in_array($row->status,['exporting','exported','deleting','quarantined','recovering','deleted'],true)) throw new \RuntimeException('Resource admission is closed.');
            if ($this->anchor!==null && AnchorHistory::assertCurrent($this->catalog,$this->anchor)!==$row->recovery_generation) throw new \RuntimeException('Recovery anchor mismatch.');
            $route=($this->routeFor)($row);
            if ($route !== $input->access->route || ($route['driver'] ?? null) !== 'pgsql' || ($route['database'] ?? null) !== $row->database_name || isset($route['url']) || isset($route['read']) || isset($route['write'])) throw new \RuntimeException('Route mismatch.');
            $pdo=(new PostgresConnector)->connect($route);
            $role=$pdo->query("SELECT EXISTS (SELECT 1 FROM pg_roles WHERE (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls) AND pg_has_role(current_user,oid,'MEMBER')) AS privileged")->fetch(PDO::FETCH_ASSOC);
            if (! is_array($role) || ! in_array($role['privileged'] ?? null,[false,'f'],true)) throw new \RuntimeException('Privileged runtime role.');
            $privileges=$pdo->query("SELECT has_table_privilege(current_user,'tenant_binding','INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER') OR has_schema_privilege(current_user,'public','CREATE') OR has_database_privilege(current_user,current_database(),'CREATE') AS writable")->fetch(PDO::FETCH_ASSOC);
            if (! is_array($privileges) || ! in_array($privileges['writable'] ?? null,[false,'f'],true)) throw new \RuntimeException('Runtime role can mutate binding or create schema objects.');
            $markers=$pdo->query('SELECT protocol,owner_id,resource_ref,incarnation,recovery_generation FROM tenant_binding')->fetchAll(PDO::FETCH_ASSOC);
            $marker=['protocol'=>'tenant.binding/1','owner_id'=>'tenant-postgres','resource_ref'=>$expected->resourceRef,'incarnation'=>$expected->incarnation,'recovery_generation'=>$row->recovery_generation];
            if ($markers !== [$marker]) throw new \RuntimeException('Instance marker mismatch.');
            return new VerifiedBinding($expected,'tenant-postgres',$row->recovery_generation);
        } catch (\Throwable $failure) {
            throw new BindingMismatch([$failure]);
        }
    }
}
