<?php

declare(strict_types=1);

use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Testkit\Concerns\Assertions\AssertsRegisteredErrors;
use Tenant\Errors\Enums\TenantErrorCode;
use Tenant\Exceptions\TenantFailure;
use Tenant\Postgres\Database\TenantPostgresExtension;
use Tenant\Postgres\Database\TenantPostgresSchemaSets;
use Tenant\Postgres\Tests\Feature\TenantPostgresTestCase;

uses(TenantPostgresTestCase::class, AssertsRegisteredErrors::class);

it('registers TenantPostgresSchemaSets, SchemaOwnership nouns, ProblemRegistry definitions, and TenantPostgresExtension', function (): void {
    $sets = $this->app->make(SchemaSets::class);
    $set = $sets->get('axiomasoft/tenant-postgres:tenant-postgres');
    $ownership = $this->app->make(SchemaOwnership::class);
    $registry = $this->app->make(ProblemRegistry::class);

    expect($set->package)->toBe('axiomasoft/tenant-postgres')
        ->and($set->scope)->toBe(SchemaScope::Central)
        ->and($set->lifecycle)->toBe(SchemaLifecycle::Platform)
        ->and($this->app->make(TenantPostgresSchemaSets::class))->toBeInstanceOf(TenantPostgresSchemaSets::class)
        ->and($this->app->make(TenantPostgresExtension::class))->toBeInstanceOf(TenantPostgresExtension::class)
        ->and($ownership->owners()['axiomasoft/tenant-postgres'] ?? [])->toBe((new TenantPostgresExtension)->tableNouns())
        ->and($ownership->ownerOf('clusters'))->toBe('axiomasoft/tenant-postgres')
        ->and($ownership->ownerOf('lifecycle_operations'))->toBe('axiomasoft/tenant-postgres');

    $this->assertAllErrorCodesRegistered($registry, TenantErrorCode::class);
    $this->assertCodedExceptionRegistered($registry, new TenantFailure('facility_configuration'));
    expect($registry->get(TenantErrorCode::FacilityConfiguration)->type)
        ->toBe('urn:packman:problem:tenant.facility_configuration');
});
