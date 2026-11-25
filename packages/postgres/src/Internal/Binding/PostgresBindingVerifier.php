<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Binding;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\PostgresConnector;
use Packman\Attributes\Internal;
use PDO;
use PDOException;
use RuntimeException;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Data\Verification\VerifiedBinding;
use Tenant\Exceptions\Verification\BindingMismatch;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\Data\Binding\PhysicalAccess;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Database\PostgresRoute;
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
            if (! $this->maintenance && ! in_array(DbRow::str($row, 'status'), ['ready', 'active'], true)) {
                throw new RuntimeException('Resource admission is closed.');
            }
            if ($this->anchor !== null && AnchorHistory::assertCurrent($this->catalog, $this->anchor) !== $recoveryGeneration) {
                throw new RuntimeException('Recovery anchor mismatch.');
            }
            $route = ($this->routeFor)($row);
            if ($route !== $input->access->route || ! PostgresRoute::isDirect($route) || ($route['database'] ?? null) !== DbRow::str($row, 'database_name')) {
                throw new RuntimeException('Route mismatch.');
            }
            $pdo = (new PostgresConnector)->connect($route);
            $pdo->exec('SET search_path TO public');
            $roleStmt = $pdo->query("SELECT EXISTS (SELECT 1 FROM pg_roles WHERE (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls) AND (pg_has_role(current_user,oid,'MEMBER') OR pg_has_role(session_user,oid,'MEMBER'))) AS privileged");
            $role = $roleStmt !== false ? $roleStmt->fetch(PDO::FETCH_ASSOC) : false;
            if (! is_array($role) || ! in_array($role['privileged'] ?? null, [false, 'f'], true)) {
                throw new RuntimeException('Privileged runtime role.');
            }
            // A lowered startup role and NOINHERIT membership can hide privileges
            // available through SET ROLE. Inspect every reachable role, including
            // column grants and ownership that can restore revoked privileges.
            $privStmt = $pdo->query(<<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM pg_roles AS runtime_role
                    WHERE (pg_has_role(current_user, runtime_role.oid, 'MEMBER')
                        OR pg_has_role(session_user, runtime_role.oid, 'MEMBER'))
                    AND (
                        has_table_privilege(runtime_role.oid, 'public.tenant_binding', 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
                        OR has_any_column_privilege(runtime_role.oid, 'public.tenant_binding', 'INSERT,UPDATE,REFERENCES')
                        OR has_schema_privilege(runtime_role.oid, 'public', 'CREATE')
                        OR has_database_privilege(runtime_role.oid, current_database(), 'CREATE')
                        OR EXISTS (
                            SELECT 1 FROM pg_class AS relation
                            JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
                            WHERE relation.oid = 'public.tenant_binding'::regclass
                                AND runtime_role.oid IN (relation.relowner, namespace.nspowner)
                        )
                        OR EXISTS (
                            SELECT 1 FROM pg_database
                            WHERE datname = current_database() AND datdba = runtime_role.oid
                        )
                    )
                ) AS writable
                SQL);
            $privileges = $privStmt !== false ? $privStmt->fetch(PDO::FETCH_ASSOC) : false;
            if (! is_array($privileges) || ! in_array($privileges['writable'] ?? null, [false, 'f'], true)) {
                throw new RuntimeException('Runtime role can mutate binding or create schema objects.');
            }
            $markerStmt = $pdo->query('SELECT protocol,owner_id,resource_ref,incarnation,recovery_generation FROM public.tenant_binding');
            $markers = $markerStmt !== false ? $markerStmt->fetchAll(PDO::FETCH_ASSOC) : false;
            $marker = ['protocol' => 'tenant.binding/1', 'owner_id' => 'tenant-postgres', 'resource_ref' => $expected->resourceRef, 'incarnation' => $expected->incarnation, 'recovery_generation' => $recoveryGeneration];
            if ($markers !== [$marker]) {
                throw new RuntimeException('Instance marker mismatch.');
            }

            return new VerifiedBinding($expected, 'tenant-postgres', $recoveryGeneration);
        } catch (Throwable $failure) {
            if ($failure instanceof PDOException) {
                $failure = new RuntimeException('PostgreSQL binding verification failed.');
            }
            throw new BindingMismatch([$failure]);
        }
    }
}
