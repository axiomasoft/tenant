<?php

declare(strict_types=1);

namespace Tenant\Laravel\Registrars;

use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Queue\Worker;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Tenant\Contracts\Context\ContextCodec;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Configuration\TenantOptions;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;
use Tenant\Laravel\Internal\Queue\ControlQueueManager;
use Tenant\Laravel\Internal\Queue\LaravelContextDispatcher;
use Tenant\Laravel\Internal\Queue\QueueContextBoundary;
use Tenant\Laravel\Internal\Queue\QueueEventRegistrar;
use Tenant\Laravel\Laravel\ConsoleCommandProbe;
use Tenant\Laravel\Queue\ContextDispatcher;
use Tenant\Laravel\Queue\QueueRegistry;

/**
 * Registers Tenant signed queue dispatch, central control cache wiring, and job context boundaries.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class TenantQueueRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->extend('queue', static function (mixed $original, Container $container) use ($app): mixed {
            if (! $container->bound(QueueRegistry::class)) {
                return $original;
            }

            $control = FrameworkConfig::service($container, 'cache', CacheManager::class)->store('central');
            $manager = new ControlQueueManager($app, $control);
            new QueueServiceProvider($app)->registerConnectors($manager);

            return $manager;
        });

        $app->afterResolving('queue.worker', static function (mixed $worker) use ($app): void {
            if ($app->bound(QueueRegistry::class) && $worker instanceof Worker) {
                $worker->setCache(FrameworkConfig::service($app, 'cache', CacheManager::class)->store('central'));
            }
        });

        $app->singleton(ContextDispatcher::class, static fn (Container $app): LaravelContextDispatcher => new LaravelContextDispatcher(
            FrameworkConfig::service($app, TenantRuntime::class, TenantRuntime::class),
            FrameworkConfig::service($app, ContextCodec::class, ContextCodec::class),
            FrameworkConfig::service($app, QueueRegistry::class, QueueRegistry::class),
            FrameworkConfig::service($app, 'queue', QueueManager::class),
        ));

        $app->singleton(QueueContextBoundary::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        if (ConsoleCommandProbe::isDiagnosticCommand($app)) {
            return;
        }

        if (! $app->bound(QueueRegistry::class)) {
            return;
        }

        $registry = FrameworkConfig::service($app, QueueRegistry::class, QueueRegistry::class);

        if ($app instanceof Container) {
            $this->watchRebind($app, $registry);
        }

        $options = FrameworkConfig::service($app, TenantOptions::class, TenantOptions::class);
        $queueConfig = FrameworkConfig::service($app, 'config', Repository::class);

        if ($queueConfig->get('queue.failed.database') !== $options->centralConnection
            || $queueConfig->get('queue.connections.database.driver') !== 'database'
            || $queueConfig->get('queue.connections.database.connection') !== $options->centralConnection
            || $queueConfig->get('queue.connections.database.after_commit') !== false
            || $queueConfig->get('queue.connections.sync.after_commit') !== false) {
            throw new TenantFailure('facility_configuration');
        }

        new QueueEventRegistrar(
            FrameworkConfig::service($app, QueueContextBoundary::class, QueueContextBoundary::class),
        )->boot(FrameworkConfig::service($app, 'events', Dispatcher::class));
    }

    private function watchRebind(Container $container, QueueRegistry $registry): void
    {
        $container->rebinding(QueueRegistry::class, static function (Container $current, mixed $replacement) use ($registry): void {
            if ($replacement === $registry) {
                return;
            }

            $current->instance(QueueRegistry::class, $registry);

            throw new TenantFailure('composition_frozen');
        });
    }
}
