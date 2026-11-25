<?php
require __DIR__.'/Environment.php';
$c=getenv('FLEXCRM_CHECKOUT');require $c.'/vendor/autoload.php';
$rows=[];$paths=[];
foreach(['app','modules','packages/flexcrm','config','bootstrap'] as $base){
    $iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($c.'/'.$base,FilesystemIterator::SKIP_DOTS));
    foreach($iter as $path){if($path->isFile() && $path->getExtension()==='php' && !str_contains($path->getPathname(),'/bootstrap/cache/'))$paths[]=$path->getPathname();}
}
foreach($paths as $file){
    $tokens=token_get_all(file_get_contents($file));
    foreach($tokens as $token){
        if(!is_array($token) || !in_array($token[0],[T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED],true))continue;
        $name=ltrim($token[1],'\\');
        if(!str_starts_with($name,'CoreX\\') && !str_starts_with($name,'Tenant\\') && !str_starts_with($name,'Flex\\Crm\\'))continue;
        if(str_contains($name,'\\Internal\\') || str_starts_with($name,'CoreX\\Api\\') || str_starts_with($name,'CoreX\\Enums\\'))throw new RuntimeException('Private or retired consumer reference '.$file.' '.$name);
        if(!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name))throw new RuntimeException('Unresolved public import '.$name.' in '.$file);
        $reflection=new ReflectionClass($name);$source=$reflection->getFileName();
        if(!is_string($source) || !str_starts_with($source,$c.'/vendor/'))throw new RuntimeException('Not an installed artifact '.$name);
        $rows[]=['file'=>substr($file,strlen($c)+1),'line'=>$token[2],'symbol'=>$name,'installed'=>substr($source,strlen($c)+1),'sha256'=>hash_file('sha256',$source)];
    }
}
$dest=getenv('FLEXCRM_TRACE_OUTPUT');
if(is_string($dest) && $dest!=='')file_put_contents($dest,json_encode(['status'=>'passed','references'=>$rows,'files'=>count($paths)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
echo 'PASS exact public installed source references '.count($rows).' across '.count($paths).' files'.PHP_EOL;
echo "COMPLETE public source trace\n";
