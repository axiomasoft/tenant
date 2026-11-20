<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Tenant\Contracts\Context\FacetAccess;
use Tenant\Contracts\Runtime\FrameState;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Laravel\Data\ResourceDescriptor;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;
use Tenant\Laravel\Internal\Facilities\DatabaseFacility;

it('keeps central application settings and refuses a route that inherits central credentials', function (): void {
    $repository = new Repository([
        'database' => [
            'default' => 'central',
            'connections' => [
                'central' => [
                    'driver' => 'pgsql',
                    'url' => 'pgsql://central-user:secret@127.0.0.1:5432/central_db?application_name=url-central&connect_via_database=other_db',
                    'read' => ['host' => 'replica.invalid'],
                    'write' => ['host' => 'primary.invalid'],
                    'search_path' => 'evil',
                    'server_options' => ['search_path' => 'evil'],
                    'connect_via_port' => 1,
                ],
            ],
        ],
    ]);
    $app = new Container;
    $app->instance('config', $repository);
    $databases = new DatabaseManager($app, new ConnectionFactory($app));
    $facility = new DatabaseFacility($databases, new FrameworkConfig(static fn (): Repository => $repository), 'central');
    $state = new class implements FrameState
    {
        /** @var array<string, mixed> */
        private array $values = [];

        public function put(string $key, mixed $value): void
        {
            $this->values[$key] = $value;
        }

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }
    };
    $route = [
        'driver' => 'pgsql',
        'host' => 'db.example',
        'port' => 5432,
        'database' => 'tenant_a',
        'username' => 'tenant_role',
        'password' => 'tenant-secret',
        'search_path' => 'evil',
        'connect_via_database' => 'central_db',
    ];
    $descriptor = new ResourceDescriptor($route, 'tenant:a:', '/private/a/durable', '/private/a/temporary', '/private/a/sessions');
    $phase = databaseRoutePhase($descriptor);
    $facility->prepare($phase, $state);
    $facility->activate($phase, $state);
    /** @var array<string, mixed> $tenant */
    $tenant = $repository->get('database.connections.tenant');

    expect($tenant['application_name'] ?? null)->toBe('url-central')
        ->and($tenant['host'] ?? null)->toBe('db.example')
        ->and($tenant['database'] ?? null)->toBe('tenant_a')
        ->and($tenant['username'] ?? null)->toBe('tenant_role')
        ->and($tenant['password'] ?? null)->toBe('tenant-secret')
        ->and(array_key_exists('url', $tenant))->toBeFalse()
        ->and(array_key_exists('read', $tenant))->toBeFalse()
        ->and(array_key_exists('write', $tenant))->toBeFalse()
        ->and(array_key_exists('connect_via_database', $tenant))->toBeFalse()
        ->and(array_key_exists('connect_via_port', $tenant))->toBeFalse()
        ->and(array_key_exists('search_path', $tenant))->toBeFalse()
        ->and(array_key_exists('server_options', $tenant))->toBeFalse()
        ->and($databases->getDefaultConnection())->toBe('tenant');

    $incomplete = new ResourceDescriptor(['driver' => 'pgsql'], 'tenant:a:', '/private/a/durable', '/private/a/temporary', '/private/a/sessions');
    expect(fn () => $facility->activate(databaseRoutePhase($incomplete), $state))
        ->toThrow(InvalidArgumentException::class, 'unsupported_capability');
});

function databaseRoutePhase(ResourceDescriptor $descriptor): PhaseContext
{
    $resolved = new ResolvedIsolation(ContextState::active(new IsolationContext(
        'tenant',
        new IsolationIdentity('host', 'tenant-a'),
        new ProfileRef('laravel-pg-database', 1),
        new BindingExpectation('resource:a', 'incarnation:1', 'route:1'),
    )), new FacetBindings(['tenant:database' => $descriptor]));

    return new class($resolved) implements PhaseContext
    {
        public function __construct(private readonly ResolvedIsolation $resolved) {}

        public function candidate(): ContextState
        {
            return $this->resolved->context;
        }

        public function require(CapabilityId $id): FacetAccess
        {
            return $this->resolved->facets->require($id);
        }
    };
}
