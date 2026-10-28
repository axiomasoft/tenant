<?php
require __DIR__.'/Environment.php';
$secret=json_decode(file_get_contents($m['credentials']),true,flags:JSON_THROW_ON_ERROR);
foreach($m['databases'] as $label=>$db){
    $pdo=new PDO('pgsql:host=127.0.0.1;port='.$m['ports']['pg'].';dbname='.$db,$m['runtime'],$secret['runtimePassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $row=$pdo->query("select current_database() as db, rolsuper, rolcreatedb, rolcreaterole, rolreplication, has_schema_privilege(current_user,'public','CREATE') as ddl from pg_roles where rolname=current_user")->fetch(PDO::FETCH_ASSOC);
    if($row['db']!==$db || !str_ends_with($db,'_test') || array_filter(array_intersect_key($row,array_flip(['rolsuper','rolcreatedb','rolcreaterole','rolreplication','ddl']))))throw new RuntimeException('Unsafe runtime target or privileges');
}
echo "PASS actual PG16 exact _test allowlist and runtime role cannot DDL\n";
echo "COMPLETE FlexCRM preflight\n";
