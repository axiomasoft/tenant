<?php

declare(strict_types=1);

namespace TenantVaulter;

use Illuminate\Database\Eloquent\Model;
use Tenant\Contracts\{ContextResolver, BindingVerifier, TenantRuntime};
use Tenant\Data\{Selection, Purpose, ResolvedIsolation, IsolationContext, ContextState, FacetBindings, BindingExpectation, BindingVerificationInput, VerifiedBinding, IsolationIdentity, ProfileRef};
use Tenant\Laravel\Queue\{QueueRegistry, ContextDispatcher, ScalarJob};
use Vaulter\Tenant\Jobs\TenantJobPublisher;
use Vaulter\Tenant\TenantResolver;

require_once dirname(__DIR__, 2).'/laravel/Support/bootstrap.php';

if (getenv('VAULTER_USE_SOURCE') === '1') {
    $loader = new \Composer\Autoload\ClassLoader;
    foreach (['Vaulter\\Tenant\\' => 'tenant/src', 'Vaulter\\Drive\\' => 'drive/src', 'Vaulter\\Media\\' => 'media/src', 'Vaulter\\' => 'core/src'] as $prefix => $path) {
        $loader->addPsr4($prefix, getenv('VAULTER_CONSUMER_CHECKOUT').'/packages/'.$path);
    }
    $loader->register(true);
}

final class Owner implements ContextResolver, BindingVerifier
{
    public bool $available = true;
    public string $routeRevision = 'route:1';
    public function __construct(private \TenantLab\LabBindingOwner $physical) {}
    public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
    {
        if (!$this->available) throw new \Tenant\Exceptions\ContextDenied;
        if ($selection->mode === 'central') return new ResolvedIsolation(ContextState::central(), new FacetBindings([]));
        $id = $selection->identity;
        if ($id?->id !== 'same' || !in_array($id->namespace, ['alpha', 'beta'], true)) throw new \Tenant\Exceptions\ContextDenied;
        $label = $id->namespace === 'alpha' ? 'a' : 'b';
        $binding = new BindingExpectation('resource:'.$label, 'incarnation:'.$label, $this->routeRevision);
        return new ResolvedIsolation(ContextState::active(new IsolationContext($selection->mode, $id, $selection->expectedProfile, $binding, $selection->extensions)),
            new FacetBindings(['tenant:database' => $this->physical->descriptor($label), 'tenant:binding-verification' => $this->physical->descriptor($label)]));
    }
    public function verify(BindingVerificationInput $input): VerifiedBinding { return $this->physical->verify($input); }
}

final class HostRecord extends Model
{
    protected $table = 'host_owners';
    protected $connection = 'central';
    protected $guarded = [];
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
}
final class HostUser extends \Illuminate\Foundation\Auth\User
{
    protected $connection = 'central';
    protected $table = 'host_users';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];
}
final class HostMapping implements \Vaulter\Tenant\Contracts\OwnerMapping
{
    public int $reads = 0;
    public function identity(Model $owner): IsolationIdentity
    {
        $this->reads++;
        if (!$owner instanceof HostRecord || !$owner->exists || $owner->getKey() !== $owner->getRawOriginal('id')) throw new \Tenant\Exceptions\ContextDenied;
        $row = HostRecord::query()->find($owner->getRawOriginal('id'));
        if ($row === null) throw new \Tenant\Exceptions\ContextDenied;
        return new IsolationIdentity($row->namespace, $row->isolation_id);
    }
}
final class Scanner implements \Vaulter\Media\Contracts\Cas\ContentScanner
{
    public function key(): string { return 'fixture'; }
    public function version(): string { return '1'; }
    public function scan(\Vaulter\Media\Content\ScanInput $input): \Vaulter\Media\Content\ScanResult
    {
        $app = app(); $state = $app->make(TenantRuntime::class)->current();
        $bytes = stream_get_contents($input->stream);
        $db = $app['db']->connection('central');
        $db->table('scan_reads')->insert(['namespace' => $state->context->identity->namespace, 'bytes' => $bytes, 'drive_id' => (string) $input->driveId, 'pid' => getmypid()]);
        echo 'SCANNER '.json_encode(['namespace' => $state->context->identity->namespace, 'bytes' => $bytes, 'pid' => getmypid()]).PHP_EOL;
        return new \Vaulter\Media\Content\ScanResult(\Vaulter\Media\Enums\Cas\ScanVerdict::Clean);
    }
}
final class Errors extends \Illuminate\Foundation\Exceptions\Handler
{
    public function report(\Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL); }
}

final class Host
{
    public readonly \TenantLab\LabHost $lab;
    public readonly \Illuminate\Foundation\Application $app;
    public readonly TenantRuntime $runtime;
    public readonly Owner $owner;
    public function __construct(public array $manifest, public bool $setup = false, public string $database = 'a', public string $session = 'file', public array $overrides = [])
    {
        foreach (['app/bootstrap/cache', 'app/storage/framework/views', 'app/storage/framework/sessions', 'app/storage/logs', 'central-sessions'] as $path) {
            if (!is_dir($manifest['root'].'/'.$path)) mkdir($manifest['root'].'/'.$path, 0700, true);
        }
        $this->lab = new \TenantLab\LabHost($manifest, $session,
            $setup ? null : static fn ($builder, $app) => \Vaulter\Tenant\Storage\StorageFacility::compose($builder, $app),
            queueSetup: function (\TenantLab\LabHost $lab) use ($setup, $database): void {
                $app = $lab->app;
                \Illuminate\Container\Container::setInstance($app);
                \Illuminate\Support\Facades\Facade::clearResolvedInstances();
                \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
                $app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, Errors::class);
                $app->singleton(\Illuminate\Contracts\Http\Kernel::class, \Illuminate\Foundation\Http\Kernel::class);
                $app->singleton(\Illuminate\Contracts\Console\Kernel::class, \Illuminate\Foundation\Console\Kernel::class);
                foreach ([\Illuminate\Bus\BusServiceProvider::class, \Illuminate\Hashing\HashServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class, \Illuminate\Validation\ValidationServiceProvider::class, \Illuminate\View\ViewServiceProvider::class] as $provider) $app->register($provider);
                $config = $app['config'];
                $config->set('cache.limiter', 'central');
                $app['events']->listen(\Illuminate\Log\Events\MessageLogged::class, static function ($e): void { if ($e->level === 'warning') echo 'DIAGNOSTIC '.json_encode([$e->message,$e->context]).PHP_EOL; });
                $config->set('queue', ['default' => 'database', 'connections' => [
                    'sync' => ['driver' => 'sync', 'after_commit' => false],
                    'database' => ['driver' => 'database', 'connection' => 'central', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 180, 'after_commit' => false],
                ], 'failed' => ['driver' => 'database-uuids', 'database' => 'central', 'table' => 'failed_jobs']]);
                $app->register(\Illuminate\Queue\QueueServiceProvider::class);
                $config->set('vaulter', ['tenancy' => ['enabled' => true, 'resolver' => $setup ? \Vaulter\Testing\Tenancy\MutableTenantResolver::class : TenantResolver::class],
                    'ids' => ['strategy' => 'bigint', 'host_keys' => 'string'], 'database' => ['connection' => $setup ? 'setup' : 'tenant', 'write_guard' => true], 'schedule' => ['enabled' => false]]);
                $config->set('vaulter-drive', ['scan' => ['mode' => 'all_content', 'scanner' => Scanner::class, 'scanner_key' => 'fixture', 'scanner_version' => '1'],
                    'uploads' => ['scan' => ['queue' => 'default']], 'access' => ['permission_driver' => 'database'], 'schedule' => ['enabled' => false]]);
                $config->set('vaulter-media', ['disk' => 'local', 'dedup' => ['scope' => 'tenant'], 'schedule' => ['enabled' => false]]);
                $config->set('filesystems.disks.local', ['driver' => $setup ? 'local' : 'vaulter-tenant', 'root' => $this->manifest['root'].'/setup-files', 'throw' => true]);
                $config->set('vaulter-tenant', ['schema_connection' => 'schema', 'tenant_columns' => ['v_drives' => 'tenant_id', 'v_nodes' => 'tenant_id', 'v_blobs' => 'tenant_id'], 'queues' => ['default']]);
                $credentials = json_decode(file_get_contents($this->manifest['credentials']), true, flags: JSON_THROW_ON_ERROR);
                $route = $lab->owner->route($database);
                $config->set('database.connections.schema', $route);
                $route['username'] = $this->manifest['admin']; $route['password'] = $credentials['adminPassword'];
                $config->set('database.connections.setup', $route);
                $app->register(\Vaulter\VaulterServiceProvider::class);
                $app->register(\Vaulter\Media\VaulterMediaServiceProvider::class);
                $app->register(\Vaulter\Drive\VaulterDriveServiceProvider::class);
                if (!$setup) {
                    $this->owner = new Owner($lab->owner);
                    $app->instance(ContextResolver::class, $this->owner); $app->instance(BindingVerifier::class, $this->owner);
                    $app->singleton(\Vaulter\Tenant\Contracts\OwnerMapping::class, HostMapping::class);
                    $app->register(\Vaulter\Tenant\TenantServiceProvider::class);
                    $app->instance(QueueRegistry::class, new QueueRegistry(['alpha','beta'], ['laravel-pg-database' => ['version' => 1, 'modes' => ['tenant', 'central']]], [],
                        [TenantJobPublisher::HANDLER => TenantJobPublisher::handler($app),
                         'host:central' => ['producer' => 'host:producer', 'purposes' => ['host:central'], 'extensions' => [], 'admit' => static function (): void {}, 'handle' => function (): void { app('db')->connection('central')->table('probes')->updateOrInsert(['name'=>'central-job'],['value'=>'central']); }]],
                        hash('sha256', file_get_contents($this->manifest['credentials'])), new ProfileRef('laravel-pg-database', 1)));
                }
                foreach ($this->overrides as $key => $value) $config->set($key, $value);
            });
        $this->app = $this->lab->app; $this->runtime = $this->lab->runtime;
        Model::setConnectionResolver($this->app['db']);
        Model::setEventDispatcher($this->app['events']);
    }
    public function selection(string $namespace = 'alpha'): Selection { return Selection::tenant(new IsolationIdentity($namespace, 'same'), new ProfileRef('laravel-pg-database',1)); }
    public function run(string $namespace, \Closure $callback): mixed { return $this->runtime->run($this->selection($namespace), new Purpose('vaulter:test'), $callback); }
    public function worker(): void
    {
        echo 'WORKER pid='.getmypid().PHP_EOL;
        $this->app['events']->listen(\Illuminate\Queue\Events\JobProcessed::class, function ($e): void { echo 'PROCESSED id='.$e->job->getJobId().' state='.$this->runtime->current()->kind.PHP_EOL; });
        $this->app['events']->listen(\Illuminate\Queue\Events\JobFailed::class, function ($e): void {
            $this->app['queue.failer']->log($e->connectionName, $e->job->getQueue(), $e->job->getRawBody(), $e->exception);
            echo 'FAILED '.get_class($e->exception).' '.$e->exception->getMessage().PHP_EOL;
        });
        $worker = new \Illuminate\Queue\Worker($this->app['queue'], $this->app['events'], $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class), static fn () => false);
        $worker->setCache($this->app['cache']->store('central'));
        for ($i = 0; $i < 30 && $this->app['db']->connection('central')->table('jobs')->where('queue','default')->exists(); $i++) {
            $worker->runNextJob('database', 'default', new \Illuminate\Queue\WorkerOptions(sleep:0, maxTries:1, backoff:0));
        }
        if ($this->app['db']->connection('central')->table('jobs')->where('queue','default')->exists()) throw new \RuntimeException('Worker did not drain queue.');
    }
}
