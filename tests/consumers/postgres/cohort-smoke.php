<?php

declare(strict_types=1);

putenv('COREX_PROBE_HOST_ONLY=1');
require getenv('COREX_CONSUMER_CHECKOUT').'/packages/tenancy/tests/Pg/TenantRuntimeConsumerProbe.php';
$h = corexProbeHost($m); $app = $h->app;
$owner = $app->make(CoreX\Context\Contracts\AccountIsolationOwner::class);
$account = new CoreX\Context\Data\AccountRef('00000000-0000-4000-8000-000000000001', 'a', 'active', [], []);
$binding = $owner->resolve($account->id);
$manager = $app->make(CoreX\Context\Contracts\TenancyManager::class);
$marker = 'forward-cohort-'.bin2hex(random_bytes(5));
$manager->runFor($account, null, function () use ($h, $marker): void {
    $h->db()->table('probes')->insert(['name' => $marker, 'value' => 'forward']);
    if ($h->db()->table('probes')->where('name', $marker)->value('value') !== 'forward') { throw new RuntimeException('Forward cohort actual IO failed.'); }
});
if ($h->sql('central', "SELECT * FROM probes WHERE name='".$marker."'") !== []) { throw new RuntimeException('Forward IO leaked to central.'); }
echo json_encode(['status' => 'passed', 'scope' => 'full restored source cohort actual public owner/runtime SQL', 'owner' => $binding['ownerId'],
    'incarnation' => $binding['incarnation'], 'marker' => $marker, 'sourceFiles' => [
        'factory' => (new ReflectionClass(Tenant\Postgres\PostgresFactory::class))->getFileName(),
        'adapter' => (new ReflectionClass(CoreX\Tenant\Postgres\PostgresAccountOwner::class))->getFileName()]], JSON_THROW_ON_ERROR), PHP_EOL;
