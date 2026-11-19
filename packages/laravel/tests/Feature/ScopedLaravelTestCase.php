<?php

declare(strict_types=1);

namespace Tenant\Laravel\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Override;
use Packman\PackmanServiceProvider;
use Packman\Testkit\PackageTestCase;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Laravel\Data\ScopeDescriptor;
use Tenant\Laravel\TenantServiceProvider;

abstract class ScopedLaravelTestCase extends PackageTestCase
{
    private string $root = '';

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [PackmanServiceProvider::class, TenantServiceProvider::class];
    }

    /** @param Application $app */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->root = sys_get_temp_dir().'/tenant_scoped_'.bin2hex(random_bytes(8));
        $config = $app->make(Repository::class);
        $config->set('database.default', 'sqlite');
        $config->set('database.connections', [
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'central' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        $config->set('cache.default', 'tenant');
        $config->set('cache.stores.tenant', ['driver' => 'array']);
        $config->set('cache.stores.central', ['driver' => 'array']);
        $config->set('session.driver', 'file');
        $config->set('session.files', $this->root.'/host-sessions');
        $config->set('tenant', [
            'runtime_profile' => 'laravel-scoped',
            'facilities' => ['database' => false],
            'shared' => ['database' => ['owner' => 'host:eloquent', 'purpose' => 'host:row-isolation']],
        ]);

        $app->instance(ContextResolver::class, new class($this->root) implements ContextResolver
        {
            public function __construct(private readonly string $root) {}

            public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
            {
                if ($selection->mode === 'central') {
                    return new ResolvedIsolation(ContextState::central(), new FacetBindings([]));
                }

                $identity = $selection->identity;
                assert($identity instanceof IsolationIdentity);

                return new ResolvedIsolation(
                    ContextState::active(new IsolationContext(
                        $selection->mode,
                        $identity,
                        $selection->expectedProfile,
                        new BindingExpectation($identity->id.'-resource', '1', '1'),
                        $selection->extensions,
                    )),
                    new FacetBindings(['tenant:resource' => new ScopeDescriptor(
                        'test:'.$identity->id.':',
                        $this->root.'/'.$identity->id.'/durable',
                        $this->root.'/'.$identity->id.'/ephemeral',
                        $this->root.'/'.$identity->id.'/session',
                    )]),
                );
            }
        });
    }

    public function selection(string $key = 'a', string $mode = 'tenant'): Selection
    {
        if ($key === 'central') {
            return Selection::central(new ProfileRef('laravel-scoped', 1));
        }

        $identity = new IsolationIdentity('test', $key);

        return $mode === 'single'
            ? Selection::single($identity)
            : Selection::tenant($identity, new ProfileRef('laravel-scoped', 1));
    }

    public function resourceRoot(string $key, string $kind): string
    {
        return $this->root.'/'.$key.'/'.$kind;
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->root !== '') {
                new Filesystem()->deleteDirectory($this->root);
            }
        }
    }
}
