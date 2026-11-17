<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Binding;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\PostgresConnector;
use Packman\Attributes\Internal;
use PDO;
use RuntimeException;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Data\Verification\VerifiedBinding;
use Tenant\Exceptions\Verification\BindingMismatch;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\Data\Binding\PhysicalAccess;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Recovery\AnchorHistory;
use Throwable;

/** PG owner tenant.binding/1 verification. */
#[Internal]
final readonly class PostgresBindingVerifier implements BindingVerifier
{
    /** @param Closure(object): array<string, mixed> $routeFor Current owner route, never caller-provided credentials. */
    public function __construct(private Connection $catalog, private Closure $routeFor, private ?RecoveryAnchor $anchor = null, private bool $maintenance = false) {}

    public function verify(BindingVerificationInput $input): VerifiedBinding
    {
        try {
            if (! $input->access instanceof PhysicalAccess) {
                throw new RuntimeException('Invalid physical access.');
            }
            $expected = $input->expected;
            $row = $this->catalog->table('tenant_pg_resources')->where('resource_ref', $expected->resourceRef)->first();
            if ($row === null
                || DbRow::raw($row, 'deleted_at') !== null
                || DbRow::str($row, 'incarnation') !== $expected->incarnation
                || DbRow::str($row, 'route_revision') !== $expected->routeRevision) {
                throw new RuntimeException('Stale catalog binding.');
            }
            $recoveryGeneration = DbRow::str($row, 'recovery_generation');
            if (! $this->maintenance && in_array(DbRow::str($row, 'status'), ['exporting', 'exported', 'deleting', 'quarantined', 'recovering', 'deleted'], true)) {
                throw new RuntimeException('Resource admission is closed.');
            }
            if ($this->anchor !== null && AnchorHistory::assertCurrent($this->catalog, $this->anchor) !== $recoveryGeneration) {
                throw new RuntimeException('Recovery anchor mismatch.');
            }
            $route = ($this->routeFor)($row);
            if ($route !== $input->access->route || ($route['driver'] ?? null) !== 'pgsql' || ($route['database'] ?? null) !== DbRow::str($row, 'database_name') || isset($route['url']) || isset($route['read']) || isset($route['write'])) {
                throw new RuntimeException('Route mismatch.');
            }
            $pdo = (new PostgresConnector)->connect($route);
            $roleStmt = $pdo->query("SELECT EXISTS (SELECT 1 FROM pg_roles WHERE (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls) AND pg_has_role(current_user,oid,'MEMBER')) AS privileged");
            $role = $roleStmt !== false ? $roleStmt->fetch(PDO::FETCH_ASSOC) : false;
            if (! is_array($role) || ! in_array($role['privileged'] ?? null, [false, 'f'], true)) {
                throw new RuntimeException('Privileged runtime role.');
            }
            $privStmt = $pdo->query("SELECT has_table_privilege(current_user,'tenant_binding','INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER') OR has_schema_privilege(current_user,'public','CREATE') OR has_database_privilege(current_user,current_database(),'CREATE') AS writable");
            $privileges = $privStmt !== false ? $privStmt->fetch(PDO::FETCH_ASSOC) : false;
            if (! is_array($privileges) || ! in_array($privileges['writable'] ?? null, [false, 'f'], true)) {
                throw new RuntimeException('Runtime role can mutate binding or create schema objects.');
            }
            $markerStmt = $pdo->query('SELECT protocol,owner_id,resource_ref,incarnation,recovery_generation FROM tenant_binding');
            $markers = $markerStmt !== false ? $markerStmt->fetchAll(PDO::FETCH_ASSOC) : false;
            $marker = ['protocol' => 'tenant.binding/1', 'owner_id' => 'tenant-postgres', 'resource_ref' => $expected->resourceRef, 'incarnation' => $expected->incarnation, 'recovery_generation' => $recoveryGeneration];
            if ($markers !== [$marker]) {
                throw new RuntimeException('Instance marker mismatch.');
            }

            return new VerifiedBinding($expected, 'tenant-postgres', $recoveryGeneration);
        } catch (Throwable $failure) {
            throw new BindingMismatch([$failure]);
        }
    }
}
