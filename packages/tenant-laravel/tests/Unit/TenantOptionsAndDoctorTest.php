<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Enums\CheckStatus;
use Tenant\Laravel\Configuration\TenantFacilitiesOptions;
use Tenant\Laravel\Configuration\TenantOptions;
use Tenant\Laravel\Diagnostics\TenantDoctorCheck;

it('compiles immutable TenantOptions and TenantFacilitiesOptions from config arrays', function (): void {
    $defaults = TenantOptions::fromArray([], 'tenant');

    expect($defaults->centralConnection)->toBe('central')
        ->and($defaults->runtimeProfile)->toBe('laravel-pg-database')
        ->and($defaults->cacheStores)->toBe(['tenant'])
        ->and($defaults->centralCacheStores)->toBe(['central'])
        ->and($defaults->storageDriver)->toBe('local')
        ->and($defaults->shared)->toBe([])
        ->and($defaults->facilities->database)->toBeTrue()
        ->and($defaults->facilities->cache)->toBeTrue()
        ->and($defaults->facilities->session)->toBeTrue()
        ->and($defaults->facilities->storage)->toBeTrue();

    $custom = TenantOptions::fromArray([
        'central_connection' => 'primary',
        'cache_stores' => ['tenant', 'tenant_secondary'],
        'central_cache_stores' => ['central'],
        'facilities' => ['database' => true, 'cache' => false, 'session' => true, 'storage' => true],
        'storage_driver' => 'local',
        'shared' => [
            'cache' => ['owner' => 'host:cache', 'purpose' => 'host:shared-cache'],
        ],
    ], 'tenant');

    expect($custom->centralConnection)->toBe('primary')
        ->and($custom->cacheStores)->toBe(['tenant', 'tenant_secondary'])
        ->and($custom->facilities->cache)->toBeFalse()
        ->and($custom->shared)->toBe(['cache' => ['owner' => 'host:cache', 'purpose' => 'host:shared-cache']]);
});

it('rejects invalid TenantOptions and TenantFacilitiesOptions values', function (): void {
    expect(static fn (): TenantOptions => TenantOptions::fromArray(['central_connection' => 'tenant'], 'tenant'))
        ->toThrow(InvalidConfiguration::class)
        ->and(static fn (): TenantOptions => TenantOptions::fromArray(['cache_stores' => 'not-a-list'], 'tenant'))
        ->toThrow(InvalidConfiguration::class)
        ->and(static fn (): TenantFacilitiesOptions => TenantFacilitiesOptions::fromArray(['database' => 'yes'], 'tenant.facilities'))
        ->toThrow(InvalidConfiguration::class)
        ->and(static fn (): TenantOptions => TenantOptions::fromArray(['shared' => ['cache' => ['owner' => '']]], 'tenant'))
        ->toThrow(InvalidConfiguration::class);
});

it('rejects explicit null options instead of silently substituting defaults', function (string $option): void {
    expect(fn (): TenantOptions => TenantOptions::fromArray([$option => null]))
        ->toThrow(InvalidConfiguration::class);
})->with(['central_connection', 'cache_stores', 'central_cache_stores', 'facilities', 'storage_driver', 'shared', 'runtime_profile']);

it('requires an explicit host database owner before enabling the scoped runtime profile', function (): void {
    expect(fn (): TenantOptions => TenantOptions::fromArray(['runtime_profile' => 'unknown']))->toThrow(InvalidConfiguration::class, 'runtime_profile')
        ->and(fn (): TenantOptions => TenantOptions::fromArray(['runtime_profile' => 'laravel-scoped']))->toThrow(InvalidConfiguration::class, 'facilities.database')
        ->and(fn (): TenantOptions => TenantOptions::fromArray(['runtime_profile' => 'laravel-scoped', 'facilities' => ['database' => false]]))->toThrow(InvalidConfiguration::class, 'shared.database');

    $scoped = TenantOptions::fromArray([
        'runtime_profile' => 'laravel-scoped',
        'facilities' => ['database' => false],
        'shared' => ['database' => ['owner' => 'host:eloquent', 'purpose' => 'host:row-isolation']],
    ]);
    expect($scoped->runtimeProfile)->toBe('laravel-scoped')
        ->and($scoped->facilities->database)->toBeFalse();
});

it('rejects explicit null facility flags', function (string $facility): void {
    expect(fn (): TenantFacilitiesOptions => TenantFacilitiesOptions::fromArray([$facility => null]))
        ->toThrow(InvalidConfiguration::class);
})->with(['database', 'cache', 'session', 'storage']);

it('rejects duplicate cache store declarations before guarded driver registration', function (string $option): void {
    expect(fn (): TenantOptions => TenantOptions::fromArray([$option => ['cache', 'cache']]))
        ->toThrow(InvalidConfiguration::class, 'duplicate');
})->with(['cache_stores', 'central_cache_stores']);

it('rejects misspelled shared facility ownership declarations', function (): void {
    expect(fn (): TenantOptions => TenantOptions::fromArray([
        'shared' => ['cahce' => ['owner' => 'host:cache', 'purpose' => 'host:metadata']],
    ]))->toThrow(InvalidConfiguration::class, 'did you mean "cache"?');
});

it('rejects unknown and typo keys in closed-schema TenantOptions and TenantFacilitiesOptions', function (): void {
    expect(static fn (): TenantOptions => TenantOptions::fromArray(['central_conection' => 'central'], 'tenant'))
        ->toThrow(InvalidConfiguration::class, 'did you mean "central_connection"?')
        ->and(static fn (): TenantFacilitiesOptions => TenantFacilitiesOptions::fromArray(['databse' => true], 'tenant.facilities'))
        ->toThrow(InvalidConfiguration::class, 'did you mean "database"?')
        ->and(static fn (): TenantOptions => TenantOptions::fromArray([
            'shared' => [
                'cache' => ['owner' => 'host:cache', 'purpose' => 'host:shared', 'extra' => 'bad'],
            ],
        ], 'tenant'))
        ->toThrow(InvalidConfiguration::class, 'tenant.shared.cache.extra: unknown key');
});

it('uses an injected authoritative TenantOptions snapshot in TenantDoctorCheck without re-reading tenant config', function (): void {
    $snapshot = TenantOptions::fromArray(['central_connection' => 'primary_central'], 'tenant');
    $repo = new Repository([
        'tenant' => ['central_connection' => 'should_be_ignored'],
        'database' => ['connections' => ['primary_central' => ['driver' => 'sqlite']]],
        'cache' => ['stores' => ['tenant' => ['driver' => 'array'], 'central' => ['driver' => 'array']]],
        'queue' => ['connections' => ['database' => ['driver' => 'database', 'after_commit' => false]]],
    ]);

    $findings = new TenantDoctorCheck($repo, $snapshot)->run(new DoctorContext);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('tenant.doctor.healthy')
        ->and($findings[0]->evidence['central_connection'] ?? null)->toBe('primary_central');
});

it('evaluates TenantDoctorCheck findings across valid, post-boot, and invalid configurations', function (): void {
    $healthyRepo = new Repository([
        'tenant' => [
            'central_connection' => 'central',
            'cache_stores' => ['tenant'],
            'central_cache_stores' => ['central'],
            'facilities' => ['database' => true, 'cache' => true, 'session' => true, 'storage' => true],
        ],
        'database' => [
            'connections' => [
                'central' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
        'cache' => [
            'stores' => [
                'tenant' => ['driver' => 'array'],
                'central' => ['driver' => 'array'],
            ],
        ],
        'queue' => [
            'connections' => [
                'database' => ['driver' => 'database', 'after_commit' => false],
            ],
        ],
    ]);

    $healthyCheck = new TenantDoctorCheck($healthyRepo);
    $healthy = $healthyCheck->run(new DoctorContext);
    expect($healthyCheck->key())->toBe('tenant.runtime.configuration')
        ->and($healthy)->toHaveCount(1)
        ->and($healthy[0]->status)->toBe(CheckStatus::Ok)
        ->and($healthy[0]->code)->toBe('tenant.doctor.healthy');

    // Post-boot rewritten tenant-guarded cache driver remains healthy.
    $healthyRepo->set('cache.stores.tenant', ['driver' => 'tenant-guarded', 'tenant_driver' => 'array']);
    expect($healthyCheck->run(new DoctorContext)[0]->status)->toBe(CheckStatus::Ok);

    // Invalid connection, after_commit=true, and colliding cache store report errors.
    $brokenRepo = new Repository([
        'tenant' => [
            'central_connection' => 'missing_conn',
            'cache_stores' => ['central', 'unknown_store'],
            'central_cache_stores' => ['central'],
        ],
        'database' => ['connections' => []],
        'cache' => ['stores' => ['central' => ['driver' => 'array']]],
        'queue' => ['connections' => ['database' => ['after_commit' => true]]],
    ]);

    $findings = new TenantDoctorCheck($brokenRepo)->run(new DoctorContext);
    $codes = array_map(static fn (DoctorFinding $f): string => $f->code, $findings);

    expect($codes)->toContain('tenant.doctor.invalid_central_connection')
        ->and($codes)->toContain('tenant.doctor.queue_after_commit')
        ->and($codes)->toContain('tenant.doctor.cache_store_collision')
        ->and($codes)->toContain('tenant.doctor.missing_cache_store');
});
