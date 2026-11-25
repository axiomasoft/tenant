<?php

declare(strict_types=1);

namespace Tenant\Laravel\Registrars;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Errors\Laravel\ProblemDefinitions;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Tenant\Contracts\Context\ContextCodec;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Errors\TenantProblemDefinitions;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Codec\JsonContextCodec;
use Tenant\Laravel\Configuration\TenantOptions;
use Tenant\Laravel\Diagnostics\TenantDoctorCheck;
use Tenant\Laravel\Internal\Boundary\ProcessBoundary;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;
use Tenant\Laravel\Internal\Facilities\SessionGeneration;
use Tenant\Laravel\Internal\Runtime\LaravelRuntimeFactory;
use Tenant\Laravel\Laravel\ConsoleCommandProbe;
use Tenant\Laravel\Laravel\TenantLaravelExtension;
use Tenant\Laravel\Queue\QueueRegistry;
use Tenant\Runtime\SafeTenantRuntime;
use Tenant\Tenant;

/**
 * Registers the core Tenant runtime, compiled options, codec, and doctor diagnostic check.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class TenantRuntimeRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        if ($app->bound(TenantRuntime::class) || $app->bound(SafeTenantRuntime::class)) {
            throw new TenantFailure('exclusive_slot_conflict');
        }

        $app->singleton(TenantOptions::class, static function (Container $app): TenantOptions {
            $raw = FrameworkConfig::service($app, 'config', Repository::class)->get('tenant', []);

            if (! is_array($raw)) {
                throw new TenantFailure('facility_configuration', [InvalidConfiguration::forMessage('tenant: configuration must be an array.')]);
            }

            try {
                return TenantOptions::fromArray($raw, 'tenant');
            } catch (InvalidConfiguration $exception) {
                throw new TenantFailure('facility_configuration', [$exception]);
            }
        });

        ConfigSectionRegistration::own($app, 'tenant', static function (array $section): void {
            TenantOptions::fromArray($section, 'tenant');
        });

        $tenantManifest = ExtensionFacetRegistrar::discoverComposerManifest(TenantProblemDefinitions::class)
            ?? dirname(__DIR__, 3).'/tenant/composer.json';
        ProblemDefinitions::register($app, $tenantManifest, TenantProblemDefinitions::class);

        $app->singleton(SessionGeneration::class, static fn (Container $app): SessionGeneration => new SessionGeneration(
            static fn (): SafeTenantRuntime => FrameworkConfig::service($app, SafeTenantRuntime::class, SafeTenantRuntime::class),
        ));

        $app->singleton(SafeTenantRuntime::class, static fn (Container $app): SafeTenantRuntime => LaravelRuntimeFactory::build($app));

        $app->singleton(TenantRuntime::class, static function (Container $app): TenantRuntime {
            $owner = FrameworkConfig::service($app, SafeTenantRuntime::class, SafeTenantRuntime::class);
            $decorate = $app->bound('tenant.decorate') ? $app->make('tenant.decorate') : null;

            if ($decorate !== null && ! $decorate instanceof Closure) {
                throw new TenantFailure('replacement_incompatible');
            }

            $runtime = $decorate !== null ? $decorate($owner) : $owner;

            if (! $runtime instanceof TenantRuntime) {
                throw new TenantFailure('replacement_incompatible');
            }

            return $runtime;
        });

        $app->singleton(Tenant::class, static fn (Container $app): Tenant => new Tenant(
            FrameworkConfig::service($app, TenantRuntime::class, TenantRuntime::class),
        ));

        $app->singleton(ProcessBoundary::class, static fn (Container $app): ProcessBoundary => new ProcessBoundary(
            FrameworkConfig::service($app, TenantRuntime::class, TenantRuntime::class),
        ));

        $app->singleton(ContextCodec::class, static fn (): JsonContextCodec => new JsonContextCodec);

        $app->singleton(TenantDoctorCheck::class, static fn (Container $app): TenantDoctorCheck => new TenantDoctorCheck(
            FrameworkConfig::service($app, 'config', Repository::class),
            $app->resolved(TenantOptions::class)
                ? FrameworkConfig::service($app, TenantOptions::class, TenantOptions::class)
                : null,
            static fn (): TenantOptions => FrameworkConfig::service($app, TenantOptions::class, TenantOptions::class),
            queueManaged: $app->bound(QueueRegistry::class),
        ));

        $app->singletonIf(TenantLaravelExtension::class);
        ExtensionFacetRegistrar::register($app, TenantLaravelExtension::class, dirname(__DIR__, 2).'/composer.json');
    }

    #[Override]
    public function boot(Application $app): void
    {
        if (ConsoleCommandProbe::isDiagnosticCommand($app)) {
            return;
        }

        // Validate before altering default framework access. Explicit central names remain fixed.
        $runtime = FrameworkConfig::service($app, TenantRuntime::class, TenantRuntime::class);
        $owner = FrameworkConfig::service($app, SafeTenantRuntime::class, SafeTenantRuntime::class);

        $this->protectBinding($app, SafeTenantRuntime::class, $owner);
        $this->protectBinding($app, TenantRuntime::class, $runtime);
    }

    private function protectBinding(Application $app, string $name, object $owner): void
    {
        if ($app instanceof Container) {
            $this->watchRebind($app, $name, $owner);
        }
    }

    private function watchRebind(Container $container, string $name, object $owner): void
    {
        $container->rebinding($name, static function (Container $current, mixed $replacement) use ($name, $owner): void {
            if ($replacement === $owner) {
                return;
            }

            $current->instance($name, $owner);

            throw new TenantFailure('composition_frozen');
        });
    }
}
