<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Runtime;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Database\DatabaseManager;
use Illuminate\Session\SessionManager;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;
use Tenant\Composition\CompositionBuilder;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Runtime\DiagnosticSink;
use Tenant\Contracts\Runtime\Facility;
use Tenant\Contracts\Runtime\ObservationSink;
use Tenant\Contracts\Runtime\ParticipantFactory;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Composition\ParticipantDeclaration;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\Selection;
use Tenant\Exceptions\Context\ScopeRequired;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Configuration\TenantOptions;
use Tenant\Laravel\Data\ResourceDescriptor;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;
use Tenant\Laravel\Internal\Facilities\CacheFacility;
use Tenant\Laravel\Internal\Facilities\DatabaseFacility;
use Tenant\Laravel\Internal\Facilities\FacilitiesValidator;
use Tenant\Laravel\Internal\Facilities\ScopeGenerationFacility;
use Tenant\Laravel\Internal\Facilities\SessionFacility;
use Tenant\Laravel\Internal\Facilities\SessionGeneration;
use Tenant\Runtime\SafeTenantRuntime;

/** @internal Single owner construction; facilities are eagerly validated and acyclic. */
#[Internal]
final class LaravelRuntimeFactory
{
    public static function build(Container $app): SafeTenantRuntime
    {
        $configRepo = FrameworkConfig::service($app, 'config', Repository::class);
        $options = FrameworkConfig::service($app, TenantOptions::class, TenantOptions::class);
        new FacilitiesValidator($configRepo, $options)->assertValid();

        $config = new FrameworkConfig(static fn (): Repository => FrameworkConfig::service($app, 'config', Repository::class));
        $verifier = FrameworkConfig::service($app, BindingVerifier::class, BindingVerifier::class);
        $profiles = ['laravel-pg-database' => ['version' => 1, 'modes' => ['tenant', 'central'], 'requiredCapabilities' => ['tenant:database', 'tenant:binding-verification', 'tenant:scope-generation'], 'bindingCapability' => 'tenant:binding-verification']];
        $builder = new CompositionBuilder($profiles, [['id' => 'host:verifier', 'slot' => 'tenant:binding/default', 'spiVersion' => 1, 'service' => $verifier, 'capabilities' => ['tenant:binding-verification']], ...self::services($app)]);
        $facilities = [
            'database' => [fn (): DatabaseFacility => new DatabaseFacility(FrameworkConfig::service($app, 'db', DatabaseManager::class), $config, $options->centralConnection), ['tenant:database']],
            'cache' => [fn (): CacheFacility => new CacheFacility(FrameworkConfig::service($app, 'cache', CacheManager::class), $config, $options->cacheStores), ['tenant:cache']],
            'session' => [fn (): SessionFacility => new SessionFacility(FrameworkConfig::service($app, 'session', SessionManager::class), FrameworkConfig::service($app, 'cache', CacheManager::class), $app, $config, FrameworkConfig::service($app, SessionGeneration::class, SessionGeneration::class)), ['tenant:session']],
            'scope' => [fn (): ScopeGenerationFacility => new ScopeGenerationFacility($app), ['tenant:scope-generation']],
        ];
        foreach ($facilities as $name => [$create, $capabilities]) {
            if (! $options->facilities->enabled($name)) {
                continue;
            }
            $factory = new class($create) implements ParticipantFactory
            {
                /** @param Closure(): mixed $create */
                public function __construct(private Closure $create) {}

                public function create(): Facility
                {
                    $facility = ($this->create)();
                    if (! $facility instanceof Facility) {
                        throw new LogicException;
                    }

                    return $facility;
                }
            };
            $builder->register(new ParticipantDeclaration('tenant:'.$name, 1, 'resource', array_map(fn (string $id): CapabilityId => new CapabilityId($id), $capabilities), [], ['tenant:'.$name.'/default'], [], $name === 'database' ? [] : ['tenant:database'], null, $factory));
        }
        if ($app->bound('tenant.compose')) {
            $compose = $app->make('tenant.compose');
            if (! $compose instanceof Closure) {
                throw new InvalidArgumentException('Invalid composition hook');
            }
            $compose($builder, $app);
        }
        $composition = $builder->compile();
        $app->instance('tenant.composition', $composition);
        $observers = $app->bound('tenant.observers') ? $app->make('tenant.observers') : [];
        if (! is_array($observers) || ! array_is_list($observers)) {
            throw new InvalidArgumentException('Invalid observers');
        }
        foreach ($observers as $observer) {
            if (! $observer instanceof ObservationSink) {
                throw new InvalidArgumentException('Invalid observer');
            }
        }
        $diagnostics = $app->bound('tenant.diagnostics') ? FrameworkConfig::service($app, 'tenant.diagnostics', DiagnosticSink::class) : null;

        return new SafeTenantRuntime(FrameworkConfig::service($app, ContextResolver::class, ContextResolver::class), $composition, $observers, $diagnostics);
    }

    public static function descriptor(ContainerContract $app): ResourceDescriptor
    {
        $context = FrameworkConfig::service($app, SafeTenantRuntime::class, SafeTenantRuntime::class)->current()->context ?? throw new ScopeRequired;
        $selection = Selection::tenant($context->identity, $context->profile, $context->extensions, $context->binding);
        $facet = FrameworkConfig::service($app, ContextResolver::class, ContextResolver::class)->resolve($selection, new Purpose('tenant:facilities'))->facets->require(new CapabilityId('tenant:database'));
        if (! $facet instanceof ResourceDescriptor) {
            throw new TenantFailure('unsupported_capability');
        }

        return $facet;
    }

    /** @return list<array{id: string, slot: string, spiVersion: int, service: object, capabilities: list<string>}> */
    private static function services(Container $app): array
    {
        if (! $app->bound('tenant.services')) {
            return [];
        }
        $services = $app->make('tenant.services');
        if (! is_array($services) || ! array_is_list($services)) {
            throw new InvalidArgumentException('Invalid services');
        }
        $result = [];
        foreach ($services as $service) {
            if (! is_array($service) || ! is_string($service['id'] ?? null) || ! is_string($service['slot'] ?? null) || ! is_int($service['spiVersion'] ?? null) || ! is_object($service['service'] ?? null) || ! is_array($service['capabilities'] ?? null) || ! array_is_list($service['capabilities'])) {
                throw new InvalidArgumentException('Invalid service');
            }
            foreach ($service['capabilities'] as $capability) {
                if (! is_string($capability)) {
                    throw new InvalidArgumentException('Invalid capability');
                }
            }
            $result[] = ['id' => $service['id'], 'slot' => $service['slot'], 'spiVersion' => $service['spiVersion'], 'service' => $service['service'], 'capabilities' => $service['capabilities']];
        }

        return $result;
    }
}
