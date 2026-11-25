<?php

declare(strict_types=1);
namespace TenantLab;

use Symfony\Component\Process\Process;

final class FpmProbe
{
    public static function run(array $manifest, bool $decorated): array
    {
        $root=$manifest['root'].'/fpm-'.bin2hex(random_bytes(5));mkdir($root,0700);
        $port=self::port();$fast=self::port();$php=PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $fpm=$root.'/fpm.conf';$nginx=$root.'/nginx.conf';
        file_put_contents($fpm,"[global]\ndaemonize=no\npid=$root/fpm.pid\nerror_log=$root/fpm.log\nlog_level=notice\n[tenant]\nlisten=127.0.0.1:$fast\npm=dynamic\npm.max_children=1\npm.start_servers=1\npm.min_spare_servers=1\npm.max_spare_servers=1\npm.max_requests=1\nclear_env=no\ncatch_workers_output=yes\n");
        $front=TENANT_SOURCE_ROOT.'/tests/laravel/Support/fpm-front.php';
        file_put_contents($nginx,"daemon off;\npid $root/nginx.pid;\nerror_log $root/nginx.log;\nevents { worker_connections 32; }\nhttp { access_log $root/access.log; client_body_temp_path $root/body; fastcgi_temp_path $root/fastcgi; server { listen 127.0.0.1:$port; server_name _; location / { include /etc/nginx/fastcgi_params; fastcgi_param SCRIPT_FILENAME $front; fastcgi_param TENANT_SESSION_DRIVER file; fastcgi_pass 127.0.0.1:$fast; } } }\n");
        $resolver=TENANT_SOURCE_ROOT.'/tests/laravel/lab/telemetry-resolver.c';
        $resolverLibrary=$root.'/telemetry-resolver.so';
        (new Process(['/usr/bin/cc','-shared','-fPIC','-O2','-Wall','-Werror',$resolver,'-ldl','-o',$resolverLibrary]))->mustRun();
        $env=['XDEBUG_MODE'=>'off','TENANT_LAB_MANIFEST'=>getenv('TENANT_LAB_MANIFEST'),'TENANT_LANE_AUTOLOAD'=>TENANT_LANE_AUTOLOAD,'TENANT_USE_SOURCE'=>getenv('TENANT_USE_SOURCE')?:'0','TENANT_DECORATED'=>$decorated?'1':'0','TENANT_FPM_ROOT'=>$root];
        $fp=new Process(['/usr/sbin/php-fpm'.$php,'--nodaemonize','--fpm-config',$fpm],TENANT_SOURCE_ROOT,[...$env,'LD_PRELOAD'=>$resolverLibrary],timeout:60);
        $ng=new Process(['/usr/sbin/nginx','-c',$nginx,'-p',$root],TENANT_SOURCE_ROOT,$env,timeout:60);
        $fp->start();$ng->start();$checks=[];
        $check=function(bool $ok,string $message)use(&$checks):void {if(!$ok)throw new \RuntimeException($message);$checks[]=$message;};
        try {
            for($i=0;$i<500;$i++){
                if(!$fp->isRunning()||!$ng->isRunning())throw new \RuntimeException($fp->getErrorOutput().$ng->getErrorOutput());
                set_error_handler(static fn()=>true);
                $s=@fsockopen('127.0.0.1',$port,$errno,$errstr,.1);$f=@fsockopen('127.0.0.1',$fast,$errno,$errstr,.1);restore_error_handler();if($s)fclose($s);if($f){fclose($f);if($s)break;}usleep(20000);
            }
            if(!isset($f)||!$f)throw new \RuntimeException('FPM did not open its allowlisted port; startup stderr: '.$fp->getErrorOutput());
            $fetch=function(string $path,?string $cookie=null,string $driver='file')use($port):array {
                $ctx=stream_context_create(['http'=>['header'=>"Host: a.example.test\r\nX-Session-Driver: $driver\r\n".($cookie===null?'':"Cookie: tenant_session=$cookie\r\n"),'ignore_errors'=>true,'timeout'=>10]]);
                $body=file_get_contents('http://127.0.0.1:'.$port.$path,false,$ctx);$headers=http_get_last_response_headers();$pid=null;$id=null;
                foreach($headers as$header){if(preg_match('/^X-Tenant-Pid: (\d+)/i',$header,$m))$pid=(int)$m[1];if(preg_match('/^Set-Cookie: tenant_session=([^;]+)/i',$header,$m))$id=urldecode($m[1]);}
                return ['body'=>$body,'headers'=>$headers,'pid'=>$pid,'cookie'=>$id];
            };
            $requests=[];
            foreach(['file','redis']as$driver){
                $first=$fetch('/put/fpm-user',driver:$driver);$second=$fetch('/get',$first['cookie'],$driver);$requests[]=$first;$requests[]=$second;
                $check(json_decode($first['body'],true)['login']==='fpm-user'&&json_decode($second['body'],true)['terminated']==='a','FPM '.$driver.' save and terminate inside verified frame');
                $check(is_int($first['pid'])&&is_int($second['pid'])&&$first['pid']!==$second['pid'],'FPM max_requests1 replaces child '.$driver);
            }
            foreach(['poison','stream','leak']as$op){
                $failed=$fetch('/'.$op);$requests[]=$failed;
                $caught=json_decode(file_get_contents($root.'/'.$op.'.json'),true);
                $check(str_contains($failed['headers'][0],'502'),'FPM '.$op.' stopped without successful response');
                for($wait=0;$wait<100;$wait++){ $log=file_get_contents($root.'/fpm.log');if(str_contains($log,'child '.$caught['pid'].' exited on signal 9'))break;usleep(10000); }
                $check(str_contains($log,'child '.$caught['pid'].' exited on signal 9'),'FPM parent recorded signal9 exit before next request '.$op);
                $check(!file_exists($root.'/unexpected-effect'),'no HTTP effect after failed boundary');
                $next=$fetch('/pid');$requests[]=$next;
                $check($next['pid']!==$caught['pid']&&is_int($next['pid']),'parent observes dead '.$op.' PID before fresh request');
                $check($caught['afterEffect']===false,'no post-boundary application effect '.$op);
                if($op!=='stream')$check(!file_exists($root.'/terminated-'.$caught['pid'].'.json'),'no poisoned/foreign-frame termination '.$op);
                else $check(json_decode(file_get_contents($root.'/terminated-'.$caught['pid'].'.json'),true)['id']==='a','stream lease remains A through final scoped terminate before failed exit');
            }
            $result=['assertions'=>count($checks),'checks'=>$checks,'requests'=>$requests,'root'=>$root,'fpmMasterPid'=>$fp->getPid(),'httpMasterPid'=>$ng->getPid(),'telemetryResolver'=>['source'=>$resolver,'sourceHash'=>hash_file('sha256',$resolver),'library'=>$resolverLibrary,'libraryHash'=>hash_file('sha256',$resolverLibrary),'compilerHash'=>hash_file('sha256','/usr/bin/cc'),'scope'=>'getaddrinfo(telemetry.sury.org) only; application DNS/SQL/Redis/HTTP unchanged'],'fpmBinary'=>'/usr/sbin/php-fpm'.$php,'fpmBinaryHash'=>hash_file('sha256','/usr/sbin/php-fpm'.$php),'httpBinaryHash'=>hash_file('sha256','/usr/sbin/nginx'),'configs'=>[$fpm=>hash_file('sha256',$fpm),$nginx=>hash_file('sha256',$nginx)]];
        } finally {$ng->stop();$fp->stop();file_put_contents($root.'/fpm-process.log',$fp->getOutput().$fp->getErrorOutput());file_put_contents($root.'/nginx-process.log',$ng->getOutput().$ng->getErrorOutput());}
        $result['logs']=[];
        foreach(['fpm.log','nginx.log','access.log']as$log)$result['logs'][$log]=file_get_contents($root.'/'.$log);
        return $result;
    }
    private static function port(): int { $s=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($s,false),':'),1);fclose($s);return $port; }
}
