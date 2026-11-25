<?php

declare(strict_types=1);
require_once __DIR__.'/public-errors-autoload.php';
require getenv('P41_AUTOLOAD');spl_autoload_register(static function(string $class):void{$prefix='Tenant\\Postgres\\';if(str_starts_with($class,$prefix))require __DIR__.'/../../packages/postgres/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';});
use Tenant\Postgres\Exceptions\TemplateIntegrityException;use Tenant\Postgres\Internal\Database\{SchemaCatalogueSerializer,TemplateManifest};
try{TemplateManifest::fromConfig(['payload'=>[0=>'unnamed'],'signature'=>'AA','trusted_key_id'=>'test']);throw new RuntimeException('Unnamed member accepted');}catch(TemplateIntegrityException){}
$m=TemplateManifest::fromConfig(['payload'=>['template_id'=>'template'],'signature'=>'','trusted_key_id'=>'test']);
try{$m->assertTrusted(['test'=>'AA'],new SchemaCatalogueSerializer);throw new RuntimeException('Empty signature accepted');}catch(TemplateIntegrityException){}
try{$m->assertTrusted([],new SchemaCatalogueSerializer);throw new RuntimeException('Untrusted key accepted');}catch(TemplateIntegrityException){}
echo "Original manifest negative cases passed.\n";
