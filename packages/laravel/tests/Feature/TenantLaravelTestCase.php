<?php

declare(strict_types=1);

namespace Tenant\Laravel\Tests\Feature;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Override;
use Packman\PackmanServiceProvider;
use Packman\Testkit\PackageTestCase;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Data\Verification\VerifiedBinding;
use Tenant\Laravel\TenantServiceProvider;

abstract class TenantLaravelTestCase extends PackageTestCase
{
    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            TenantServiceProvider::class,
        ];
    }

    /** @param Application $app */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make(ConfigRepository::class);
        $config->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $config->set('cache.default', 'tenant');
        $config->set('cache.stores.tenant', ['driver' => 'array']);
        $config->set('cache.stores.central', ['driver' => 'array']);
        $config->set('session.driver');
        $config->set('queue.connections.database.after_commit', false);
        $config->set('tenant', [
            'central_connection' => 'central',
            'cache_stores' => ['tenant'],
            'central_cache_stores' => ['central'],
            'facilities' => [
                'database' => true,
                'cache' => true,
                'session' => false,
                'storage' => true,
            ],
            'storage_driver' => 'local',
            'shared' => [
                'session' => ['owner' => 'host:session', 'purpose' => 'host:test'],
            ],
        ]);

        $app->singleton(ContextResolver::class, static fn (): ContextResolver => new class implements ContextResolver
        {
            public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
            {
                return new ResolvedIsolation(ContextState::central(), new FacetBindings([]));
            }
        });

        $app->singleton(BindingVerifier::class, static fn (): BindingVerifier => new class implements BindingVerifier
        {
            public function verify(BindingVerificationInput $input): VerifiedBinding
            {
                return new VerifiedBinding(new BindingExpectation('res:1', 'inc:1', 'rev:1'), 'test:owner', 'gen:1');
            }
        });
    }
}
