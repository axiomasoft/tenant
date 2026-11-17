<?php

declare(strict_types=1);

namespace Tenant\Laravel\Registrars;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Redis\RedisManager;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store as SessionStore;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use PDO;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Configuration\TenantOptions;
use Tenant\Laravel\Http\ScopeSessionToResource;
use Tenant\Laravel\Internal\Cache\GuardedCache;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;
use Tenant\Laravel\Internal\Database\GuardedConnection;
use Tenant\Laravel\Internal\Facilities\GuardedSessionHandler;
use Tenant\Laravel\Internal\Facilities\SessionGeneration;
use Tenant\Laravel\Internal\Runtime\LaravelRuntimeFactory;
use Tenant\Laravel\Internal\Storage\LocalStorage;
use Tenant\Laravel\Laravel\ConsoleCommandProbe;
use Tenant\Runtime\SafeTenantRuntime;

/**
 * Registers guarded database, cache, session, local storage, and HTTP session-binding middleware.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class TenantFacilitiesRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->scoped('tenant.storage', static fn (Container $app): LocalStorage => new LocalStorage(
            FrameworkConfig::service($app, 'filesystem', FilesystemManager::class),
            FrameworkConfig::service($app, SafeTenantRuntime::class, SafeTenantRuntime::class)->guard(),
            LaravelRuntimeFactory::descriptor($app),
        ));
    }

    #[Override]
    public function boot(Application $app): void
    {
        if (ConsoleCommandProbe::isDiagnosticCommand($app)) {
            return;
        }

        $owner = FrameworkConfig::service($app, SafeTenantRuntime::class, SafeTenantRuntime::class);
        $options = FrameworkConfig::service($app, TenantOptions::class, TenantOptions::class);

        FrameworkConfig::service($app, 'db', DatabaseManager::class)->extend('tenant-guarded-pgsql', static function (array $config, string $name) use ($app, $owner): Connection {
            $guard = $owner->guard();
            $config = FrameworkConfig::asMap($config);
            $config['driver'] = 'pgsql';
            $config['name'] = $name;

            if (! is_string($config['database'] ?? null) || ! is_string($config['prefix'] ?? '')) {
                throw new TenantFailure('facility_configuration');
            }

            return new GuardedConnection(static function () use ($app, $config, $owner): PDO {
                $descriptor = LaravelRuntimeFactory::descriptor($app);
                $expected = $owner->current()->context->binding ?? throw new TenantFailure('missing');
                FrameworkConfig::service($app, BindingVerifier::class, BindingVerifier::class)
                    ->verify(new BindingVerificationInput($expected, $descriptor));

                return (new PostgresConnector)->connect($config);
            }, $config['database'], $config['prefix'] ?? '', $config, $guard);
        });

        FrameworkConfig::service($app, 'cache', CacheManager::class)->extend('tenant-guarded', function (Container $container, array $config) use ($owner): CacheRepository {
            $guard = $owner->guard();
            $prefix = $config['prefix'] ?? '';
            $connection = $config['connection'] ?? '';
            $lockConnection = $config['lock_connection'] ?? $connection;

            if (! is_string($prefix) || ! is_string($connection) || ! is_string($lockConnection)) {
                throw new TenantFailure('facility_configuration');
            }

            $raw = match ($config['tenant_driver'] ?? null) {
                'array' => new ArrayStore,
                'redis' => new RedisStore(FrameworkConfig::service($container, 'redis', RedisManager::class), $prefix, $connection),
                default => throw new TenantFailure('facility_configuration'),
            };

            if ($raw instanceof RedisStore) {
                $raw->setLockConnection($lockConnection);
            }

            return new CacheRepository(new GuardedCache($raw, $guard));
        });

        $config = new FrameworkConfig(static fn (): ConfigRepository => FrameworkConfig::service($app, 'config', ConfigRepository::class));

        if ($options->facilities->database) {
            $config->set('database.connections.tenant', ['driver' => 'tenant-guarded-pgsql']);
            $databases = FrameworkConfig::service($app, 'db', DatabaseManager::class);
            $databases->purge('tenant');
            $databases->setDefaultConnection('tenant');
        }

        if ($options->facilities->cache) {
            $cache = FrameworkConfig::service($app, 'cache', CacheManager::class);

            foreach ($options->cacheStores as $name) {
                $store = $config->map('cache.stores.'.$name);
                $store['tenant_driver'] = $store['driver'];
                $store['tenant_base_prefix'] = $store['prefix'] ?? '';
                $store['driver'] = 'tenant-guarded';
                $config->set('cache.stores.'.$name, $store);
                $cache->forgetDriver($name);
            }
        }

        // Materialize configured live store without IO so every transition swaps its handler.
        if ($options->facilities->session && $config->get('session.driver') !== null) {
            $sessions = FrameworkConfig::service($app, 'session', SessionManager::class);
            $generation = FrameworkConfig::service($app, SessionGeneration::class, SessionGeneration::class);

            foreach (['file', 'redis'] as $driver) {
                $sessions->extend($driver, function () use ($app, $driver, $generation): GuardedSessionHandler {
                    $fresh = new SessionManager($app)->driver($driver);

                    if (! $fresh instanceof SessionStore) {
                        throw new TenantFailure('facility_configuration');
                    }

                    return new GuardedSessionHandler($fresh->getHandler(), $generation->checker());
                });
            }

            $sessions->driver();

            foreach (FrameworkConfig::stores($sessions) as $store) {
                if (! $store->getHandler() instanceof GuardedSessionHandler) {
                    $store->setHandler(new GuardedSessionHandler($store->getHandler(), $generation->checker()));
                }
            }
        }

        $app->afterResolving(HttpKernelContract::class, static function (mixed $kernel): void {
            if ($kernel instanceof HttpKernel) {
                $kernel->addToMiddlewarePriorityAfter(StartSession::class, ScopeSessionToResource::class);
                $kernel->addToMiddlewarePriorityBefore(
                    [ShareErrorsFromSession::class, AuthenticatesRequests::class],
                    ScopeSessionToResource::class,
                );
            }
        });
    }
}
