<?php

declare(strict_types=1);

use Tenant\Laravel\Contracts\Storage\ResourceFilesystem;
use Tenant\Laravel\Contracts\Storage\ResourceStorage;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;
use Tenant\Laravel\Internal\Runtime\LaravelRuntimeFactory;

(static function (): void {
    $contracts = 'Tenant\\Laravel\\Contracts\\';
    $internal = 'Tenant\\Laravel\\Internal\\';

    /** @var array<string, class-string> $aliases */
    $aliases = [
        $contracts.'ResourceFilesystem' => ResourceFilesystem::class,
        $contracts.'ResourceStorage' => ResourceStorage::class,
        $internal.'FrameworkConfig' => FrameworkConfig::class,
        $internal.'LaravelRuntimeFactory' => LaravelRuntimeFactory::class,
    ];

    foreach ($aliases as $legacy => $target) {
        if (! class_exists($legacy, false) && ! interface_exists($legacy, false)) {
            class_alias($target, $legacy);
        }
    }
})();
