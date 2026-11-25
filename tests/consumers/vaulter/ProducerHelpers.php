<?php

declare(strict_types=1);
namespace TenantVaulter;

function runWorker(string $label): void
{
    $proc=proc_open([PHP_BINARY,__DIR__.'/Worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,3),getenv());
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
    echo 'CHILD '.$label.PHP_EOL.$out.$err;
    if($exit!==0)throw new \RuntimeException('Worker failed '.$label.' exit'.$exit);
}
