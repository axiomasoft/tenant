<?php

declare(strict_types=1);

if (getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('P5.4 requires APP_ENV=testing before autoload.');
}
require getenv('TENANT_LANE_AUTOLOAD');
$p54Root = getenv('P54_TENANT_CHECKOUT') ?: dirname(__DIR__, 3);
if (getenv('P54_TENANT_CHECKOUT') && (!str_starts_with($p54Root, '/tmp/tenant-p54-') || is_link($p54Root))) {
    throw new RuntimeException('Unexpected isolated Tenant cohort root.');
}
$p54Checkout = getenv('COREX_CONSUMER_CHECKOUT');
if (getenv('P54_SOURCE') === '1') {
    $loader = new Composer\Autoload\ClassLoader();
    foreach (['Tenant\\Postgres\\' => $p54Root.'/packages/tenant-postgres/src', 'Tenant\\Laravel\\' => $p54Root.'/packages/tenant-laravel/src',
        'Tenant\\' => $p54Root.'/packages/tenant/src', 'CoreX\\Tenant\\' => $p54Checkout.'/packages/tenant/src',
        'CoreX\\Tenancy\\' => $p54Checkout.'/packages/tenancy/src', 'CoreX\\' => $p54Checkout.'/packages/core/src'] as $prefix => $path) {
        $loader->addPsr4($prefix, $path);
    }
    $loader->addPsr4('Tenant\\Exceptions\\', $p54Root.'/packages/tenant-postgres/src/Exceptions/Public');
    $loader->register(true);
}
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('TenantLab\\', $p54Root.'/tests/laravel/Support');
$loader->register(true);
$p54Manifest = json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')), true, flags: JSON_THROW_ON_ERROR);
foreach ($p54Manifest['databases'] as $label => $database) {
    if ($database !== 'tenant_'.$p54Manifest['runId'].'_'.$label.'_test' || !preg_match('/^[a-z0-9_]+_test$/D', $database)) {
        throw new RuntimeException('Unexpected P5.4 DB target.');
    }
}
if (!preg_match('~^/tmp/tenant_lab_[a-f0-9]{12}$~D', $p54Manifest['root']) || is_link($p54Manifest['root'])) {
    throw new RuntimeException('Unexpected P5.4 filesystem target.');
}
require $p54Checkout.'/packages/tenant/tests/Postgres/Fixture.php';
