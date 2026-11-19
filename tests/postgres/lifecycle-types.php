<?php

declare(strict_types=1);
require_once __DIR__.'/public-errors-autoload.php';
require getenv('P41_AUTOLOAD');
spl_autoload_register(static function(string $class):void{foreach(['Tenant\\Postgres\\'=>__DIR__.'/../../packages/postgres/src/','Tenant\\'=>__DIR__.'/../../packages/core/src/'] as $prefix=>$root)if(str_starts_with($class,$prefix)){require $root.str_replace('\\','/',substr($class,strlen($prefix))).'.php';return;}});
use Tenant\Postgres\{ExportIntent,DeleteIntent,RecoveryIntent,OperationKey,OperationRef,ExportRef};
$operation=new OperationRef('lab','op-1','A','i1');$export=new ExportRef('export-1',$operation);
$a=new ExportIntent('A','i1','archive:local','retention:lab');
$b=new ExportIntent('A','i1','archive:other','retention:lab');
if($a->digest()===$b->digest())throw new RuntimeException('Destination omitted from canonical intent');
$d=new DeleteIntent('A','i1',$export,'policy:lab');
if($d->digest()===(new DeleteIntent('A','i1',new ExportRef('export-2',$operation),'policy:lab'))->digest())throw new RuntimeException('Original export omitted');
foreach([['A','i1','https://secret','retention:lab'],['','i1','archive:local','retention:lab']] as $args){try{new ExportIntent(...$args);throw new RuntimeException('Invalid reference allowed');}catch(InvalidArgumentException){}}
echo "Canonical typed export/delete references passed\n";
