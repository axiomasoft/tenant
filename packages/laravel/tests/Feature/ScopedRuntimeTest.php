<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Session\SessionManager;
use Packman\Doctor\Data\DoctorContext;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\ExtensionRef;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Exceptions\Context\ContextDenied;
use Tenant\Exceptions\Context\ScopeRequired;
use Tenant\Exceptions\Runtime\StaleHandle;
use Tenant\Exceptions\Verification\BindingMismatch;
use Tenant\Laravel\Contracts\Storage\ResourceStorage;
use Tenant\Laravel\Diagnostics\TenantDoctorCheck;
use Tenant\Laravel\Tests\Feature\ScopedLaravelTestCase;

abstract class ScopedWithoutCentralTestCase extends ScopedLaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make(Repository::class)->set('database.connections.central');
    }
}

uses(ScopedWithoutCentralTestCase::class);

it('boots scoped Laravel without a verifier, a central database, or a PostgreSQL connection extension', function (): void {
    $config = $this->app->make(Repository::class);
    $config->set('queue.connections.database.after_commit', true);

    expect($this->app->bound(BindingVerifier::class))->toBeFalse()
        ->and($this->app->make(TenantDoctorCheck::class)->run(new DoctorContext)[0]->code)->toBe('tenant.doctor.healthy')
        ->and($config->get('database.default'))->toBe('sqlite')
        ->and($config->get('database.connections.tenant'))->toBeNull();

    $config->set('database.connections.probe', ['driver' => 'tenant-guarded-pgsql', 'database' => 'unreachable']);
    expect(fn () => $this->app->make(DatabaseManager::class)->connection('probe'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported driver');
});

it('keeps managed facilities unavailable in missing and central contexts', function (): void {
    $runtime = $this->app->make(TenantRuntime::class);
    $deny = function () use ($runtime): void {
        $guardFailure = $runtime->current()->kind === 'central' ? ContextDenied::class : ScopeRequired::class;
        expect(fn () => $this->app->make(CacheManager::class)->store('tenant')->get('key'))->toThrow($guardFailure)
            ->and(fn () => $this->app->make('tenant.storage'))->toThrow($guardFailure)
            ->and(fn () => $this->app->make(SessionManager::class)->driver()->getHandler()->read(str_repeat('a', 40)))->toThrow($guardFailure);
    };

    $deny();
    $runtime->run($this->selection('central'), new Purpose('test:central'), $deny);
    expect($runtime->current()->kind)->toBe('missing');
});

it('isolates cache, real file sessions and storage across nested scoped frames and invalidates retained handles', function (): void {
    $runtime = $this->app->make(TenantRuntime::class);
    $purpose = new Purpose('test:facilities');
    $cache = $this->app->make(CacheManager::class);
    $session = $this->app->make(SessionManager::class)->driver();
    $config = $this->app->make(Repository::class);
    $databaseBefore = $config->get('database');
    $this->app->scoped('host:scope', static fn (): stdClass => new stdClass);

    $handles = $runtime->run($this->selection('a'), $purpose, function () use ($runtime, $purpose, $cache, $session, $config): array {
        $storeA = $cache->store('tenant');
        $storageA = $this->app->make('tenant.storage');
        expect($storageA)->toBeInstanceOf(ResourceStorage::class);
        $diskA = $storageA->disk();
        $handlerA = $session->getHandler();
        $scopeA = $this->app->make('host:scope');
        $storeA->put('owner', 'a', 60);
        $diskA->put('owner.txt', 'a');
        $session->setId(str_repeat('a', 40));
        $session->start();
        $session->put('owner', 'a');
        $session->save();

        $runtime->run($this->selection('b'), $purpose, function () use ($cache, $session, $storeA, $diskA, $handlerA, $scopeA): void {
            expect($cache->store('tenant')->get('owner'))->toBeNull()
                ->and($session->get('owner'))->toBeNull()
                ->and($this->app->make('host:scope'))->not->toBe($scopeA)
                ->and(fn () => $storeA->get('owner'))->toThrow(StaleHandle::class)
                ->and(fn () => $diskA->get('owner.txt'))->toThrow(StaleHandle::class)
                ->and(fn () => $handlerA->read(str_repeat('a', 40)))->toThrow(StaleHandle::class);

            $diskB = $this->app->make('tenant.storage')->disk();
            expect($diskB->exists('owner.txt'))->toBeFalse();
            $diskB->put('owner.txt', 'b');
            $session->setId(str_repeat('b', 40));
            $session->start();
            $session->put('owner', 'b');
            $session->save();
        });

        expect($session->get('owner'))->toBe('a')
            ->and($config->get('cache.stores.tenant.prefix'))->toBe('test:a:')
            ->and($this->app->make('tenant.storage')->disk()->get('owner.txt'))->toBe('a')
            ->and($this->app->make('host:scope'))->not->toBe($scopeA)
            ->and(fn () => $storeA->get('owner'))->toThrow(StaleHandle::class)
            ->and(fn () => $diskA->get('owner.txt'))->toThrow(StaleHandle::class);

        return [$cache->store('tenant'), $this->app->make('tenant.storage')->disk(), $session->getHandler()];
    });

    expect($runtime->current()->kind)->toBe('missing')
        ->and($config->get('database'))->toBe($databaseBefore)
        ->and($session->get('owner'))->toBeNull()
        ->and(file_get_contents($this->resourceRoot('a', 'durable').'/owner.txt'))->toBe('a')
        ->and(file_get_contents($this->resourceRoot('b', 'durable').'/owner.txt'))->toBe('b')
        ->and(file_exists($this->resourceRoot('a', 'session').'/'.str_repeat('a', 40)))->toBeTrue()
        ->and(file_exists($this->resourceRoot('b', 'session').'/'.str_repeat('b', 40)))->toBeTrue();

    foreach ([fn () => $handles[0]->get('owner'), fn () => $handles[1]->get('owner.txt'), fn () => $handles[2]->read(str_repeat('a', 40))] as $read) {
        expect($read)->toThrow(StaleHandle::class);
    }
});

it('preserves single mode while resolving route-free storage and session namespaces', function (): void {
    $runtime = $this->app->make(TenantRuntime::class);
    $runtime->run($this->selection('local', 'single'), new Purpose('test:single'), function () use ($runtime): void {
        expect($runtime->current()->context->mode)->toBe('single')
            ->and($runtime->current()->context->profile->id)->toBe('single');
        $this->app->make(CacheManager::class)->store('tenant')->put('key', 'single', 60);
        $disk = $this->app->make('tenant.storage')->disk();
        $disk->put('owner.txt', 'single');

        expect($disk->get('owner.txt'))->toBe('single')
            ->and($this->app->make(CacheManager::class)->store('tenant')->get('key'))->toBe('single')
            ->and($this->app->make(Repository::class)->get('session.files'))->toBe($this->resourceRoot('local', 'session'));
    });
    expect($runtime->current()->kind)->toBe('missing');
});

it('refuses a descriptor re-resolved for a different active context', function (string $difference): void {
    $runtime = $this->app->make(TenantRuntime::class);
    $original = $this->app->make(ContextResolver::class);
    $runtime->run($this->selection('a'), new Purpose('test:descriptor'), function () use ($original, $difference): void {
        $this->app->instance(ContextResolver::class, new class($original, $difference) implements ContextResolver
        {
            public function __construct(private readonly ContextResolver $original, private readonly string $difference) {}

            public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
            {
                $resolved = $this->original->resolve($selection, $purpose);
                $current = $resolved->context->context;

                return new ResolvedIsolation(ContextState::active(new IsolationContext(
                    $this->difference === 'mode' ? 'single' : $current->mode,
                    $this->difference === 'identity' ? new IsolationIdentity('test', 'b') : $current->identity,
                    $this->difference === 'profile' ? new ProfileRef('laravel-scoped', 2) : $current->profile,
                    $this->difference === 'binding' ? new BindingExpectation('a-resource', '1', '01') : $current->binding,
                    $this->difference === 'extension' ? [new ExtensionRef('test:workspace', 1, false, ['id' => 'w'])] : $current->extensions,
                )), $resolved->facets);
            }
        });
        expect(fn () => $this->app->make('tenant.storage'))->toThrow(BindingMismatch::class);
        $this->app->instance(ContextResolver::class, $original);
    });
})->with(['mode', 'identity', 'profile', 'binding', 'extension']);

it('accepts equivalent extension reference maps regardless of key insertion order', function (): void {
    $runtime = $this->app->make(TenantRuntime::class);
    $original = $this->app->make(ContextResolver::class);
    $selection = Selection::tenant(new IsolationIdentity('test', 'a'), new ProfileRef('laravel-scoped', 1), [
        new ExtensionRef('test:workspace', 1, false, ['id' => '01', 'revision' => '1']),
    ]);
    $runtime->run($selection, new Purpose('test:descriptor'), function () use ($original): void {
        $this->app->instance(ContextResolver::class, new class($original) implements ContextResolver
        {
            public function __construct(private readonly ContextResolver $original) {}

            public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
            {
                return $this->original->resolve(Selection::tenant($selection->identity, $selection->expectedProfile, [
                    new ExtensionRef('test:workspace', 1, false, ['revision' => '1', 'id' => '01']),
                ], $selection->expectedBinding), $purpose);
            }
        });
        expect($this->app->make('tenant.storage'))->toBeInstanceOf(ResourceStorage::class);
        $this->app->instance(ContextResolver::class, $original);
    });
});
