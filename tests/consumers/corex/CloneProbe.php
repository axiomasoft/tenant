<?php
putenv('TENANT_LAB_SUPPORT='.dirname(__DIR__,2).'/laravel/Support');
require getenv('COREX_CONSUMER_CHECKOUT').'/packages/tenancy/tests/Pg/CloneBindingProbe.php';
