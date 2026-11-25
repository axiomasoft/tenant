<?php

declare(strict_types=1);

it('STATE-04 actual worker poison stream token leak exit and FPM replacement', function (): void {
    foreach([false,true]as$decorated){
        $manifest=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
        $checks=[];$assertions=0;$logs=[];
        foreach(['poison','stream','stream-throw','leak']as$operation){
            $root=getenv('TENANT_OBSERVATIONS').'/'.$operation.'-'.($decorated?'decorator':'default');if(!is_dir($root))mkdir($root,0700,true);
            $process=new \Symfony\Component\Process\Process([PHP_BINARY,TENANT_SOURCE_ROOT.'/tests/laravel/Support/worker-front.php','__'.$operation,$decorated?'1':'0'],TENANT_SOURCE_ROOT,['TENANT_OBSERVATIONS'=>$root],timeout:30);
            $process->run();file_put_contents($root.'/worker.log',$process->getOutput().$process->getErrorOutput());
            expect($process->getExitCode())->toBe(70);$assertions++;
            $start=json_decode(file_get_contents($root.'/supervisor-start.json'),true);$checks[]=['exit'=>$process->getExitCode(),'pid'=>$start['pid'],'operation'=>$operation];
            $host=new \TenantLab\LabHost($manifest);$db=$host->app['db']->connection('central');$jobs=$db->table('jobs')->where('queue',$start['queue'])->orderBy('id')->get();
            expect(count($jobs))->toBe(2)->and($jobs[0]->reserved_at)->not->toBeNull()->and($jobs[1]->reserved_at)->toBeNull()->and($db->table('probes')->where('name',$start['queue'].'-sentinel')->exists())->toBeFalse();$assertions+=4;
            expect(file_exists($root.'/unexpected-survivor.json'))->toBeFalse();$assertions++;
            if($operation==='poison'){ $caught=json_decode(file_get_contents($root.'/poison-caught.json'),true);expect($caught['assertions'])->toBeGreaterThan(4);$assertions+=$caught['assertions']; }
            $next=new \Symfony\Component\Process\Process([PHP_BINARY,TENANT_SOURCE_ROOT.'/tests/laravel/Support/worker-front.php','__resume',$decorated?'1':'0',$start['queue']],TENANT_SOURCE_ROOT,['TENANT_OBSERVATIONS'=>$root],timeout:30);$next->mustRun();
            $replacement=json_decode(file_get_contents($root.'/replacement.json'),true);expect($replacement['pid'])->not->toBe($start['pid'])->and($db->table('probes')->where('name',$start['queue'].'-sentinel')->value('value'))->toBe((string)$replacement['pid']);$assertions+=2;
            $logs[$operation]=['start'=>$start,'replacement'=>$replacement,'log'=>$root.'/worker.log','logHash'=>hash_file('sha256',$root.'/worker.log')];
        }
        $fpm=\TenantLab\FpmProbe::run($manifest,$decorated);$assertions+=$fpm['assertions'];
        $result=['fixture'=>'STATE-04','variant'=>$decorated?'decorator':'default','assertions'=>$assertions,'workerChecks'=>$checks,'workers'=>$logs,'fpm'=>$fpm];
        file_put_contents(getenv('TENANT_OBSERVATIONS').'/STATE-04-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }
});
