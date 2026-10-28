<?php

declare(strict_types=1);

require __DIR__.'/Host.php';
$count=0;
$check=static function(bool $ok,string $message)use(&$count):void{if(!$ok)throw new RuntimeException($message);$count++;echo 'PASS '.$message.PHP_EOL;};
foreach(['file','redis'] as $driver){
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $env=getenv();$env['TENANT_SESSION_DRIVER']=$driver;
    $log=tempnam(sys_get_temp_dir(),'tenant-vault-http-');
    $process=proc_open([PHP_BINARY,'-d','opcache.enable_cli=0','-d','opcache.jit_buffer_size=0','-S','127.0.0.1:'.$port,__DIR__.'/HttpFront.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__,3),$env);fclose($pipes[0]);
    try{
        for($i=0;$i<100;$i++){if(str_contains(file_get_contents($log),'Development Server'))break;usleep(20000);}
        $fetch=static function(string $host,string $path,?string $cookie=null)use($port):array{
            $headers="Host: ".$host."\r\n".($cookie===null?'':"Cookie: tenant_session=".$cookie."\r\n");
            $body=file_get_contents('http://127.0.0.1:'.$port.$path,false,stream_context_create(['http'=>['header'=>$headers,'ignore_errors'=>true,'timeout'=>5]]));
            $reply=http_get_last_response_headers();$id=null;foreach($reply as $header)if(preg_match('/^Set-Cookie: tenant_session=([^;]+)/i',$header,$m))$id=urldecode($m[1]);
            return [json_decode($body,true),$id,$reply[0]??''];
        };
        [$a,$cookie,$status]=$fetch('a.example.test','/vault');
        $check(str_contains($status,'200')&&$a['namespace']==='alpha'&&$a['user']===null&&is_string($cookie),'actual Vaulter socket HTTP writes selected A '.$driver);
        [$v,,$status]=$fetch('a.example.test','/session',$cookie);
        $check($v['login']==='alpha'&&$v['terminated']==='alpha','HTTP session save and kernel terminate retained A '.$driver);
        [$v,,$status]=$fetch('b.example.test','/session',$cookie);
        $check(str_contains($status,'200')&&$v['login']===null&&$v['user']===null,'foreign cookie cannot authenticate or inherit A session in B '.$driver);
        [$b,,$status]=$fetch('b.example.test','/vault');
        $check(str_contains($status,'200')&&$b['namespace']==='beta'&&$b['tenant']!==$a['tenant'],'actual Vaulter HTTP projects B independently '.$driver);
        [,, $status]=$fetch('unknown.example.test','/vault');
        $check(str_contains($status,'403'),'untrusted HTTP selector refused '.$driver);
    }finally{
        proc_terminate($process);proc_close($process);
        echo 'HTTP_SERVER_LOG '.file_get_contents($log);unlink($log);
    }
}
echo 'COMPLETE Vaulter HTTP '.$count.' assertions'.PHP_EOL;
