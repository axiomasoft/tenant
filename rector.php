<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\SimplifyBoolIdenticalTrueRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;

return static function (RectorConfig $rectorConfig): void {
    $srcPaths = array_values(array_filter(glob(__DIR__.'/packages/*/src') ?: [], 'is_dir'));
    $migrationPaths = array_values(array_filter(glob(__DIR__.'/packages/*/database/migrations') ?: [], 'is_dir'));
    $testPaths = array_values(array_filter(glob(__DIR__.'/packages/*/tests') ?: [], 'is_dir'));

    $rectorConfig->disableParallel();
    $rectorConfig->paths([...$srcPaths, ...$migrationPaths, ...$testPaths]);
    $rectorConfig->importNames(importDocBlockNames: false);
    $rectorConfig->removeUnusedImports();

    PackmanRectorSet::configure($rectorConfig, [
        __DIR__.'/packages/tenant-laravel/tests/Boundary',
        __DIR__.'/packages/tenant-laravel/tests/Codec',
        __DIR__.'/packages/tenant-laravel/tests/Compatibility',
        __DIR__.'/packages/tenant-laravel/tests/Facilities',
        __DIR__.'/packages/tenant-laravel/tests/Http',
        __DIR__.'/packages/tenant-laravel/tests/Queue',
        __DIR__.'/packages/tenant-laravel/tests/TestCase.php',
        ReadOnlyClassRector::class,
        ReadOnlyPropertyRector::class,
        ReadOnlyAnonymousClassRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        ExplicitNullableObjectInstanceRector::class,
        RemoveEmptyClassMethodRector::class,
        RemoveUnusedPublicMethodParameterRector::class,
        NarrowObjectReturnTypeRector::class,
        ThrowWithPreviousExceptionRector::class,
        SimplifyBoolIdenticalTrueRector::class,
    ]);
};
