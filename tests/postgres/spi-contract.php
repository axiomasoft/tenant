<?php

declare(strict_types=1);
require getenv('P41_AUTOLOAD');spl_autoload_register(static function(string $class):void{$p='Tenant\\Postgres\\';if(str_starts_with($class,$p))require __DIR__.'/../../packages/tenant-postgres/src/'.str_replace('\\','/',substr($class,strlen($p))).'.php';});
if(!is_subclass_of(\Tenant\Postgres\ProvisioningOwner::class,\Tenant\Postgres\PostgresLifecycle::class))throw new RuntimeException('Missing lifecycle SPI');
foreach(['Admission','OperationKey','OperationReceipt','OperationRef','ProvisionIntent'] as $name){$root='Tenant\\Postgres\\'.$name;$old='Tenant\\Postgres\\Data\\'.$name;if(!class_exists($root)||!class_exists($old)||!is_a($old,$root,true))throw new RuntimeException('Public/compatibility type mismatch: '.$name);}
$methods=['claim','queryOperation','reconcile','requestExport','queryExport','requestDelete','cancelIntent','admitRecovery'];$r=new ReflectionClass(\Tenant\Postgres\PostgresLifecycle::class);foreach($methods as $name)if(!$r->hasMethod($name))throw new RuntimeException('Missing SPI method: '.$name);
$claim=$r->getMethod('claim');if((string)$claim->getParameters()[0]->getType()!=='Tenant\\Postgres\\ProvisionIntent'||(string)$claim->getReturnType()!=='Tenant\\Postgres\\OperationReceipt')throw new RuntimeException('Public signature mismatch');
echo "Frozen provisioning SPI and compatibility aliases passed; P4.2 operations remain unavailable.\n";
