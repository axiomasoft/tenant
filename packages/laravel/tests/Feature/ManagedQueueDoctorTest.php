<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use Tenant\Data\Context\ProfileRef;
use Tenant\Laravel\Queue\QueueRegistry;
use Tenant\Laravel\Tests\Feature\TenantLaravelTestCase;

abstract class ManagedQueueDoctorTestCase extends TenantLaravelTestCase
{
    private mixed $previousArgv = null;

    protected function setUp(): void
    {
        $this->previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'packman:doctor', '--json'];

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $_SERVER['argv'] = $this->previousArgv;
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make(Repository::class);
        $config->set('queue.failed.database', 'central');
        $config->set('queue.connections.database', ['driver' => 'database', 'connection' => 'central', 'after_commit' => false]);
        $config->set('queue.connections.sync.after_commit', true);
        $app->instance(QueueRegistry::class, new QueueRegistry([], [], [], [], str_repeat('x', 32), new ProfileRef('central', 1)));
    }
}

uses(ManagedQueueDoctorTestCase::class);

it('reports the managed sync commit setting through Artisan JSON and exits zero after correction', function (): void {
    $args = ['--prefix' => ['tenant'], '--json' => true];
    $exit = Artisan::call('packman:doctor', $args);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = array_column($payload['checks'], null, 'key');
    $check = $checks['tenant.runtime.configuration'];
    $findings = $check['findings'];

    expect($exit)->toBe(1)
        ->and($check['status'])->toBe('error')
        ->and($findings)->toHaveCount(1)
        ->and($findings[0]['code'] ?? null)->toBe('tenant.doctor.queue_after_commit')
        ->and($findings[0]['evidence']['key'] ?? null)->toBe('queue.connections.sync.after_commit');

    $this->app->make(Repository::class)->set('queue.connections.sync.after_commit', false);
    $exit = Artisan::call('packman:doctor', $args);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = array_column($payload['checks'], null, 'key');

    expect($exit)->toBe(0)
        ->and(array_column($checks['tenant.runtime.configuration']['findings'], 'code'))->toBe(['tenant.doctor.healthy']);
});

it('keeps both commit findings when their Artisan JSON codes are identical', function (): void {
    $this->app->make(Repository::class)->set('queue.connections.database.after_commit', true);

    $exit = Artisan::call('packman:doctor', ['--prefix' => ['tenant'], '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = array_column($payload['checks'], null, 'key');
    $findings = $checks['tenant.runtime.configuration']['findings'];

    expect($exit)->toBe(1)
        ->and($findings)->toHaveCount(2)
        ->and(array_column($findings, 'code'))->toBe(['tenant.doctor.queue_after_commit', 'tenant.doctor.queue_after_commit'])
        ->and(array_column(array_column($findings, 'evidence'), 'key'))->toEqualCanonicalizing([
            'queue.connections.database.after_commit',
            'queue.connections.sync.after_commit',
        ]);
});
