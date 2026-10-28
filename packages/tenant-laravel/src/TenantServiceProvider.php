<?php

declare(strict_types=1);

namespace Tenant\Laravel;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\{ArrayStore, RedisStore, Repository};
use Illuminate\Database\Connectors\PostgresConnector;
use Tenant\Contracts\TenantRuntime;
use Tenant\Runtime\SafeTenantRuntime;
use Tenant\Laravel\Internal\LaravelRuntimeFactory;
use Tenant\Laravel\Internal\FrameworkConfig;
use Illuminate\Container\Container;
use Tenant\Laravel\Internal\Facilities\{SessionGeneration, GuardedSessionHandler};
use Tenant\Laravel\Internal\Database\GuardedConnection;
use Tenant\Laravel\Internal\Cache\GuardedCache;
use Tenant\Laravel\Internal\Storage\LocalStorage;
use Tenant\Exceptions\TenantFailure;

/** Registers a single runtime; host supplies resolver, verifier and optional composition. */
final class TenantServiceProvider extends ServiceProvider
{
    private readonly \Illuminate\Foundation\Application $container;
    public function __construct($app)
    {
        if (!$app instanceof \Illuminate\Foundation\Application) throw new TenantFailure('facility_configuration');
        parent::__construct($app);
        $this->container = $app;
    }
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/tenant.php', 'tenant');
        if ($this->app->bound(TenantRuntime::class) || $this->app->bound(SafeTenantRuntime::class)) throw new TenantFailure('exclusive_slot_conflict');
        $this->app->singleton(SessionGeneration::class, fn (Container $app) => new SessionGeneration(fn () => FrameworkConfig::service($app, SafeTenantRuntime::class, SafeTenantRuntime::class)));
        $this->app->singleton(SafeTenantRuntime::class, fn (Container $app) => LaravelRuntimeFactory::build($app));
        $this->app->singleton(TenantRuntime::class, function (Container $app) {
            $owner = FrameworkConfig::service($app, SafeTenantRuntime::class, SafeTenantRuntime::class);
            $decorate = $app->bound('tenant.decorate') ? $app->make('tenant.decorate') : null;
            if ($decorate !== null && !$decorate instanceof \Closure) throw new TenantFailure('replacement_incompatible');
            $runtime = $decorate !== null ? $decorate($owner) : $owner;
            if (!$runtime instanceof TenantRuntime) throw new TenantFailure('replacement_incompatible');
            return $runtime;
        });
        $this->app->singleton(\Tenant\Tenant::class, fn (Container $app) => new \Tenant\Tenant(FrameworkConfig::service($app, TenantRuntime::class, TenantRuntime::class)));
        $this->app->singleton(\Tenant\Laravel\Internal\Boundary\ProcessBoundary::class, fn (Container $app) => new \Tenant\Laravel\Internal\Boundary\ProcessBoundary(FrameworkConfig::service($app, TenantRuntime::class, TenantRuntime::class)));
        if ($this->app->bound(\Tenant\Laravel\Queue\QueueRegistry::class)) {
            $this->app->extend('queue', function ($original, Container $app) {
                $control = FrameworkConfig::service($app, 'cache', \Illuminate\Cache\CacheManager::class)->store('central');
                $manager = new \Tenant\Laravel\Internal\Queue\ControlQueueManager($this->container, $control);
                (new \Illuminate\Queue\QueueServiceProvider($this->container))->registerConnectors($manager);
                return $manager;
            });
            $this->app->afterResolving('queue.worker', function ($worker): void {
                if ($worker instanceof \Illuminate\Queue\Worker) $worker->setCache(FrameworkConfig::service($this->container, 'cache', \Illuminate\Cache\CacheManager::class)->store('central'));
            });
            $this->app->singleton(\Tenant\Contracts\ContextCodec::class, fn () => new \Tenant\Laravel\Codec\JsonContextCodec());
            $this->app->singleton(\Tenant\Laravel\Queue\ContextDispatcher::class, fn (Container $app) => new \Tenant\Laravel\Internal\Queue\LaravelContextDispatcher(FrameworkConfig::service($app, TenantRuntime::class, TenantRuntime::class), FrameworkConfig::service($app, \Tenant\Contracts\ContextCodec::class, \Tenant\Contracts\ContextCodec::class), FrameworkConfig::service($app, \Tenant\Laravel\Queue\QueueRegistry::class, \Tenant\Laravel\Queue\QueueRegistry::class), FrameworkConfig::service($app, 'queue', \Illuminate\Queue\QueueManager::class)));
            $this->app->singleton(\Tenant\Laravel\Internal\Queue\QueueContextBoundary::class);
        }
        $this->app->scoped('tenant.storage', fn (Container $app) => new LocalStorage(FrameworkConfig::service($app, 'filesystem', \Illuminate\Filesystem\FilesystemManager::class), FrameworkConfig::service($app, SafeTenantRuntime::class, SafeTenantRuntime::class)->guard(), LaravelRuntimeFactory::descriptor($app)));
    }
    public function boot(): void
    {
        if ($this->app->bound(\Tenant\Laravel\Queue\QueueRegistry::class)) {
            $this->protectBinding(\Tenant\Laravel\Queue\QueueRegistry::class, FrameworkConfig::service($this->container, \Tenant\Laravel\Queue\QueueRegistry::class, \Tenant\Laravel\Queue\QueueRegistry::class));
            $queueConfig = FrameworkConfig::service($this->container, 'config', \Illuminate\Contracts\Config\Repository::class);
            if ($queueConfig->get('queue.failed.database') !== $queueConfig->get('tenant.central_connection') || $queueConfig->get('queue.connections.database.driver') !== 'database' || $queueConfig->get('queue.connections.database.connection') !== $queueConfig->get('tenant.central_connection') || $queueConfig->get('queue.connections.database.after_commit') !== false || $queueConfig->get('queue.connections.sync.after_commit') !== false) throw new TenantFailure('facility_configuration');
            (new \Tenant\Laravel\Internal\Queue\QueueEventRegistrar(FrameworkConfig::service($this->container, \Tenant\Laravel\Internal\Queue\QueueContextBoundary::class, \Tenant\Laravel\Internal\Queue\QueueContextBoundary::class)))->boot(FrameworkConfig::service($this->container, 'events', \Illuminate\Contracts\Events\Dispatcher::class));
        }
        // Validate before altering default framework access. Explicit central names remain fixed.
        FrameworkConfig::service($this->container, TenantRuntime::class, TenantRuntime::class);
        $owner = FrameworkConfig::service($this->container, SafeTenantRuntime::class, SafeTenantRuntime::class);
        $this->protectBinding(SafeTenantRuntime::class, $owner);
        $this->protectBinding(TenantRuntime::class, FrameworkConfig::service($this->container, TenantRuntime::class, TenantRuntime::class));
        FrameworkConfig::service($this->container, 'db', \Illuminate\Database\DatabaseManager::class)->extend('tenant-guarded-pgsql', function (array $config, string $name) use ($owner) {
            $guard = $owner->guard();
            $config = FrameworkConfig::asMap($config);
            $config['driver'] = 'pgsql';
            $config['name'] = $name;
            if (!is_string($config['database'] ?? null) || !is_string($config['prefix'] ?? '')) throw new TenantFailure('facility_configuration');
            return new GuardedConnection(function () use ($config, $owner) {
                $descriptor = LaravelRuntimeFactory::descriptor($this->container);
                $expected = $owner->current()->context->binding ?? throw new TenantFailure('missing');
                FrameworkConfig::service($this->container, \Tenant\Contracts\BindingVerifier::class, \Tenant\Contracts\BindingVerifier::class)->verify(new \Tenant\Data\BindingVerificationInput($expected, $descriptor));
                return (new PostgresConnector())->connect($config);
            }, $config['database'], $config['prefix'] ?? '', $config, $guard);
        });
        FrameworkConfig::service($this->container, 'cache', \Illuminate\Cache\CacheManager::class)->extend('tenant-guarded', function (Container $app, array $config) use ($owner) {
            $guard = $owner->guard();
            $prefix = $config['prefix'] ?? '';
            $connection = $config['connection'] ?? '';
            $lockConnection = $config['lock_connection'] ?? $connection;
            if (!is_string($prefix) || !is_string($connection) || !is_string($lockConnection)) throw new TenantFailure('facility_configuration');
            $raw = match ($config['tenant_driver'] ?? null) {
                'array' => new ArrayStore(),
                'redis' => new RedisStore(FrameworkConfig::service($app, 'redis', \Illuminate\Redis\RedisManager::class), $prefix, $connection),
                default => throw new TenantFailure('facility_configuration'),
            };
            if ($raw instanceof RedisStore) $raw->setLockConnection($lockConnection);
            return new Repository(new GuardedCache($raw, $guard));
        });
        $config = new FrameworkConfig(fn () => FrameworkConfig::service($this->container, 'config', \Illuminate\Contracts\Config\Repository::class));
        if ($config->get('tenant.facilities.database', true)) {
            $config->set('database.connections.tenant', ['driver' => 'tenant-guarded-pgsql']);
            FrameworkConfig::service($this->container, 'db', \Illuminate\Database\DatabaseManager::class)->purge('tenant');
            FrameworkConfig::service($this->container, 'db', \Illuminate\Database\DatabaseManager::class)->setDefaultConnection('tenant');
        }
        if ($config->get('tenant.facilities.cache', true)) {
            foreach ($config->strings('tenant.cache_stores') as $name) {
                $store = $config->map('cache.stores.'.$name);
                $store['tenant_driver'] = $store['driver'];
                $store['tenant_base_prefix'] = $store['prefix'] ?? '';
                $store['driver'] = 'tenant-guarded';
                $config->set('cache.stores.'.$name, $store);
                FrameworkConfig::service($this->container, 'cache', \Illuminate\Cache\CacheManager::class)->forgetDriver($name);
            }
        }
        // Materialize configured live store without IO so every transition swaps its handler.
        if ($config->get('tenant.facilities.session', true) && $config->get('session.driver') !== null) {
            $store = FrameworkConfig::service($this->container, 'session', \Illuminate\Session\SessionManager::class)->driver();
            if (!$store instanceof \Illuminate\Session\Store) throw new TenantFailure('facility_configuration');
            $store->setHandler(new GuardedSessionHandler($store->getHandler(), FrameworkConfig::service($this->container, SessionGeneration::class, SessionGeneration::class)->checker()));
        }
        $this->app->afterResolving(\Illuminate\Contracts\Http\Kernel::class, function ($kernel): void {
            if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
                $kernel->addToMiddlewarePriorityAfter(\Illuminate\Session\Middleware\StartSession::class, \Tenant\Laravel\Http\ScopeSessionToResource::class);
                $kernel->addToMiddlewarePriorityBefore([\Illuminate\View\Middleware\ShareErrorsFromSession::class, \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class], \Tenant\Laravel\Http\ScopeSessionToResource::class);
            }
        });
    }
    private function protectBinding(string $name, object $owner): void
    {
        $this->container->rebinding($name, function (Container $app, $replacement) use ($name, $owner): void {
            if ($replacement === $owner) return;
            $app->instance($name, $owner);
            throw new TenantFailure('composition_frozen');
        });
    }
}
