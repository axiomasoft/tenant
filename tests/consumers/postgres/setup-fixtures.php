<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\{DatabaseMigrationRepository, Migrator};
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Tenant\Postgres\{Admission, FileRecoveryAnchor, OperationKey, PostgresFactory, PostgresSchema, ProvisionIntent};
use Tenant\Postgres\Data\TemplateTrust;
use Tenant\Postgres\Internal\Database\SchemaCatalogueSerializer;

$m = $p54Manifest;
$secret = json_decode(file_get_contents($m['credentials']), true, flags: JSON_THROW_ON_ERROR);
$app = new Container();
$db = new Manager($app);
$configuration = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $m['ports']['pg'], 'database' => $m['databases']['central'],
    'username' => $m['admin'], 'password' => $secret['adminPassword'], 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public'];
foreach (['central', 'catalog', 'template'] as $label) {
    $name = $label === 'catalog' ? PostgresSchema::Connection : $label;
    $db->addConnection([...$configuration, 'database' => $m['databases'][$label]], $name);
}
$connections = $db->getDatabaseManager();
$connections->setDefaultConnection('central');
if ((string) $connections->connection('central')->selectOne('SELECT system_identifier FROM pg_control_system()')->system_identifier !== $m['systemIdentifier']) {
    throw new RuntimeException('Wrong isolated server.');
}
$app->instance('db', $connections);
$app->bind('db.schema', fn () => $connections->connection()->getSchemaBuilder());
Facade::setFacadeApplication($app);

function migrateSet(string $connection, string $path): array
{
    global $connections, $app;
    $connections->setDefaultConnection($connection);
    $app->forgetInstance('db.schema');
    Illuminate\Support\Facades\Schema::clearResolvedInstance('db.schema');
    $repository = new DatabaseMigrationRepository($connections, PostgresSchema::Repository);
    $repository->setSource($connection);
    if (!$repository->repositoryExists()) {
        $repository->createRepository();
    }
    $migrator = new Migrator($repository, $connections, new Filesystem());
    $migrator->setConnection($connection);
    $migrator->run([$path]);
    return $repository->getRan();
}

$rootSet = (new CoreX\Tenancy\Internal\TenancySchemaSets())->sets()[0];
$roots = migrateSet('central', $rootSet->path);
$set = (new CoreX\Tenant\Postgres\PostgresSchemaSet())->sets()[0];
if ($set->connection !== PostgresSchema::Connection || $set->path !== PostgresSchema::migrationPath()) {
    throw new RuntimeException('Wrong public catalog schema selection.');
}
$catalogRan = migrateSet($set->connection, $set->path);
if (count($catalogRan) !== 6 || migrateSet(PostgresSchema::Connection, PostgresSchema::migrationPath()) !== $catalogRan) {
    throw new RuntimeException('CoreX/standalone must share one six-migration catalog history.');
}
$catalog = $connections->connection(PostgresSchema::Connection);
$central = $connections->connection('central');
foreach (['root_clusters', 'root_templates', 'root_account_exports'] as $old) {
    if ($central->getSchemaBuilder()->hasTable($old)) {
        throw new RuntimeException('Old physical schema remains active.');
    }
}
foreach (['db_name', 'cluster_id', 'template_version', 'signup_key', 'resource_incarnation', 'pool_cleanup'] as $column) {
    if ($central->getSchemaBuilder()->hasColumn('root_accounts', $column)) {
        throw new RuntimeException('Mixed physical account column: '.$column);
    }
}
$template = $connections->connection('template');
$template->statement('DROP TABLE tenant_binding');
$template->statement('CREATE TABLE tenant_binding (singleton boolean PRIMARY KEY DEFAULT true CHECK(singleton), protocol text NOT NULL, owner_id text NOT NULL, resource_ref text NOT NULL, incarnation text NOT NULL, recovery_generation text NOT NULL)');
$marker = ['protocol' => 'tenant.binding/1', 'owner_id' => 'template', 'resource_ref' => 'template', 'incarnation' => 'template', 'recovery_generation' => 'template'];
$template->table('tenant_binding')->insert($marker);
$template->statement('CREATE TABLE records (id integer PRIMARY KEY, content text NOT NULL)');
$template->table('records')->insert(['id' => 1, 'content' => 'original:p54']);
$template->statement('CREATE TABLE corex_scope_rows (id text PRIMARY KEY, workspace_id text NULL)');
$template->table('corex_scope_rows')->insert([['id' => 'rNull', 'workspace_id' => null], ['id' => 'rW', 'workspace_id' => 'w'], ['id' => 'rOther', 'workspace_id' => 'other']]);
$outbox = dirname((new ReflectionClass(CoreX\CoreServiceProvider::class))->getFileName(), 2).'/database/migrations/tenant/2026_10_01_010120_create_sys_outbox_messages.php';
migrateSet('template', $outbox);
migrateSet('central', $outbox);
// Only product fixture IO is writable. Marker, schema and database DDL remain restricted.
$template->statement('GRANT SELECT,INSERT,UPDATE,DELETE ON probes, records, corex_scope_rows, sys_outbox_messages TO "'.$m['runtime'].'"');
$template->statement('REVOKE CREATE ON DATABASE "'.$m['databases']['template'].'" FROM PUBLIC');
$serializer = new SchemaCatalogueSerializer();
$hash = $serializer->hash($template);
$payload = ['template_id' => '12121212-1212-4212-8212-121212121212', 'template_version' => 7, 'canonical_schema_sha256' => $hash];
$keypair = sodium_crypto_sign_keypair();
$encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$proof = ['templateId' => $payload['template_id'], 'clusterId' => '13131313-1313-4313-8313-131313131313',
    'payload' => $payload, 'marker' => $marker, 'digest' => hash('sha256', $serializer->encode($payload)),
    'signature' => $encode(sodium_crypto_sign_detached("tenant-template-manifest/v1\0".$serializer->encode($payload), sodium_crypto_sign_secretkey($keypair))),
    'publicKey' => $encode(sodium_crypto_sign_publickey($keypair))];
file_put_contents($m['root'].'/template-proof.json', json_encode($proof, JSON_THROW_ON_ERROR));
chmod($m['root'].'/template-proof.json', 0600);
$template->disconnect();
mkdir($m['root'].'/anchor', 0700);
mkdir($m['root'].'/archives', 0700);
$anchor = FileRecoveryAnchor::create($m['root'].'/anchor/anchor.json');
$catalog->table('tenant_pg_control')->insert(['id' => 1, 'recovery_generation' => $anchor->generation()]);
$catalog->table('tenant_pg_templates')->insert(['id' => $proof['templateId'], 'version' => 7, 'status' => 'ready', 'db_name' => $m['databases']['template'], 'schema_hash' => $hash]);
$catalog->table('tenant_pg_clusters')->insert(['id' => $proof['clusterId'], 'template_version' => 7, 'status' => 'active', 'capacity' => 64, 'database_count' => 0]);
$central->table('root_identities')->insert(['id' => '00000000-0000-4000-8000-000000000099', 'email' => 'owner@example.test']);
foreach (['a', 'b'] as $number => $label) {
    $id = '00000000-0000-4000-8000-00000000000'.($number + 1);
    $central->table('root_accounts')->insert(['id' => $id, 'slug' => $label, 'status' => 'active', 'owner_identity_id' => '00000000-0000-4000-8000-000000000099', 'embedding_model' => 'lab-only', 'embedding_dim' => 3]);
    $central->table('root_domains')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'account_id' => $id,
        'host' => $label.'.example.test', 'type' => 'platform', 'is_primary' => true, 'verified_at' => new DateTimeImmutable(), 'tls_status' => 'issued']);
    $route = static fn (object $resource): array => [...$configuration, 'database' => $resource->database_name, 'username' => $m['runtime'], 'password' => $secret['runtimePassword']];
    $trust = new TemplateTrust([$proof['templateId'] => ['payload' => $payload, 'signature' => $proof['signature'], 'trusted_key_id' => 'lab']], ['lab' => $proof['publicKey']]);
    $owner = PostgresFactory::owner($catalog, $connections, $trust, $route, static fn (string $resourceId): string => $m['databases'][$label], $m['runtime'], $marker, anchor: $anchor);
    $intent = new ProvisionIntent('corex-account:'.$id, $proof['clusterId'], $proof['templateId'], 7, $proof['digest'], ['fixture' => $label]);
    $key = new OperationKey('corex:fixture', $label);
    $map = new CoreX\Tenant\Postgres\AccountResourceMap($central);
    $map->reserve($id, $intent, $key);
    $receipt = $owner->claim($intent, $key, new Admission(static fn (): bool => true));
    $map->bind($id, $receipt);
    if ($receipt->status !== 'succeeded') {
        throw new RuntimeException('Public fixture allocation failed.');
    }
    $physical = (new Illuminate\Database\Connectors\ConnectionFactory($app))->make([...$configuration, 'database' => $m['databases'][$label]]);
    $physical->table('probes')->where('name', 'identity')->update(['value' => $label]);
    $physical->disconnect();
}
$central->statement('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO "'.$m['runtime'].'"');
$central->statement('GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO "'.$m['runtime'].'"');
echo json_encode(['status' => 'passed', 'catalogMigrations' => $catalogRan, 'productMigrations' => $roots,
    'source' => getenv('P54_SOURCE'), 'bindingOwner' => 'tenant-postgres', 'databases' => $m['databases']], JSON_THROW_ON_ERROR), "\n";
