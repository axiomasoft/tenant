<?php

declare(strict_types=1);

// Execute only the reversed P5.1 cohort and its immutable installed graph.
if (getenv('APP_ENV') !== 'testing') { throw new RuntimeException('Testing environment required.'); }
require getenv('TENANT_LANE_AUTOLOAD');
$m = json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')), true, flags: JSON_THROW_ON_ERROR);
$secret = json_decode(file_get_contents($m['credentials']), true, flags: JSON_THROW_ON_ERROR);
foreach ($m['databases'] as $label => $name) if ($name !== 'tenant_'.$m['runId'].'_'.$label.'_test') { throw new RuntimeException('Unexpected rollback DB.'); }
$app = new Illuminate\Container\Container(); $db = new Illuminate\Database\Capsule\Manager($app);
$configuration = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $m['ports']['pg'], 'database' => $m['databases']['central'],
    'username' => $m['admin'], 'password' => $secret['adminPassword'], 'charset' => 'utf8', 'schema' => 'public', 'prefix' => ''];
foreach (['central', 'template'] as $label) $db->addConnection([...$configuration, 'database' => $m['databases'][$label]], $label);
$connections = $db->getDatabaseManager(); $connections->setDefaultConnection('central');
if ((string) $connections->connection('central')->selectOne('SELECT system_identifier FROM pg_control_system()')->system_identifier !== $m['systemIdentifier']) { throw new RuntimeException('Wrong rollback server.'); }
$app->instance('db', $connections); $app->bind('db.schema', fn () => $connections->connection()->getSchemaBuilder());
$db->bootEloquent();
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
function rollbackMigrate(string $name, string $path): array {
    global $connections;
    Illuminate\Support\Facades\Schema::clearResolvedInstance('db.schema');
    $repository = new Illuminate\Database\Migrations\DatabaseMigrationRepository($connections, 'migrations'); $repository->setSource($name);
    $connections->setDefaultConnection($name);
    if (!$repository->repositoryExists()) $repository->createRepository();
    $migrator = new Illuminate\Database\Migrations\Migrator($repository, $connections, new Illuminate\Filesystem\Filesystem());
    $migrator->setConnection($name); $migrator->run([$path]); return $repository->getRan();
}
$corex = getenv('COREX_CONSUMER_CHECKOUT');
$ran = rollbackMigrate('central', $corex.'/packages/tenancy/database/migrations/root');
$outbox = $corex.'/packages/core/database/migrations/tenant/2026_10_01_010120_create_sys_outbox_messages.php';
rollbackMigrate('template', $outbox); rollbackMigrate('central', $outbox);
$connections->connection('template')->disconnect();
$central = $connections->connection('central');
$cluster = '13131313-1313-4313-8313-131313131313';
$central->table('root_clusters')->insert(['id' => $cluster, 'name' => 'rollback-lab', 'pg_host_writer' => '127.0.0.1',
    'pgbouncer_host' => '127.0.0.1', 'pgbouncer_port' => $m['ports']['pg'], 'app_db_user' => $m['runtime'], 'template_version' => 7]);
$central->table('root_templates')->insert(['id' => '12121212-1212-4212-8212-121212121212', 'version' => 7,
    'db_name' => $m['databases']['template'], 'status' => 'ready', 'embedding_model' => 'lab-only', 'embedding_dim' => 3]);
$central->table('root_identities')->insert(['id' => '00000000-0000-4000-8000-000000000099', 'email' => 'owner@example.test']);
foreach (['a','b'] as $number => $label) {
    $target = $m['databases'][$label]; $id = '00000000-0000-4000-8000-00000000000'.($number+1);
    if ($central->selectOne('SELECT 1 FROM pg_database WHERE datname=?', [$target]) !== null) { throw new RuntimeException('Rollback fixture will not overwrite a DB.'); }
    // Existing CoreX clone primitive remains the sole selected old physical engine.
    $options = CoreX\Tenancy\Laravel\Support\TenancyOptions::fromArray(['central_connection' => 'central']);
    $factory = new Illuminate\Database\Connectors\ConnectionFactory($app);
    $integrity = new CoreX\Tenancy\Provisioning\Internal\Database\TemplateIntegrityVerifier('central', new CoreX\Tenancy\Provisioning\Internal\Database\SchemaCatalogueSerializer(), new CoreX\Tenancy\Provisioning\Internal\Database\TemplateIntegrityLock(), $options, $connections, $factory);
    $routes = new class($configuration, $m, $secret, $target) implements CoreX\Context\Contracts\AccountConnectionResolver {
        public function __construct(private array $configuration, private array $m, private array $secret, private string $target) {}
        public function resolve(CoreX\Context\Data\AccountRef $account): CoreX\Context\Data\DatabaseConnectionConfig {
            return new CoreX\Context\Data\DatabaseConnectionConfig('pgsql', '127.0.0.1', $this->m['ports']['pg'], $this->target, $this->m['runtime'], ['password' => $this->secret['runtimePassword'], 'schema' => 'public']);
        }
    };
    $owner = new CoreX\Tenancy\Runtime\Internal\CorexBindingOwner($connections, new CoreX\Tenancy\Account\Internal\SqlAccountRead(), $routes, 'central', $m['root'].'/corex-resources');
    $cloner = new CoreX\Tenancy\Provisioning\Internal\Database\TemplateClonePostgreSQLManager($options, $connections, $integrity, $owner);
    $cloner->createDatabase($target, $m['databases']['template']);
    $central->table('root_accounts')->insert(['id'=>$id,'slug'=>$label,'status'=>'provisioning','cluster_id'=>$cluster,'db_name'=>$target,'template_version'=>7,
        'owner_identity_id'=>'00000000-0000-4000-8000-000000000099','embedding_model'=>'lab-only','embedding_dim'=>3]);
    $account = new CoreX\Context\Data\AccountRef($id,$label,'provisioning',[],[]); $owner->stamp($account);
    $central->table('root_accounts')->where('id',$id)->update(['status'=>'active']);
    $central->table('root_domains')->insert(['id'=>(string)Illuminate\Support\Str::uuid7(),'account_id'=>$id,'host'=>$label.'.example.test','type'=>'platform','is_primary'=>true,'verified_at'=>new DateTimeImmutable(),'tls_status'=>'issued']);
    $physical=$factory->make([...$configuration,'database'=>$target]);
    $physical->table('probes')->where('name','identity')->update(['value'=>$label]);
    $physical->statement('CREATE TABLE corex_scope_rows(id text PRIMARY KEY,workspace_id text NULL)');
    $physical->table('corex_scope_rows')->insert([['id'=>'rNull','workspace_id'=>null],['id'=>'rW','workspace_id'=>'w'],['id'=>'rOther','workspace_id'=>'other']]);
    $physical->statement('GRANT SELECT,INSERT,UPDATE,DELETE ON probes,corex_scope_rows,sys_outbox_messages TO "'.$m['runtime'].'"');
    $physical->disconnect();
}
$central->statement('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO "'.$m['runtime'].'"');
$central->statement('GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO "'.$m['runtime'].'"');
if ($central->getSchemaBuilder()->hasTable('tenant_pg_resources')) { throw new RuntimeException('Reversed cohort still activates PG catalog.'); }
echo json_encode(['status'=>'passed','owner'=>'corex','schema'=>'actual P5.1 mixed baseline, not copied P5.4 schema','migrations'=>$ran],JSON_THROW_ON_ERROR),PHP_EOL;
