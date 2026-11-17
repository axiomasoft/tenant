<?php

declare(strict_types=1);

use Packman\Extensions\ExtensionInspector;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Tenant\Errors\TenantProblemDefinitions;
use Tenant\Postgres\Database\TenantPostgresExtension;
use Tenant\Postgres\Database\TenantPostgresSchemaSets;
use Tenant\Postgres\Exceptions\Provisioning\TemplateIntegrityException;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Database\SchemaCatalogueSerializer;
use Tenant\Postgres\Internal\Database\TemplateManifest;
use Tenant\Postgres\ProvisioningOwner;
use Tenant\Postgres\RecoveryOperator;

it('declares central platform SchemaSet and inspects TenantPostgresExtension facets', function (): void {
    $provider = new TenantPostgresSchemaSets;
    $sets = $provider->sets();

    expect($sets)->toHaveCount(1)
        ->and($sets[0]->id())->toBe('axiomasoft/tenant-postgres:tenant-postgres')
        ->and($sets[0]->scope)->toBe(SchemaScope::Central)
        ->and($sets[0]->lifecycle)->toBe(SchemaLifecycle::Platform)
        ->and($sets[0]->drivers)->toBe(['pgsql'])
        ->and(is_dir($sets[0]->path))->toBeTrue();

    $migrations = glob($sets[0]->path.'/*.php');
    expect($migrations)->toBeArray()->toHaveCount(6);

    $profile = ExtensionInspector::inspect(new TenantPostgresExtension);

    expect($profile->hasConfiguration)->toBeFalse()
        ->and($profile->configRoot)->toBeNull()
        ->and($profile->hasSchema)->toBeTrue()
        ->and($profile->tablePrefix)->toBe('tenant_pg_')
        ->and($profile->tableNouns)->toContain('clusters', 'templates', 'resources', 'operations', 'lifecycle_operations')
        ->and($profile->schemaSetProviders)->toBe([TenantPostgresSchemaSets::class])
        ->and($profile->problemProviders)->toBe([TenantProblemDefinitions::class]);
});

it('verifies neutral tenant-template-manifest/v1 signatures and rejects legacy prefix', function (): void {
    $serializer = new SchemaCatalogueSerializer;
    $payload = [
        'template_id' => '12121212-1212-4212-8212-121212121212',
        'template_version' => 1,
        'canonical_schema_sha256' => str_repeat('a', 64),
    ];
    $encodedPayload = $serializer->encode($payload);

    $pair = sodium_crypto_sign_keypair();
    $encodeUrl = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $trustedKeys = ['test-key' => $encodeUrl(sodium_crypto_sign_publickey($pair))];
    $secret = sodium_crypto_sign_secretkey($pair);

    $validSig = sodium_crypto_sign_detached("tenant-template-manifest/v1\0".$encodedPayload, $secret);
    $manifest = TemplateManifest::fromConfig([
        'payload' => $payload,
        'signature' => $encodeUrl($validSig),
        'trusted_key_id' => 'test-key',
    ]);
    $manifest->assertTrusted($trustedKeys, $serializer);
    expect($manifest->digest($serializer))->toBe(hash('sha256', $encodedPayload));

    $legacySig = sodium_crypto_sign_detached("legacy-template-manifest/v1\0".$encodedPayload, $secret);
    $legacyManifest = TemplateManifest::fromConfig([
        'payload' => $payload,
        'signature' => $encodeUrl($legacySig),
        'trusted_key_id' => 'test-key',
    ]);
    expect(static fn () => $legacyManifest->assertTrusted($trustedKeys, $serializer))
        ->toThrow(TemplateIntegrityException::class);
});

it('keeps ProvisioningOwner and RecoveryOperator constructors private behind PostgresFactory', function (): void {
    $ownerCtor = new ReflectionClass(ProvisioningOwner::class)->getConstructor();
    $operatorCtor = new ReflectionClass(RecoveryOperator::class)->getConstructor();

    expect($ownerCtor)->not->toBeNull()
        ->and($ownerCtor?->isPrivate())->toBeTrue()
        ->and($operatorCtor)->not->toBeNull()
        ->and($operatorCtor?->isPrivate())->toBeTrue();
});

it('validates DbRow::int() strictly through DatabaseValue::integer()', function (): void {
    expect(DbRow::int((object) ['v' => 42], 'v'))->toBe(42)
        ->and(DbRow::int((object) ['v' => '0'], 'v'))->toBe(0)
        ->and(DbRow::int((object) ['v' => '-17'], 'v'))->toBe(-17);

    foreach (['1.5', '1e3', '007', ' 1', '999999999999999999999999', 1.5, true, null, []] as $invalid) {
        expect(static fn (): int => DbRow::int((object) ['v' => $invalid], 'v'))
            ->toThrow(LogicException::class, 'Database field [v] must be an integer.');
    }
});
