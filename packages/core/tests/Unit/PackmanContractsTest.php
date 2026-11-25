<?php

declare(strict_types=1);

use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Tenant\Attributes\Api as TenantApi;
use Tenant\Attributes\Spi as TenantSpi;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Data\Context\Selection;
use Tenant\Errors\Enums\TenantErrorCode;
use Tenant\Errors\TenantProblemDefinitions;
use Tenant\Exceptions\Context\ScopeRequired;
use Tenant\Exceptions\TenantFailure;
use Tenant\Internal\Runtime\GenerationGuard as LegacyGenerationGuard;
use Tenant\Runtime\GenerationGuard;

it('maps every contract error reason to TenantErrorCode and TenantProblemDefinitions', function (): void {
    $raw = file_get_contents(dirname(__DIR__, 4).'/contracts/errors.v1.json');
    expect($raw)->toBeString();
    $contract = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
    expect($contract['errors'])->toBeArray()->toHaveCount(44);

    $definitions = [];
    foreach ((new TenantProblemDefinitions)->definitions() as $definition) {
        $definitions[$definition->code->value()] = $definition;
    }

    expect($definitions)->toHaveCount(count(TenantErrorCode::cases()));

    foreach ($contract['errors'] as $entry) {
        $reason = $entry['reason'];
        $code = TenantErrorCode::fromReason($reason);

        expect($code->value())->toBe('tenant.'.$reason)
            ->and($definitions)->toHaveKey($code->value());

        $definition = $definitions[$code->value()];
        expect($definition->type)->toBe('urn:packman:problem:tenant.'.$reason)
            ->and($definition->defaultStatus)->toBeGreaterThanOrEqual(400)
            ->and($definition->defaultStatus)->toBeLessThan(600);
    }
});

it('exposes DescribesError and ErrorDescriptor from TenantFailure while preserving reason and causes', function (): void {
    $cause = new RuntimeException('inner');
    $failure = new ScopeRequired([$cause]);

    expect($failure)->toBeInstanceOf(CodedException::class)
        ->and($failure)->toBeInstanceOf(DescribesError::class)
        ->and($failure->reason())->toBe('missing')
        ->and($failure->causes())->toBe([$cause]);

    $descriptor = $failure->errorDescriptor();
    expect($descriptor)->toBeInstanceOf(ErrorDescriptor::class)
        ->and($descriptor->code)->toBe('tenant.missing')
        ->and($descriptor->errorCode)->toBe(TenantErrorCode::Missing)
        ->and($descriptor->category)->toBe(ErrorCategory::Authorization);

    $generic = new TenantFailure('facility_configuration');
    expect($generic->errorDescriptor()->code)->toBe('tenant.facility_configuration')
        ->and($generic->errorDescriptor()->category)->toBe(ErrorCategory::Internal);
});

it('instantiates deprecated Tenant attributes via reflection and preserves legacy aliases', function (): void {
    $bareSpi = new #[TenantSpi] class {};
    $versionedSpi = new #[TenantSpi('1.0.0')] class {};
    $bareApi = new #[TenantApi] class {};
    $versionedApi = new #[TenantApi('1.0.0')] class {};

    $bareSpiInstance = new ReflectionClass($bareSpi)->getAttributes(TenantSpi::class)[0]->newInstance();
    $versionedSpiInstance = new ReflectionClass($versionedSpi)->getAttributes(TenantSpi::class)[0]->newInstance();
    $bareApiInstance = new ReflectionClass($bareApi)->getAttributes(TenantApi::class)[0]->newInstance();
    $versionedApiInstance = new ReflectionClass($versionedApi)->getAttributes(TenantApi::class)[0]->newInstance();

    expect($bareSpiInstance)->toBeInstanceOf(TenantSpi::class)
        ->and($bareSpiInstance->since)->toBeNull()
        ->and($versionedSpiInstance)->toBeInstanceOf(TenantSpi::class)
        ->and($versionedSpiInstance->since)->toBe('1.0.0')
        ->and($bareApiInstance)->toBeInstanceOf(TenantApi::class)
        ->and($bareApiInstance->since)->toBeNull()
        ->and($versionedApiInstance)->toBeInstanceOf(TenantApi::class)
        ->and($versionedApiInstance->since)->toBe('1.0.0')
        ->and(class_exists(LegacyGenerationGuard::class))->toBeTrue()
        ->and(new ReflectionClass(LegacyGenerationGuard::class)->getName())->toBe(GenerationGuard::class)
        ->and(new ReflectionClass('Tenant\\Contracts\\'.'ContextResolver')->getName())->toBe(ContextResolver::class)
        ->and(new ReflectionClass('Tenant\\Data\\'.'Selection')->getName())->toBe(Selection::class)
        ->and(new ReflectionClass('Tenant\\Exceptions\\'.'ScopeRequired')->getName())->toBe(ScopeRequired::class);

    $leases = 0;
    $guard = new GenerationGuard(
        static function (): void {},
        static function (int $delta) use (&$leases): void {
            $leases += $delta;
        },
    );

    $release = $guard->acquire();
    expect($leases)->toBe(1);
    $release();
    $release();
    expect($leases)->toBe(0);
});
