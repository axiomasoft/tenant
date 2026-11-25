<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Packman\Doctor\Data\DoctorContext;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Configuration\TenantOptions;
use Tenant\Laravel\Diagnostics\TenantDoctorCheck;
use Tenant\Laravel\Internal\Facilities\FacilitiesValidator;

it('leaves explicitly shared cache and session drivers to their declared host owners', function (): void {
    $options = TenantOptions::fromArray([
        'facilities' => ['cache' => false, 'session' => false],
        'shared' => [
            'cache' => ['owner' => 'host:cache', 'purpose' => 'host:metadata'],
            'session' => ['owner' => 'host:session', 'purpose' => 'host:authentication'],
        ],
    ]);
    $config = new Repository([
        'database' => ['connections' => ['central' => ['driver' => 'sqlite']]],
        'cache' => ['default' => 'shared', 'stores' => ['shared' => ['driver' => 'file']]],
        'session' => ['driver' => 'database'],
        'queue' => ['connections' => ['database' => ['after_commit' => false]]],
    ]);

    new FacilitiesValidator($config, $options)->assertValid();

    expect(new TenantDoctorCheck($config, $options, queueManaged: false)->run(new DoctorContext)[0]->code)
        ->toBe('tenant.doctor.healthy');
});

it('accepts scoped runtime facilities without any configured physical database', function (): void {
    $options = TenantOptions::fromArray([
        'runtime_profile' => 'laravel-scoped',
        'facilities' => ['database' => false],
        'shared' => ['database' => ['owner' => 'host:eloquent', 'purpose' => 'host:row-isolation']],
    ]);
    $config = new Repository([
        'cache' => ['default' => 'tenant', 'stores' => ['tenant' => ['driver' => 'array']]],
        'session' => ['driver' => null],
    ]);

    new FacilitiesValidator($config, $options)->assertValid();

    expect(new TenantDoctorCheck($config, $options, queueManaged: false)->run(new DoctorContext)[0]->code)
        ->toBe('tenant.doctor.healthy');
});

it('still refuses unsupported drivers when Tenant owns the facility', function (string $facility): void {
    $options = TenantOptions::fromArray([]);
    $config = new Repository([
        'database' => ['connections' => ['central' => ['driver' => 'sqlite']]],
        'cache' => ['default' => 'tenant', 'stores' => ['tenant' => ['driver' => 'array']]],
        'session' => ['driver' => null],
    ]);
    $config->set($facility === 'cache' ? 'cache.stores.tenant.driver' : 'session.driver', 'database');

    expect(fn () => new FacilitiesValidator($config, $options)->assertValid())
        ->toThrow(TenantFailure::class, 'facility_configuration');
})->with(['cache', 'session']);

it('requires an explicit owner and purpose for every disabled facility', function (string $facility): void {
    $options = TenantOptions::fromArray(['facilities' => [$facility => false]]);
    $config = new Repository(['database' => ['connections' => ['central' => ['driver' => 'sqlite']]]]);

    expect(fn () => new FacilitiesValidator($config, $options)->assertValid())
        ->toThrow(TenantFailure::class, 'facility_configuration');
})->with(['database', 'cache', 'session', 'storage']);

it('never lets isolated Redis sessions rewrite a shared cache store', function (string $sessionStore, string $defaultStore): void {
    $options = TenantOptions::fromArray([
        'facilities' => ['cache' => false],
        'shared' => ['cache' => ['owner' => 'host:cache', 'purpose' => 'host:metadata']],
    ]);
    $config = new Repository([
        'database' => ['connections' => ['central' => ['driver' => 'sqlite']]],
        'cache' => ['default' => $defaultStore, 'stores' => [$sessionStore => ['driver' => 'redis', 'connection' => 'shared']]],
        'session' => ['driver' => 'redis', 'store' => $sessionStore, 'connection' => 'sessions'],
    ]);

    expect(fn () => new FacilitiesValidator($config, $options)->assertValid())
        ->toThrow(TenantFailure::class, 'facility_configuration');
})->with([
    'declared cache store' => ['tenant', 'shared'],
    'shared default store' => ['shared', 'shared'],
    'central control store' => ['central', 'shared'],
]);

it('allows isolated Redis sessions to use their own store alongside a shared cache', function (): void {
    $options = TenantOptions::fromArray([
        'facilities' => ['cache' => false],
        'shared' => ['cache' => ['owner' => 'host:cache', 'purpose' => 'host:metadata']],
    ]);
    $config = new Repository([
        'database' => ['connections' => ['central' => ['driver' => 'sqlite']]],
        'cache' => ['default' => 'shared', 'stores' => [
            'shared' => ['driver' => 'file'],
            'sessions' => ['driver' => 'redis', 'connection' => 'sessions'],
        ]],
        'session' => ['driver' => 'redis', 'store' => 'sessions', 'connection' => 'sessions'],
    ]);

    new FacilitiesValidator($config, $options)->assertValid();
    expect($config->get('cache.stores.shared.driver'))->toBe('file');
});
