<?php

declare(strict_types=1);

use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\Console\RestartCommand;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Queue\Worker;
use Symfony\Component\Console\Tester\CommandTester;
use Tenant\Data\Context\ProfileRef;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Configuration\TenantOptions;
use Tenant\Laravel\Internal\Queue\ControlQueueManager;
use Tenant\Laravel\Queue\QueueRegistry;
use Tenant\Laravel\Registrars\TenantQueueRegistrar;

/**
 * @param  list<string>  $centralCacheStores
 */
function bootQueueControlApplication(array $centralCacheStores): Application
{
    $app = new Application(dirname(__DIR__, 4));
    $app->instance('env', 'testing');
    $app->instance('config', new Repository([
        'app' => ['name' => 'Test', 'env' => 'testing'],
        'cache' => [
            'default' => 'central',
            'stores' => [
                'control' => ['driver' => 'array'],
                'central' => ['driver' => 'array'],
            ],
        ],
        'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
        'tenant' => ['central_cache_stores' => $centralCacheStores],
    ]));
    $app->singleton(ExceptionHandler::class, static fn (Application $app): Handler => new Handler($app));
    $app->register(CacheServiceProvider::class);
    $app->register(QueueServiceProvider::class);
    $app->singleton(RestartCommand::class, static fn (Application $app): RestartCommand => new RestartCommand($app->make('cache.store')));
    $app->instance(TenantOptions::class, TenantOptions::fromArray([
        'central_cache_stores' => $centralCacheStores,
    ]));
    $app->instance(QueueRegistry::class, new QueueRegistry([], [], [], [], str_repeat('k', 32), new ProfileRef('php-scoped', 1)));
    (new TenantQueueRegistrar)->register($app);
    $app->boot();

    return $app;
}

function queueControlRepository(ControlQueueManager $manager): CacheRepository
{
    $store = new ReflectionProperty($manager, 'control')->getValue($manager);
    expect($store)->toBeInstanceOf(CacheRepository::class);

    return $store;
}

function workerCacheRepository(Worker $worker): CacheRepository
{
    $store = new ReflectionProperty($worker, 'cache')->getValue($worker);
    expect($store)->toBeInstanceOf(CacheRepository::class);

    return $store;
}

it('wires queue control and the worker to the single configured central cache store', function (): void {
    $app = bootQueueControlApplication(['control']);
    $control = $app->make('cache')->store('control');
    $central = $app->make('cache')->store('central');
    $queue = $app->make('queue');
    $worker = $app->make('queue.worker');

    expect($queue)->toBeInstanceOf(ControlQueueManager::class)
        ->and($worker)->toBeInstanceOf(Worker::class);

    $queueStore = queueControlRepository($queue);
    $workerStore = workerCacheRepository($worker);

    expect($queueStore)->toBe($control)
        ->and($workerStore)->toBe($control)
        ->and($queueStore)->not->toBe($central)
        ->and($workerStore)->not->toBe($central);

    $control->forever('illuminate:queue:paused:database:emails', true);
    expect($queue->isPaused('database', 'emails'))->toBeTrue();
    $control->forget('illuminate:queue:paused:database:emails');
    $central->forever('illuminate:queue:paused:database:emails', true);
    expect($queue->isPaused('database', 'emails'))->toBeFalse();

    $command = $app->make(RestartCommand::class);
    $command->setLaravel($app);
    expect(new CommandTester($command)->execute([]))->toBe(0)
        ->and($workerStore->get('illuminate:queue:restart'))->toBeInt()
        ->and($central->get('illuminate:queue:restart'))->toBeNull();
});

it('fails closed when central cache stores is not exactly one name', function (): void {
    $app = bootQueueControlApplication(['control', 'central']);

    expect(fn () => $app->make('queue'))
        ->toThrow(TenantFailure::class, 'facility_configuration')
        ->and(fn () => $app->make('queue.worker'))
        ->toThrow(TenantFailure::class, 'facility_configuration');
});
