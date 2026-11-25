<?php
require __DIR__.'/lifecycle-support.php';
use Tenant\Postgres\Internal\Database\SchemaCatalogueSerializer;
$reviewCluster='41414141-4141-4141-8141-414141414141';
$existing=$c->table('tenant_pg_templates')->where('id','12121212-1212-4212-8212-121212121212')->first();
if($existing!==null){
    $source=$m['root'].'/receipt-race-trust.json';$destination=$m['root'].'/review-trust.json';check(is_file($source)&&!file_exists($destination),'Signed P4.1 proof missing or already copied');
    file_put_contents($destination,file_get_contents($source));chmod($destination,0600);
    $c->table('tenant_pg_clusters')->insert(['id'=>$reviewCluster,'status'=>'active','template_version'=>7,'database_count'=>0,'capacity'=>30]);
    echo json_encode(['status'=>'passed','scope'=>'reuse actual signed P4.1 template with independent regression allocation cluster','cluster'=>$reviewCluster]),"\n";return;
}
$gold=$base.'_reviewgold_test';check($c->selectOne('SELECT oid FROM pg_database WHERE datname=?',[$gold])===null,'No blind template recreation');$c->getPdo()->exec('CREATE DATABASE "'.$gold.'"');
$x=$factory->make([...$cfg,'database'=>$gold],'review-gold');$x->statement('CREATE TABLE records (id integer PRIMARY KEY, content text NOT NULL)');$x->table('records')->insert(['id'=>1,'content'=>'signed-template-sentinel']);
$x->statement('CREATE TABLE tenant_binding (singleton boolean PRIMARY KEY DEFAULT true CHECK(singleton),protocol text NOT NULL,owner_id text NOT NULL,resource_ref text NOT NULL,incarnation text NOT NULL,recovery_generation text NOT NULL)');
$x->table('tenant_binding')->insert(['protocol'=>'tenant.binding/1','owner_id'=>'template','resource_ref'=>'template','incarnation'=>'template','recovery_generation'=>'template']);
$x->statement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');$x->statement('REVOKE CREATE ON DATABASE "'.$gold.'" FROM PUBLIC');$x->statement('GRANT SELECT ON ALL TABLES IN SCHEMA public TO "'.$m['runtime'].'"');
$serializer=new SchemaCatalogueSerializer;$hash=$serializer->hash($x);$x->disconnect();$template='12121212-1212-4212-8212-121212121212';$cluster=$reviewCluster;
$c->table('tenant_pg_templates')->insert(['id'=>$template,'version'=>7,'status'=>'ready','db_name'=>$gold,'schema_hash'=>$hash]);$c->table('tenant_pg_clusters')->insert(['id'=>$cluster,'status'=>'active','template_version'=>7,'database_count'=>0,'capacity'=>30]);
$payload=['template_id'=>$template,'template_version'=>7,'canonical_schema_sha256'=>$hash];$pair=sodium_crypto_sign_keypair();$enc=static fn(string $s):string=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');$signature=sodium_crypto_sign_detached("tenant-template-manifest/v1\0".$serializer->encode($payload),sodium_crypto_sign_secretkey($pair));
$file=$m['root'].'/review-trust.json';check(!file_exists($file),'No trust overwrite');file_put_contents($file,json_encode(['payload'=>$payload,'signature'=>$enc($signature),'key'=>$enc(sodium_crypto_sign_publickey($pair)),'digest'=>hash('sha256',$serializer->encode($payload))],JSON_THROW_ON_ERROR));chmod($file,0600);
echo json_encode(['status'=>'passed','scope'=>'actual signed template/catalog/runtime marker and restricted role','template'=>$template,'schemaHash'=>$hash]),"\n";
