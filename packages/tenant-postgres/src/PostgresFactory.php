<?php

declare(strict_types=1);
namespace Tenant\Postgres;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Connectors\ConnectionFactory;
use Tenant\Data\{BindingExpectation,BindingVerificationInput};
use Tenant\Postgres\Contracts\{ArchiveStore,RecoveryAnchor};
use Tenant\Postgres\Data\{PhysicalAccess,ResourceSnapshot,TemplateTrust};
use Tenant\Postgres\Internal\{ClaimReceiptStore,ClaimResource,ClaimWithPool,LifecycleJournal,PendingPool,PostgresBindingVerifier,ProvisionJournal};
use Tenant\Postgres\Internal\Database\{MarkerWriter,SchemaCatalogueSerializer,TemplateClonePostgreSQLManager,TemplateIntegrityLock,TemplateIntegrityVerifier};

/** Public composition of the existing P4.1 engine and optional lifecycle/recovery adapters. */
final class PostgresFactory
{
    /** @param Closure(object):array $runtimeRoute Restricted owner route, no caller routing.
     * @param Closure(string):string $databaseNameFor Trusted allocation naming policy.
     * @param ?Closure(string,object,object):bool $retentionPolicy Explicit selected policy; absent refuses.
     * @param array<string,string> $templateMarker Exact trusted template marker. */
    public static function owner(Connection $catalog, ConnectionResolverInterface $connections, TemplateTrust $trust, Closure $runtimeRoute, Closure $databaseNameFor, string $runtimeRole, array $templateMarker, ?ArchiveStore $archives=null, ?RecoveryAnchor $anchor=null, ?Closure $retentionPolicy=null): ProvisioningOwner
    {
        $name=$catalog->getName();
        if ($connections->connection($name)!==$catalog) throw new \InvalidArgumentException('Owner catalog must use one connection.');
        $factory=new ConnectionFactory(new Container);$management=$catalog->getConfig();
        $hostRoute=$runtimeRoute;
        $runtimeRoute=static function(object $r)use($hostRoute,$management):array {
            $route=$hostRoute($r);
            if (($route['driver']??null)!=='pgsql' || ($route['database']??null)!==$r->database_name || ($route['host']??null)!==($management['host']??null) || (int)($route['port']??5432)!==(int)($management['port']??5432) || isset($route['url']) || isset($route['read']) || isset($route['write'])) throw new \RuntimeException('Unsupported owner route: management and runtime must share the frozen server.');
            return $route;
        };
        $integrity=new TemplateIntegrityVerifier($name,new SchemaCatalogueSerializer,new TemplateIntegrityLock,$trust,$connections,$factory);
        $binding=new PostgresBindingVerifier($catalog,$runtimeRoute,$anchor,maintenance:true);
        $verify=static function(object $r)use($binding,$runtimeRoute):void { $binding->verify(new BindingVerificationInput(new BindingExpectation($r->resource_ref,$r->incarnation,$r->route_revision),new PhysicalAccess($runtimeRoute($r)))); };
        $verifySnapshot=static function(ResourceSnapshot $s)use($catalog,$verify):void {
            $r=$catalog->table('tenant_pg_resources')->where('resource_ref',$s->resourceRef)->first() ?? throw new \RuntimeException('Unknown original resource');
            if ($r->incarnation!==$s->incarnation || $r->database_name!==$s->databaseName || $r->cluster_id!==$s->clusterId) throw new \RuntimeException('Frozen snapshot differs.');$verify($r);
        };
        $journal=new ProvisionJournal($catalog,$integrity,$verifySnapshot,$anchor);
        $build=static function(Closure $fence,ResourceSnapshot $s)use($catalog,$integrity,$factory,$management,$runtimeRole,$templateMarker,$verifySnapshot):TemplateClonePostgreSQLManager {
            $generation=$catalog->table('tenant_pg_resources')->where('resource_ref',$s->resourceRef)->value('recovery_generation');
            $writer=new MarkerWriter($factory,$management,$runtimeRole,$generation,$templateMarker,$fence);
            return new TemplateClonePostgreSQLManager($catalog,$integrity,$verifySnapshot,$writer->stamp(...),$fence);
        };
        $claims=new ClaimReceiptStore($catalog,new ClaimResource($name,$integrity,$connections),$verify,$anchor);
        $pool=new PendingPool($catalog,$integrity,$journal,$build,$verifySnapshot,$databaseNameFor,$anchor);
        $lifecycle=new LifecycleJournal($catalog,$verify,$archives,$anchor,$retentionPolicy);
        $operator=new RecoveryOperator($catalog,$lifecycle,static fn(object $r):array=>[...$management,'database'=>$r->database_name],$runtimeRole,$runtimeRoute);
        return new ProvisioningOwner(new ClaimWithPool($claims,$pool),$claims,$journal,$lifecycle,$operator);
    }
}
