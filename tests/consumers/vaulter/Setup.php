<?php

declare(strict_types=1);

require __DIR__.'/Host.php';
$m = json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')), true, flags: JSON_THROW_ON_ERROR);
foreach (['a','b'] as $label) {
    $h = new TenantVaulter\Host($m, true, $label);
    $db = $h->app['db']->connection('setup');
    if (!str_ends_with($db->getDatabaseName(), '_test') || $db->getDatabaseName() !== $m['databases'][$label]) throw new RuntimeException('Setup DB guard');
    $paths = $h->app->make(Vaulter\Schema\ModuleSchemas::class)->migrationPaths();
    $files = [];
    foreach ($paths as $path) foreach (glob($path.'/*.php') as $file) $files[] = $file;
    usort($files, static fn ($a, $b) => basename($a) <=> basename($b));
    $existing = array_column($db->select("select tablename from pg_tables where schemaname='public' and tablename like 'v_%'"), 'tablename');
    echo 'RECONCILE setup-only tables '.json_encode($existing).PHP_EOL;
    $db->transaction(function () use ($db, $existing, $files): void {
        foreach ($existing as $table) {
            if (!preg_match('/^v_[a-z0-9_]+$/D', $table)) throw new RuntimeException('Unexpected setup table');
            $db->unprepared('DROP TABLE IF EXISTS "'.$table.'" CASCADE');
        }
        foreach ($files as $file) (require $file)->up();
    });
    $tables = array_column($db->select("select tablename from pg_tables where schemaname='public' and tablename like 'v_%'"), 'tablename');
    if ($tables !== []) $db->unprepared('TRUNCATE '.implode(',', array_map(fn ($v) => '"'.$v.'"', $tables)).' RESTART IDENTITY CASCADE');
    $db->unprepared('GRANT SELECT, INSERT, UPDATE, DELETE ON '.implode(',', array_map(fn ($v) => '"'.$v.'"', $tables)).' TO '.$m['runtime']);
    $db->unprepared('REVOKE INSERT, UPDATE, DELETE ON tenant_binding FROM '.$m['runtime']);
    $db->unprepared('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO '.$m['runtime']);
    echo 'SETUP '.$label.' '.$db->getDatabaseName().' migrations='.count($files).PHP_EOL;
}
$credentials = json_decode(file_get_contents($m['credentials']), true, flags: JSON_THROW_ON_ERROR);
$pdo = new PDO('pgsql:host=127.0.0.1;port='.$m['ports']['pg'].';dbname='.$m['databases']['central'], $m['admin'], $credentials['adminPassword'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS host_owners (id varchar(64) PRIMARY KEY, namespace varchar(32), isolation_id varchar(96))');
$pdo->exec('CREATE TABLE IF NOT EXISTS host_users (id varchar(64) PRIMARY KEY, name text)');
$pdo->exec('CREATE TABLE IF NOT EXISTS scan_reads (id bigserial PRIMARY KEY, namespace text, bytes text, drive_id text, pid bigint)');
$pdo->exec('TRUNCATE host_owners,scan_reads,jobs,failed_jobs RESTART IDENTITY');
$pdo->exec("INSERT INTO host_owners VALUES ('owner-a','alpha','same'),('owner-b','beta','same')");
$pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON host_owners,host_users,scan_reads TO '.$m['runtime']);
$pdo->exec('GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO '.$m['runtime']);
$keys = $h->app['redis']->connection()->keys($m['project'].':central:vaulter:job:*');
if ($keys !== []) $h->app['redis']->connection()->del(...$keys);
echo "PASS actual installed Vaulter migrations and isolated setup/runtime role separation\n";
