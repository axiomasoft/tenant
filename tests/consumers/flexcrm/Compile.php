<?php
require __DIR__.'/Boot.php';
$code=$app->make(Illuminate\Contracts\Console\Kernel::class)->call('corex:modules:compile');
echo $app->make(Illuminate\Contracts\Console\Kernel::class)->output();
exit($code);
