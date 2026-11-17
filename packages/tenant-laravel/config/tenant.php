<?php

declare(strict_types=1);

return [
    'runtime_profile' => 'laravel-pg-database',
    'central_connection' => 'central',
    'cache_stores' => ['tenant'],
    'central_cache_stores' => ['central'],
    'facilities' => ['database' => true, 'cache' => true, 'session' => true, 'storage' => true],
    'storage_driver' => 'local',
    'shared' => [],
];
