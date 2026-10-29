<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

$classes = [Tenant\Postgres\PostgresFactory::class, Tenant\Postgres\ProvisioningOwner::class, Tenant\Postgres\PostgresSchema::class,
    Tenant\Postgres\Contracts\ResourceBindings::class, Tenant\Postgres\Data\ResolvedBinding::class,
    Tenant\Postgres\RecoveryOperator::class, CoreX\Tenant\Postgres\PostgresAccountOwner::class,
    CoreX\Tenant\Postgres\PostgresAccountProvisioner::class, CoreX\Tenant\Postgres\PostgresAccountLifecycle::class,
    CoreX\Tenant\Postgres\PostgresOwnerServiceProvider::class, CoreX\Tenant\CorexTenantServiceProvider::class];
$classes[] = CoreX\Tenant\Postgres\PostgresSchemaMaintenance::class;
$rows = [];
$installedRoot = realpath(dirname(getenv('TENANT_LANE_AUTOLOAD')));
foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();
    if (getenv('P54_SOURCE') === '1') {
        $expected = str_starts_with($class, 'CoreX\\') ? $p54Checkout.'/packages/' : $p54Root.'/packages/';
    } else { $expected = $installedRoot.'/'; }
    if (!str_starts_with($file, $expected)) { throw new RuntimeException('Wrong source/install class origin: '.$class.' '.$file); }
    $methods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $methods[$method->getName()] = ['parameters' => array_map(static fn (ReflectionParameter $p): array => ['name' => $p->getName(), 'type' => (string) $p->getType(), 'optional' => $p->isOptional()], $method->getParameters()), 'return' => (string) $method->getReturnType()];
    }
    $rows[$class] = ['file' => $file, 'sha256' => hash_file('sha256', $file), 'methods' => $methods];
}
if (!isset($rows[Tenant\Postgres\PostgresFactory::class]['methods']['bindings'])
    || !isset($rows[Tenant\Postgres\ProvisioningOwner::class]['methods']['recoveryOperator'])) { throw new RuntimeException('Missing owning public seams.'); }
echo json_encode(['status' => 'passed', 'source' => getenv('P54_SOURCE'), 'classes' => $rows, 'php' => PHP_VERSION,
    'laravel' => Composer\InstalledVersions::getPrettyVersion('laravel/framework'), 'discovered' => count($rows), 'skipped' => 0], JSON_THROW_ON_ERROR), PHP_EOL;
