<?php
require __DIR__.'/Environment.php';
$checkout = getenv('FLEXCRM_CHECKOUT');
require $checkout.'/vendor/autoload.php';
$app = require $checkout.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'BOOT '.Illuminate\Foundation\Application::VERSION.' '.get_class($app->make(Tenant\Contracts\TenantRuntime::class)).PHP_EOL;
