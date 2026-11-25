<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Tenant\Data\{BindingExpectation, BindingVerificationInput};
use Tenant\Postgres\{FileRecoveryAnchor, PostgresFactory};
use Tenant\Postgres\Data\PhysicalAccess;

$m = $p54Manifest;
$secret = json_decode(file_get_contents($m['credentials']), true, flags: JSON_THROW_ON_ERROR);
$app = new Container(); $db = new Manager($app);
$config = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $m['ports']['pg'], 'database' => $m['databases']['catalog'],
    'username' => $m['admin'], 'password' => $secret['adminPassword'], 'schema' => 'public', 'charset' => 'utf8', 'prefix' => ''];
$db->addConnection($config, 'tenant_pg_catalog');
$catalog = $db->getConnection('tenant_pg_catalog');
if ((string) $catalog->selectOne('SELECT system_identifier FROM pg_control_system()')->system_identifier !== $m['systemIdentifier']) {
    throw new RuntimeException('Wrong isolated server.');
}
$route = static fn (object $row): array => [...$config, 'database' => $row->database_name, 'username' => $m['runtime'], 'password' => $secret['runtimePassword']];
$anchor = new FileRecoveryAnchor($m['root'].'/anchor/anchor.json');
$bindings = PostgresFactory::bindings($catalog, $route, $anchor);
$a = $catalog->table('tenant_pg_resources')->where('database_name', $m['databases']['a'])->first();
$b = $catalog->table('tenant_pg_resources')->where('database_name', $m['databases']['b'])->first();
$assertions = 0;
function bindingCheck(bool $ok, string $description): void {
    global $assertions;
    if (!$ok) { throw new RuntimeException($description); }
    $assertions++; echo 'PASS BINDING '.$description.PHP_EOL;
}
function bindingDenied(Closure $call, string $type, string $description): void {
    try { $call(); } catch (Throwable $failure) { bindingCheck($failure instanceof $type, $description.' ['.get_class($failure).']'); return; }
    throw new RuntimeException('Expected denial: '.$description);
}
$resolved = $bindings->resolve($a->resource_ref);
$verified = $bindings->verify(new BindingVerificationInput($resolved->expected, $resolved->access));
bindingCheck($resolved->expected->resourceRef === $a->resource_ref && $verified->ownerId === 'tenant-postgres' && $verified->recoveryGeneration === $anchor->generation(), 'public facade verifies actual catalog/marker/anchor');
bindingDenied(fn () => serialize($resolved), LogicException::class, 'resolved route capability cannot be serialized');
bindingDenied(fn () => $bindings->verify(new BindingVerificationInput(new BindingExpectation($a->resource_ref, 'stale', $a->route_revision), $resolved->access)), Tenant\Exceptions\BindingMismatch::class, 'stale incarnation refused');
bindingDenied(fn () => $bindings->verify(new BindingVerificationInput($resolved->expected, new PhysicalAccess($route($b)))), Tenant\Exceptions\BindingMismatch::class, 'A cannot use B route');
bindingDenied(fn () => PostgresFactory::bindings($catalog, $route, new FileRecoveryAnchor($m['root'].'/missing-anchor.json'))->resolve($a->resource_ref), Tenant\Exceptions\BindingMismatch::class, 'missing anchor fails closed');
bindingDenied(fn () => PostgresFactory::bindings($catalog, static fn (object $r): array => [...$route($r), 'username' => $m['admin'], 'password' => $secret['adminPassword']], $anchor)->resolve($a->resource_ref), Tenant\Exceptions\BindingMismatch::class, 'privileged runtime route refused');
bindingDenied(fn () => PostgresFactory::bindings($catalog, static fn (object $r): array => [...$route($r), 'port' => 1], $anchor)->resolve($a->resource_ref), Tenant\Exceptions\BindingMismatch::class, 'route cannot leave frozen management server');
$admin = (new Illuminate\Database\Connectors\ConnectionFactory($app))->make([...$config, 'database' => $m['databases']['a']]);
$old = $admin->table('tenant_binding')->first();
try {
    $admin->table('tenant_binding')->update(['owner_id' => 'corex']);
    bindingDenied(fn () => $bindings->resolve($a->resource_ref), Tenant\Exceptions\BindingMismatch::class, 'old CoreX marker refused without implicit rebind');
} finally { $admin->table('tenant_binding')->update(['owner_id' => $old->owner_id]); }
$runtime = (new Illuminate\Database\Connectors\ConnectionFactory($app))->make($resolved->access->route);
foreach (['UPDATE tenant_binding SET incarnation=incarnation', 'CREATE TABLE forbidden_p54(id integer)', 'CREATE DATABASE forbidden_p54_test'] as $sql) {
    try { $runtime->statement($sql); throw new LogicException('Runtime permission leaked: '.$sql); }
    catch (Illuminate\Database\QueryException $failure) { bindingCheck($failure->getCode() === '42501', 'actual runtime DDL/marker permission refused: '.$sql); }
}
bindingCheck($bindings->resolve($a->resource_ref)->expected->incarnation === $a->incarnation, 'failed mutation leaves original binding current');
bindingCheck(!str_contains((new ReflectionClass(Tenant\Postgres\Contracts\ResourceBindings::class))->getFileName(), '/Internal/'), 'resource facade public in source and fresh no-dev install');
$runtime->disconnect(); $admin->disconnect(); $catalog->disconnect();
echo json_encode(['status' => 'passed', 'assertions' => $assertions, 'skipped' => 0, 'source' => getenv('P54_SOURCE')], JSON_THROW_ON_ERROR), PHP_EOL;
