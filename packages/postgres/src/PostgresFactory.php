<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Connectors\ConnectionFactory;
use InvalidArgumentException;
use Packman\Attributes\Api;
use RuntimeException;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Postgres\Contracts\Lifecycle\ArchiveStore;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\Contracts\Provisioning\ResourceBindings;
use Tenant\Postgres\Data\Binding\PhysicalAccess;
use Tenant\Postgres\Data\Provisioning\ResourceSnapshot;
use Tenant\Postgres\Data\Provisioning\TemplateTrust;
use Tenant\Postgres\Internal\Binding\CatalogResourceBindings;
use Tenant\Postgres\Internal\Binding\PostgresBindingVerifier;
use Tenant\Postgres\Internal\Claim\ClaimReceiptStore;
use Tenant\Postgres\Internal\Claim\ClaimResource;
use Tenant\Postgres\Internal\Claim\ClaimWithPool;
use Tenant\Postgres\Internal\Claim\ProvisionJournal;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Database\MarkerWriter;
use Tenant\Postgres\Internal\Database\PostgresRoute;
use Tenant\Postgres\Internal\Database\SchemaCatalogueSerializer;
use Tenant\Postgres\Internal\Database\TemplateClonePostgreSQLManager;
use Tenant\Postgres\Internal\Database\TemplateIntegrityLock;
use Tenant\Postgres\Internal\Database\TemplateIntegrityVerifier;
use Tenant\Postgres\Internal\Lifecycle\LifecycleJournal;
use Tenant\Postgres\Internal\Pool\PendingPool;

/** Public composition of the existing P4.1 engine and optional lifecycle/recovery adapters. */
#[Api(since: '1.0')]
final class PostgresFactory
{
    /** @param Closure(object): array<string, mixed> $runtimeRoute */
    public static function bindings(Connection $catalog, Closure $runtimeRoute, RecoveryAnchor $anchor): ResourceBindings
    {
        $runtimeRoute = self::frozenRoute($catalog, $runtimeRoute);

        return new CatalogResourceBindings($catalog, $runtimeRoute, new PostgresBindingVerifier($catalog, $runtimeRoute, $anchor));
    }

    /** @param Closure(object): array<string, mixed> $runtimeRoute Restricted owner route, no caller routing.
     * @param Closure(string): string $databaseNameFor Trusted allocation naming policy.
     * @param ?Closure(string, object, object): bool $retentionPolicy Explicit selected policy; absent refuses.
     * @param array<string, string> $templateMarker Exact trusted template marker. */
    public static function owner(Connection $catalog, ConnectionResolverInterface $connections, TemplateTrust $trust, Closure $runtimeRoute, Closure $databaseNameFor, string $runtimeRole, array $templateMarker, ?ArchiveStore $archives = null, ?RecoveryAnchor $anchor = null, ?Closure $retentionPolicy = null): ProvisioningOwner
    {
        $name = $catalog->getName();
        if (! is_string($name) || $name === '' || $connections->connection($name) !== $catalog) {
            throw new InvalidArgumentException('Owner catalog must use one connection.');
        }
        $factory = new ConnectionFactory(new Container);
        $management = DbRow::configMap($catalog);
        $runtimeRoute = self::frozenRoute($catalog, $runtimeRoute);
        $integrity = new TemplateIntegrityVerifier($name, new SchemaCatalogueSerializer, new TemplateIntegrityLock, $trust, $connections, $factory);
        $binding = new PostgresBindingVerifier($catalog, $runtimeRoute, $anchor, maintenance: true);
        $verify = static function (object $r) use ($binding, $runtimeRoute): void {
            $binding->verify(new BindingVerificationInput(
                new BindingExpectation(DbRow::str($r, 'resource_ref'), DbRow::str($r, 'incarnation'), DbRow::str($r, 'route_revision')),
                new PhysicalAccess($runtimeRoute($r)),
            ));
        };
        $verifySnapshot = static function (ResourceSnapshot $s) use ($catalog, $verify): void {
            $r = $catalog->table('tenant_pg_resources')->where('resource_ref', $s->resourceRef)->first() ?? throw new RuntimeException('Unknown original resource');
            if (DbRow::str($r, 'incarnation') !== $s->incarnation || DbRow::str($r, 'database_name') !== $s->databaseName || DbRow::str($r, 'cluster_id') !== $s->clusterId) {
                throw new RuntimeException('Frozen snapshot differs.');
            }
            $verify($r);
        };
        $journal = new ProvisionJournal($catalog, $integrity, $verifySnapshot, $anchor);
        $build = static function (Closure $fence, ResourceSnapshot $s) use ($catalog, $integrity, $factory, $management, $runtimeRole, $templateMarker, $verifySnapshot): TemplateClonePostgreSQLManager {
            $generation = $catalog->table('tenant_pg_resources')->where('resource_ref', $s->resourceRef)->value('recovery_generation');
            if (! is_string($generation)) {
                throw new RuntimeException('Missing recovery generation.');
            }
            $writer = new MarkerWriter($factory, $management, $runtimeRole, $generation, $templateMarker, $fence);

            return new TemplateClonePostgreSQLManager($catalog, $integrity, $verifySnapshot, $writer->stamp(...), $fence);
        };
        $claims = new ClaimReceiptStore($catalog, new ClaimResource($name, $integrity, $connections), $verify, $anchor);
        $pool = new PendingPool($catalog, $integrity, $journal, $build, $verifySnapshot, $databaseNameFor, $anchor);
        $lifecycle = new LifecycleJournal($catalog, $verify, $archives, $anchor, $retentionPolicy);

        $makeOperator = Closure::bind(
            static fn (Connection $cat, LifecycleJournal $life, Closure $mgmtRoute, string $role, Closure $rtRoute): RecoveryOperator => new RecoveryOperator($cat, $life, $mgmtRoute, $role, $rtRoute),
            null,
            RecoveryOperator::class,
        );

        $operator = $makeOperator(
            $catalog,
            $lifecycle,
            static fn (object $r): array => [...$management, 'database' => DbRow::str($r, 'database_name')],
            $runtimeRole,
            $runtimeRoute,
        );

        $makeOwner = Closure::bind(
            static fn (ClaimWithPool $cwp, ClaimReceiptStore $crs, ProvisionJournal $pj, LifecycleJournal $lj, RecoveryOperator $ro): ProvisioningOwner => new ProvisioningOwner($cwp, $crs, $pj, $lj, $ro),
            null,
            ProvisioningOwner::class,
        );

        return $makeOwner(new ClaimWithPool($claims, $pool), $claims, $journal, $lifecycle, $operator);
    }

    /**
     * @param  Closure(object): array<string, mixed>  $hostRoute
     * @return Closure(object): array<string, mixed>
     */
    private static function frozenRoute(Connection $catalog, Closure $hostRoute): Closure
    {
        $management = DbRow::configMap($catalog);
        if (! PostgresRoute::isDirect($management)) {
            throw new InvalidArgumentException('Owner catalog requires a direct PostgreSQL route.');
        }

        return static function (object $resource) use ($management, $hostRoute): array {
            $route = $hostRoute($resource);
            $routePort = $route['port'] ?? 5432;
            $mgmtPort = $management['port'] ?? 5432;
            if (! PostgresRoute::isDirect($route)
                || ($route['database'] ?? null) !== DbRow::str($resource, 'database_name')
                || ($route['host'] ?? null) !== ($management['host'] ?? null)
                || (! is_int($routePort) && ! is_string($routePort))
                || (! is_int($mgmtPort) && ! is_string($mgmtPort))
                || (int) $routePort !== (int) $mgmtPort) {
                throw new RuntimeException('Unsupported owner route: management and runtime must share the frozen server.');
            }

            return $route;
        };
    }
}
