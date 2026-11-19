<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\PackmanServiceProvider;
use Packman\Testkit\Concerns\Assertions\AssertsRegisteredErrors;
use Tenant\Contracts\Context\ContextCodec;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Data\Verification\VerifiedBinding;
use Tenant\Errors\Enums\TenantErrorCode;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Configuration\TenantOptions;
use Tenant\Laravel\Diagnostics\TenantDoctorCheck;
use Tenant\Laravel\Queue\QueueRegistry;
use Tenant\Laravel\TenantServiceProvider;
use Tenant\Laravel\Tests\Feature\TenantLaravelTestCase;
use Tenant\Runtime\SafeTenantRuntime;
use Tenant\Tenant;

uses(TenantLaravelTestCase::class, AssertsRegisteredErrors::class);

it('wires TenantOptions, runtime singletons, ProblemRegistry, ConfigSectionRegistration, and doctor check through TenantServiceProvider', function (): void {
    $options = $this->app->make(TenantOptions::class);
    $runtime = $this->app->make(TenantRuntime::class);
    $safe = $this->app->make(SafeTenantRuntime::class);
    $facade = $this->app->make(Tenant::class);
    $codec = $this->app->make(ContextCodec::class);
    $doctor = $this->app->make(TenantDoctorCheck::class);
    $registry = $this->app->make(ProblemRegistry::class);

    expect($options)->toBeInstanceOf(TenantOptions::class)
        ->and($this->app->make(TenantOptions::class))->toBe($options)
        ->and($options->centralConnection)->toBe('central')
        ->and($options->facilities->session)->toBeFalse()
        ->and($runtime)->toBe($safe)
        ->and($facade)->toBeInstanceOf(Tenant::class)
        ->and($codec)->toBeInstanceOf(ContextCodec::class)
        ->and($doctor)->toBeInstanceOf(TenantDoctorCheck::class);

    $this->assertAllErrorCodesRegistered($registry, TenantErrorCode::class);
    $this->assertCodedExceptionRegistered($registry, new TenantFailure('facility_configuration'));
    expect($registry->get(TenantErrorCode::FacilityConfiguration)->type)
        ->toBe('urn:packman:problem:tenant.facility_configuration');

    // ConfigSectionRegistration owns the 'tenant' root section and enforces closed schema.
    $tenantConfig = $this->app->make(Repository::class)->get('tenant');
    expect($tenantConfig)->toBeArray();
    ConfigSectionRegistration::assertKnownKeys($this->app, ['tenant' => $tenantConfig]);

    expect(fn () => ConfigSectionRegistration::assertKnownKeys($this->app, ['tenant' => ['central_conection' => 'central']]))
        ->toThrow(InvalidConfiguration::class, 'did you mean "central_connection"?')
        ->and(fn () => ConfigSectionRegistration::assertKnownKeys($this->app, ['unknown_section' => []]))
        ->toThrow(InvalidConfiguration::class, 'unknown configuration section');

    // Mutating live tenant.* config after boot does not diverge TenantDoctorCheck from the compiled TenantOptions snapshot.
    $this->app->make(Repository::class)->set('tenant.central_connection', 'mutated_after_boot');
    $doctorFindings = $doctor->run(new DoctorContext);
    expect($doctorFindings)->toHaveCount(1)
        ->and($doctorFindings[0]->code)->toBe('tenant.doctor.healthy')
        ->and($doctorFindings[0]->evidence['central_connection'] ?? null)->toBe('central');

    $state = $facade->run(
        Selection::central(new ProfileRef('laravel-pg-database', 1)),
        new Purpose('tenant:test'),
        static fn ($current) => $current,
    );
    expect($state->kind)->toBe('central');
});

it('runs TenantDoctorCheck via packman:doctor command', function (): void {
    $exit = Artisan::call('packman:doctor', ['--prefix' => ['tenant'], '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload['checks'][0]['key'] ?? null)->toBe('tenant.runtime.configuration')
        ->and($payload['checks'][0]['status'] ?? null)->toBe('ok');
});

it('fails closed during normal boot on invalid central_connection or non-array root tenant config while keeping diagnostic commands resilient', function (): void {
    $bootApp = static function (array $argv, array $tenantOverrides, bool $badQueue = false): Application {
        $_SERVER['argv'] = $argv;

        $app = new Application(dirname(__DIR__, 4));
        $app->instance('env', 'testing');
        $app->instance('config', new ConfigRepository([
            'app' => ['name' => 'Test', 'env' => 'testing', 'key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'cipher' => 'AES-256-CBC'],
            'database' => ['default' => 'central', 'connections' => ['central' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
            'cache' => ['default' => 'tenant', 'stores' => ['tenant' => ['driver' => 'array'], 'central' => ['driver' => 'array']]],
            'session' => ['driver' => null],
            'queue' => ['connections' => ['database' => ['driver' => 'database', 'connection' => 'central', 'after_commit' => $badQueue], 'sync' => ['after_commit' => false]], 'failed' => ['database' => 'central']],
            'tenant' => $tenantOverrides['root'] ?? array_replace_recursive([
                'central_connection' => 'central',
                'cache_stores' => ['tenant'],
                'central_cache_stores' => ['central'],
                'facilities' => ['database' => true, 'cache' => true, 'session' => false, 'storage' => true],
                'storage_driver' => 'local',
                'shared' => ['session' => ['owner' => 'host:session', 'purpose' => 'host:test']],
            ], $tenantOverrides),
        ]));

        foreach ([EventServiceProvider::class, DatabaseServiceProvider::class, FilesystemServiceProvider::class, SessionServiceProvider::class, QueueServiceProvider::class, PackmanServiceProvider::class] as $provider) {
            $app->register($provider);
        }

        $app->singleton(ContextResolver::class, static fn (): ContextResolver => new class implements ContextResolver
        {
            public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
            {
                return new ResolvedIsolation(ContextState::central(), new FacetBindings([]));
            }
        });
        $app->singleton(BindingVerifier::class, static fn (): BindingVerifier => new class implements BindingVerifier
        {
            public function verify(BindingVerificationInput $input): VerifiedBinding
            {
                return new VerifiedBinding(new BindingExpectation('res:1', 'inc:1', 'rev:1'), 'test:owner', 'gen:1');
            }
        });

        if ($badQueue) {
            $app->singleton(QueueRegistry::class, static fn (): QueueRegistry => new QueueRegistry(new ProfileRef('laravel-pg-database', 1)));
        }

        $app->register(TenantServiceProvider::class);
        $app->boot();

        return $app;
    };

    $previousArgv = $_SERVER['argv'] ?? null;

    try {
        expect(static fn (): Application => $bootApp(['artisan', 'queue:work'], ['central_connection' => 'missing_conn']))
            ->toThrow(TenantFailure::class, 'facility_configuration');

        expect(static fn (): Application => $bootApp(['artisan', 'queue:work'], ['root' => 'not-an-array']))
            ->toThrow(TenantFailure::class, 'facility_configuration');

        foreach ([
            ['artisan', 'package:discover', '--ansi'],
            ['artisan', 'packman:doctor', '--json'],
            ['artisan', 'packman:config:validate'],
            ['artisan', 'list'],
        ] as $diagnosticArgv) {
            $app = $bootApp($diagnosticArgv, ['central_connection' => 'missing_conn'], badQueue: true);
            $findings = $app->make(TenantDoctorCheck::class)->run(new DoctorContext);
            $codes = array_map(static fn (DoctorFinding $f): string => $f->code, $findings);

            expect($codes)->toContain('tenant.doctor.invalid_central_connection')
                ->and($codes)->toContain('tenant.doctor.queue_after_commit');
        }
    } finally {
        $_SERVER['argv'] = $previousArgv;
    }
});
