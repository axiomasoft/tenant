<?php

declare(strict_types=1);

namespace TenantLab;

use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\{Selection, IsolationIdentity, ProfileRef, Purpose};
use Tenant\Laravel\TenantServiceProvider;

final class LabHost
{
    public readonly Application $app;
    public readonly LabBindingOwner $owner;
    public readonly TenantRuntime $runtime;
    public function __construct(public readonly array $manifest, string $session = 'file', ?\Closure $compose = null, ?\Closure $decorate = null, array $observers = [], ?\Tenant\Contracts\DiagnosticSink $diagnostics = null, ?\Closure $queueSetup = null)
    {
        $credentials = json_decode(file_get_contents($manifest['credentials']), true, flags: JSON_THROW_ON_ERROR);
        $this->owner = new LabBindingOwner($manifest, $credentials);
        foreach ($manifest['databases'] as $label => $db) {
            if ($db !== 'tenant_'.$manifest['runId'].'_'.$label.'_test') throw new \RuntimeException('Isolation guard: unexpected DB; recreate lab from manifest');
        }
        if (!str_starts_with($manifest['root'], '/tmp/tenant_lab_') || is_link($manifest['root'])) throw new \RuntimeException('Isolation guard: unexpected filesystem root');
        $app = $this->app = new Application($manifest['root'].'/app');
        $app->instance('env', 'testing');
        $config = new Repository([
            'app' => ['name' => 'Tenant lab', 'env' => 'testing', 'key' => 'base64:'.base64_encode(str_repeat('x',32)), 'cipher' => 'AES-256-CBC', 'debug' => false, 'timezone' => 'UTC'],
            'database' => ['default' => 'central', 'connections' => array_fill_keys(['central', 'identity', 'billing', 'broker', 'jobs', 'failed_jobs'], $this->owner->route('central')), 'redis' => ['client' => 'predis', 'options' => ['prefix' => ''], 'default' => ['host' => '127.0.0.1', 'port' => $manifest['ports']['redis'], 'database' => 0]]],
            'cache' => ['default' => 'tenant', 'prefix' => '', 'stores' => ['tenant' => ['driver' => 'redis', 'connection' => 'default', 'lock_connection' => 'default', 'prefix' => 'baseline:'], 'central' => ['driver' => 'redis', 'connection' => 'default', 'prefix' => $manifest['project'].':central:'], 'sessions' => ['driver' => 'redis', 'connection' => 'default', 'prefix' => $manifest['project'].':sessions:']]],
            'session' => ['driver' => $session, 'files' => $manifest['root'].'/central-sessions', 'store' => $session === 'file' ? null : 'sessions', 'connection' => 'default', 'prefix' => 'baseline-session:', 'lifetime' => 120, 'expire_on_close' => false, 'encrypt' => false, 'serialization' => 'php', 'cookie' => 'tenant_session', 'path' => '/', 'domain' => null, 'secure' => false, 'http_only' => true, 'same_site' => 'lax', 'lottery' => [0,100], 'table' => 'sessions'],
            'filesystems' => ['default' => 'local', 'disks' => []],
            'tenant' => ['central_connection' => 'central', 'cache_stores' => ['tenant'], 'central_cache_stores' => ['central'], 'facilities' => ['database' => true, 'cache' => true, 'session' => true, 'storage' => true]],
            'auth' => ['defaults' => ['guard' => 'lab'], 'guards' => ['lab' => ['driver' => 'lab']]],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]],
        ]);
        $app->instance('config', $config);
        foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Auth\AuthServiceProvider::class, \Illuminate\Log\LogServiceProvider::class, \Illuminate\Database\DatabaseServiceProvider::class, \Illuminate\Redis\RedisServiceProvider::class, \Illuminate\Cache\CacheServiceProvider::class, \Illuminate\Filesystem\FilesystemServiceProvider::class, \Illuminate\Session\SessionServiceProvider::class, \Illuminate\Routing\RoutingServiceProvider::class] as $provider) $app->register($provider);
        $decoder=new class implements \Tenant\Contracts\ExtensionDecoder {
            public function validate(\Tenant\Data\ExtensionRef $r, \Tenant\Contracts\PhaseContext $c, Purpose $p): void {
                if(array_keys($r->refs)!==['selection']) throw new \Tenant\Exceptions\SelectionLost();
            }
        };
        $app->instance('tenant.services', [['id'=>'lab:product-decoder','slot'=>'tenant:extension/lab:product/1','spiVersion'=>1,'service'=>$decoder,'capabilities'=>[]]]);
        $app->instance(ContextResolver::class, $this->owner);
        $app->instance(BindingVerifier::class, $this->owner);
        if ($compose !== null) $app->instance('tenant.compose', $compose);
        if ($decorate !== null) $app->instance('tenant.decorate', $decorate);
        $app->instance('tenant.observers', $observers);
        if ($diagnostics !== null) $app->instance('tenant.diagnostics', $diagnostics);
        $app->register(TenantServiceProvider::class);
        // Host registration may follow auto-discovered adapter registration, before boot.
        if ($queueSetup !== null) $queueSetup($this);
        $app->boot();
        $this->runtime = $app->make(TenantRuntime::class);
    }
    public function selection(string $id = 'a'): Selection { return Selection::tenant(new IsolationIdentity('lab', $id), new ProfileRef('laravel-pg-database', 1)); }
    public function central(): Selection { return Selection::central(new ProfileRef('laravel-pg-database',1)); }
    public function purpose(): Purpose { return new Purpose('lab:test'); }
    public function sql(string $label, string $query): array
    {
        $connection = (new \Illuminate\Database\Connectors\ConnectionFactory($this->app))->make($this->owner->route($label));
        return $connection->select($query);
    }
    public function db(): \Illuminate\Database\Connection { return $this->app['db']->connection(); }
    public function cache(): \Illuminate\Contracts\Cache\Repository { return $this->app['cache']->store('tenant'); }
    public function storage(): \Tenant\Laravel\Internal\Storage\LocalStorage { return $this->app->make('tenant.storage'); }
}
