<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Artisan;
use Tenant\Laravel\Tests\Feature\TenantLaravelTestCase;

abstract class BrokenCentralConnectionTestCase extends TenantLaravelTestCase
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

        $app->make(ConfigRepository::class)->set('tenant.central_connection', 'missing_conn');
    }
}

uses(BrokenCentralConnectionTestCase::class);

it('runs packman:doctor --json with a missing central_connection without crashing during boot', function (): void {
    $exit = Artisan::call('packman:doctor', ['--prefix' => ['tenant'], '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['checks'][0]['key'] ?? null)->toBe('tenant.runtime.configuration')
        ->and($payload['checks'][0]['status'] ?? null)->toBe('error')
        ->and($payload['checks'][0]['findings'][0]['code'] ?? null)->toBe('tenant.doctor.invalid_central_connection');
});
