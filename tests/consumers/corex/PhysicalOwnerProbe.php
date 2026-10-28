<?php

declare(strict_types=1);

$checkout = getenv('COREX_CONSUMER_CHECKOUT');
if (!is_string($checkout) || !is_file($checkout.'/packages/tenancy/tests/Pg/TenantRuntimeConsumerProbe.php')) throw new RuntimeException('Missing isolated CoreX consumer checkout');
putenv('TENANT_LAB_SUPPORT='.dirname(__DIR__,2).'/laravel/Support');
require $checkout.'/packages/tenancy/tests/Pg/TenantRuntimeConsumerProbe.php';
