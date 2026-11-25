<?php

declare(strict_types=1);

namespace Tenant\Laravel\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use LogicException;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Context\Purpose;
use Tenant\Exceptions\Context\ScopeRequired;

uses(ScopedLaravelTestCase::class);

/**
 * A host fixture owns these row predicates, stamps, and model write checks.
 * The scoped runtime supplies context; it does not add Eloquent or raw SQL guards.
 */
final class HostScopedRecord extends Model
{
    protected $connection = 'sqlite';

    protected $table = 'host_records';

    protected $guarded = [];

    public $timestamps = false;

    protected static function booted(): void
    {
        // Evaluate the scope when Eloquent applies it, not when a query template is built.
        self::addGlobalScope('host-tenant', static function (Builder $query): void {
            $query->where($query->qualifyColumn('tenant_id'), self::currentTenant());
        });

        self::saving(static function (self $record): void {
            if (! $record->exists) {
                $record->setAttribute('tenant_id', self::currentTenant());

                return;
            }

            $record->assertCurrentOwner();
        });

        self::deleting(static function (self $record): void {
            $record->assertCurrentOwner();
        });
    }

    private static function currentTenant(): string
    {
        $current = Container::getInstance()->make(TenantRuntime::class)->current();
        if ($current->kind !== 'active' || $current->context?->identity->namespace !== 'test') {
            throw new ScopeRequired;
        }

        return $current->context->identity->id;
    }

    private function assertCurrentOwner(): void
    {
        $tenant = self::currentTenant();
        if ($this->getRawOriginal('tenant_id') !== $tenant || $this->getAttribute('tenant_id') !== $tenant) {
            throw new LogicException('The host model belongs to a different tenant.');
        }
    }
}

beforeEach(function (): void {
    $database = $this->app->make(DatabaseManager::class);
    $database->connection('sqlite')->getSchemaBuilder()->create('host_records', static function (Blueprint $table): void {
        $table->id();
        $table->string('tenant_id');
        $table->string('label');
        $table->string('value');
        $table->unique(['tenant_id', 'label']);
    });
    $database->connection('sqlite')->table('host_records')->insert([
        ['tenant_id' => 'a', 'label' => 'shared', 'value' => 'from-a'],
        ['tenant_id' => 'b', 'label' => 'shared', 'value' => 'from-b'],
    ]);
    $database->connection('central')->getSchemaBuilder()->create('authority_records', static function (Blueprint $table): void {
        $table->id();
        $table->string('value');
    });
});

it('supplies and restores context for host-owned Eloquent row scopes and stamps', function (): void {
    $runtime = $this->app->make(TenantRuntime::class);
    $purpose = new Purpose('host:eloquent');

    $runtime->run($this->selection('a'), $purpose, function () use ($runtime, $purpose): void {
        expect(HostScopedRecord::query()->pluck('value')->all())->toBe(['from-a']);

        $created = HostScopedRecord::query()->create(['tenant_id' => 'b', 'label' => 'created', 'value' => 'new-a']);
        expect($created->getAttribute('tenant_id'))->toBe('a');

        $runtime->run($this->selection('b'), $purpose, static function (): void {
            expect(HostScopedRecord::query()->pluck('value')->all())->toBe(['from-b']);
            HostScopedRecord::query()->create(['label' => 'created', 'value' => 'new-b']);
        });

        expect(HostScopedRecord::query()->orderBy('id')->pluck('value')->all())->toBe(['from-a', 'new-a']);
    });

    expect($runtime->current()->kind)->toBe('missing');
    $runtime->run($this->selection('b'), $purpose, static function (): void {
        expect(HostScopedRecord::query()->orderBy('id')->pluck('value')->all())->toBe(['from-b', 'new-b']);
    });
});

it('lets a reusable Eloquent query template evaluate the host scope for each current context', function (): void {
    $runtime = $this->app->make(TenantRuntime::class);
    $purpose = new Purpose('host:eloquent');
    $query = $runtime->run($this->selection('a'), $purpose, static function (): Builder {
        $query = HostScopedRecord::query()->where('label', 'shared');
        expect($query->get()->pluck('value')->all())->toBe(['from-a']);

        return $query;
    });

    // A raw Query\Builder or an already applied predicate is outside this fixture's contract.
    expect(fn () => $query->get())->toThrow(ScopeRequired::class);
    foreach (['b' => 'from-b', 'a' => 'from-a'] as $tenant => $value) {
        $runtime->run($this->selection($tenant), $purpose, static function () use ($query, $value): void {
            expect($query->get()->pluck('value')->all())->toBe([$value]);
        });
    }
});

it('uses the host model write guard to reject a retained model in another tenant', function (): void {
    $runtime = $this->app->make(TenantRuntime::class);
    $purpose = new Purpose('host:eloquent');
    $record = $runtime->run($this->selection('a'), $purpose, static fn (): Model => HostScopedRecord::query()->firstOrFail());

    $runtime->run($this->selection('b'), $purpose, static function () use ($record): void {
        $record->setAttribute('value', 'cross-tenant');
        expect(fn () => $record->save())->toThrow(LogicException::class, 'different tenant')
            ->and(fn () => $record->delete())->toThrow(LogicException::class, 'different tenant')
            ->and(HostScopedRecord::query()->pluck('value')->all())->toBe(['from-b']);
    });

    $runtime->run($this->selection('a'), $purpose, static function (): void {
        expect(HostScopedRecord::query()->pluck('value')->all())->toBe(['from-a']);
    });
});

it('lets the host model refuse missing and central context without closing the shared database', function (): void {
    $runtime = $this->app->make(TenantRuntime::class);
    $assertRefused = static function (): void {
        expect(fn () => HostScopedRecord::query()->get())->toThrow(ScopeRequired::class)
            ->and(fn () => HostScopedRecord::query()->create(['label' => 'invalid', 'value' => 'invalid']))->toThrow(ScopeRequired::class);
    };

    $assertRefused();
    $runtime->run($this->selection('central'), new Purpose('host:eloquent'), $assertRefused);
    $assertRefused();

    // Shared-mode SQL remains host-owned. Its availability is not evidence of row isolation.
    expect($this->app->make(DatabaseManager::class)->connection('sqlite')->table('host_records')->count())->toBe(2);
});

it('preserves host and named central connections without creating a tenant database route', function (): void {
    $runtime = $this->app->make(TenantRuntime::class);
    $database = $this->app->make(DatabaseManager::class);
    $config = $this->app->make(Repository::class);
    $default = $database->getDefaultConnection();
    $connections = $config->get('database.connections');
    $host = $database->connection('sqlite');
    $central = $database->connection('central');

    expect($this->app->bound(BindingVerifier::class))->toBeFalse()
        ->and($config->has('database.connections.tenant'))->toBeFalse();

    foreach (['a', 'b', 'central', 'a'] as $key) {
        $runtime->run($this->selection($key), new Purpose('host:authority'), function () use ($database, $config, $default, $connections, $host, $central, $key): void {
            expect($database->getDefaultConnection())->toBe($default)
                ->and($database->connection('sqlite'))->toBe($host)
                ->and($database->connection('central'))->toBe($central)
                ->and($config->get('database.connections'))->toBe($connections)
                ->and($config->has('database.connections.tenant'))->toBeFalse();

            $central->table('authority_records')->insert(['value' => $key]);
        });
    }

    expect($runtime->current()->kind)->toBe('missing')
        ->and($database->getDefaultConnection())->toBe($default)
        ->and($database->connection('central'))->toBe($central)
        ->and($central->table('authority_records')->orderBy('id')->pluck('value')->all())->toBe(['a', 'b', 'central', 'a'])
        ->and($config->get('database.connections'))->toBe($connections)
        ->and($config->has('database.connections.tenant'))->toBeFalse();
});
