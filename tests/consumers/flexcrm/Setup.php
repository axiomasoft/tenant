<?php

declare(strict_types=1);

require __DIR__.'/Environment.php';
$c = getenv('FLEXCRM_CHECKOUT');
require $c.'/vendor/autoload.php';
$app = require $c.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$secret = json_decode(file_get_contents($m['credentials']), true, flags:JSON_THROW_ON_ERROR);
$base = config('database.connections.central');
foreach (['a','b'] as $label) {
    $db = $m['databases'][$label];
    $base['database']=$db; $base['username']=$m['admin']; $base['password']=$secret['adminPassword'];
    config(['database.connections.seed'=>$base]);
    $app['db']->purge('seed');$app['db']->setDefaultConnection('seed');
    $connection=$app['db']->connection('seed');
    if ($connection->selectOne('select current_database() as name')->name !== $db || !str_ends_with($db,'_test')) {throw new RuntimeException('Invalid migration target');}
    $connection->statement('CREATE TABLE IF NOT EXISTS p53_lab_migrations (path text PRIMARY KEY, hash text NOT NULL)');
    $files=glob($c.'/database/migrations/*.php');sort($files);
    $azFiles=glob($c.'/vendor/axioma-studio/azguard-core/database/migrations/*.php');sort($azFiles);
    $files=array_merge($azFiles,$files,glob($c.'/vendor/axiomasoft/corex/database/migrations/tenant/*.php'),glob($c.'/vendor/axiomasoft/corex-tasks/database/migrations/tenant/*.php'),glob($c.'/vendor/flex/crm/database/migrations/tenant/*.php'));
    foreach ($files as $file) {
        $name=basename($file);
        if (str_contains($file,'/corex/database/') && preg_match('/create_mod_(modules|records|lifecycle_log)/',$name)) {continue;}
        $relative=str_replace($c.'/', '',$file);$hash=hash_file('sha256',$file);
        $old=$connection->table('p53_lab_migrations')->where('path',$relative)->first();
        if ($old!==null) {if ($old->hash!==$hash) throw new RuntimeException('Migration source drift '.$relative);continue;}
        $connection->beginTransaction();
        try { $migration=require $file;$migration->up();$connection->table('p53_lab_migrations')->insert(['path'=>$relative,'hash'=>$hash]);$connection->commit();echo "MIGRATED $label $relative\n"; }
        catch (Throwable $e) {$connection->rollBack();throw $e;}
    }
    // Disposable fixture journal belongs to this lab, not any working application.
    $connection->unprepared('CREATE TABLE IF NOT EXISTS p53_effects(id text PRIMARY KEY, workspace_id uuid, actor_id uuid, event text NOT NULL); CREATE TABLE IF NOT EXISTS p53_outbox(id text PRIMARY KEY, workspace_id uuid, actor_id uuid, event text NOT NULL)');
    $role=$m['runtime'];
    $connection->unprepared('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO "'.$role.'"; GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO "'.$role.'"');
    echo "SCHEMA READY $label\n";
}
unset($secret);
