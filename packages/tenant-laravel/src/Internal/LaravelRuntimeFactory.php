<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal;

use Tenant\Laravel\Data\ResourceDescriptor;

use Closure;
use Illuminate\Container\Container;
use Tenant\Composition\CompositionBuilder;
use Tenant\Contracts\{BindingVerifier, ContextResolver, Facility, ParticipantFactory};
use Tenant\Data\{CapabilityId, ParticipantDeclaration, Selection};
use Tenant\Runtime\SafeTenantRuntime;
use Tenant\Laravel\Internal\Facilities\{DatabaseFacility, CacheFacility, SessionFacility, ScopeGenerationFacility, SessionGeneration, GuardedSessionHandler, FacilitiesValidator};

/** @internal Single owner construction; facilities are eagerly validated and acyclic. */
final class LaravelRuntimeFactory
{
    public static function build(Container $app): SafeTenantRuntime
    {
        (new FacilitiesValidator(FrameworkConfig::service($app, 'config', \Illuminate\Contracts\Config\Repository::class)))->assertValid();
        $config = new FrameworkConfig(static fn () => FrameworkConfig::service($app, 'config', \Illuminate\Contracts\Config\Repository::class));
        $verifier = FrameworkConfig::service($app, BindingVerifier::class, BindingVerifier::class);
        $profiles = ['laravel-pg-database' => ['version' => 1, 'modes' => ['tenant', 'central'], 'requiredCapabilities' => ['tenant:database', 'tenant:binding-verification', 'tenant:scope-generation'], 'bindingCapability' => 'tenant:binding-verification']];
        $builder = new CompositionBuilder($profiles, [['id' => 'host:verifier', 'slot' => 'tenant:binding/default', 'spiVersion' => 1, 'service' => $verifier, 'capabilities' => ['tenant:binding-verification']], ...self::services($app)]);
        $facilities = [
            'database' => [fn () => new DatabaseFacility(FrameworkConfig::service($app, 'db', \Illuminate\Database\DatabaseManager::class), $config, $config->text('tenant.central_connection')), ['tenant:database']],
            'cache' => [fn () => new CacheFacility(FrameworkConfig::service($app, 'cache', \Illuminate\Cache\CacheManager::class), $config, $config->strings('tenant.cache_stores')), ['tenant:cache']],
            'session' => [fn () => new SessionFacility(FrameworkConfig::service($app, 'session', \Illuminate\Session\SessionManager::class), FrameworkConfig::service($app, 'cache', \Illuminate\Cache\CacheManager::class), $app, $config, FrameworkConfig::service($app, SessionGeneration::class, SessionGeneration::class)), ['tenant:session']],
            'scope' => [fn () => new ScopeGenerationFacility($app), ['tenant:scope-generation']],
        ];
        foreach ($facilities as $name => [$create, $capabilities]) {
            if (!$config->get('tenant.facilities.'.$name, true)) continue;
            $factory = new class($create) implements ParticipantFactory {
                public function __construct(private Closure $create) {}
                public function create(): Facility { $facility = ($this->create)(); if (!$facility instanceof Facility) throw new \LogicException(); return $facility; }
            };
            $builder->register(new ParticipantDeclaration('tenant:'.$name, 1, 'resource', array_map(fn ($id) => new CapabilityId($id), $capabilities), [], ['tenant:'.$name.'/default'], [], $name === 'database' ? [] : ['tenant:database'], null, $factory));
        }
        if ($app->bound('tenant.compose')) {
            $compose = $app->make('tenant.compose');
            if (!$compose instanceof Closure) throw new \InvalidArgumentException('Invalid composition hook');
            $compose($builder, $app);
        }
        $composition = $builder->compile();
        $app->instance('tenant.composition', $composition);
        $observers = $app->bound('tenant.observers') ? $app->make('tenant.observers') : [];
        if (!is_array($observers) || !array_is_list($observers)) throw new \InvalidArgumentException('Invalid observers');
        foreach ($observers as $observer) if (!$observer instanceof \Tenant\Contracts\ObservationSink) throw new \InvalidArgumentException('Invalid observer');
        $diagnostics = $app->bound('tenant.diagnostics') ? FrameworkConfig::service($app, 'tenant.diagnostics', \Tenant\Contracts\DiagnosticSink::class) : null;
        $runtime = new SafeTenantRuntime(FrameworkConfig::service($app, ContextResolver::class, ContextResolver::class), $composition, $observers, $diagnostics);
        return $runtime;
    }
    public static function descriptor(Container $app): ResourceDescriptor
    {
        $context = FrameworkConfig::service($app, SafeTenantRuntime::class, SafeTenantRuntime::class)->current()->context ?? throw new \Tenant\Exceptions\ScopeRequired();
        $selection = Selection::tenant($context->identity, $context->profile, $context->extensions, $context->binding);
        $facet = FrameworkConfig::service($app, ContextResolver::class, ContextResolver::class)->resolve($selection, new \Tenant\Data\Purpose('tenant:facilities'))->facets->require(new CapabilityId('tenant:database'));
        if (!$facet instanceof ResourceDescriptor) throw new \Tenant\Exceptions\TenantFailure('unsupported_capability');
        return $facet;
    }
    /** @return list<array{id: string, slot: string, spiVersion: int, service: object, capabilities: list<string>}> */
    private static function services(Container $app): array
    {
        if (!$app->bound('tenant.services')) return [];
        $services = $app->make('tenant.services');
        if (!is_array($services) || !array_is_list($services)) throw new \InvalidArgumentException('Invalid services');
        $result = [];
        foreach ($services as $service) {
            if (!is_array($service) || !is_string($service['id'] ?? null) || !is_string($service['slot'] ?? null) || !is_int($service['spiVersion'] ?? null) || !is_object($service['service'] ?? null) || !is_array($service['capabilities'] ?? null) || !array_is_list($service['capabilities'])) throw new \InvalidArgumentException('Invalid service');
            foreach ($service['capabilities'] as $capability) if (!is_string($capability)) throw new \InvalidArgumentException('Invalid capability');
            $result[] = ['id' => $service['id'], 'slot' => $service['slot'], 'spiVersion' => $service['spiVersion'], 'service' => $service['service'], 'capabilities' => $service['capabilities']];
        }
        return $result;
    }
}
