<?php
require __DIR__.'/Harness.php';$app=flexApp();flexReset($app);$seed=flexSeed($app);$app->make(CoreX\Context\Contracts\TenancyManager::class)->end();echo "COMPLETE HTTP fixture setup\n";
