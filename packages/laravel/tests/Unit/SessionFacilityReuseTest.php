<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
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
use Tenant\Laravel\Data\ScopeDescriptor;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;
use Tenant\Laravel\Internal\Facilities\GuardedSessionHandler;
use Tenant\Laravel\Internal\Facilities\SessionFacility;
use Tenant\Laravel\Internal\Facilities\SessionGeneration;

it('does not chmod a session directory that is already mode 0700 and reuses its handler', function (): void {
    $base = sessionReuseBase();
    $sessionRoot = $base.'/sessions';
    try {
        mkdir($sessionRoot, 0700);
        chmod($sessionRoot, 0700);
        ['facility' => $facility, 'repository' => $repository, 'store' => $store, 'handler' => $handler] = sessionReuseHarness($sessionRoot);
        $phase = sessionReusePhase($base, $sessionRoot);
        $state = sessionReuseFrame();
        $facility->prepare($phase, $state);
        sessionReuseWaitForClock();
        clearstatcache(true, $sessionRoot);
        $created = filectime($sessionRoot);

        $facility->activate($phase, $state);

        clearstatcache(true, $sessionRoot);
        $current = $store->getHandler();
        if (! $current instanceof GuardedSessionHandler) {
            throw new RuntimeException('session handler was not guarded');
        }

        expect(filectime($sessionRoot))->toBe($created)
            ->and(sessionReuseMode($sessionRoot))->toBe(0700)
            ->and($current->delegate)->toBe($handler)
            ->and($repository->get('session.files'))->toBe($sessionRoot)
            ->and(sessionReuseFilePath($current))->toBe($sessionRoot);
    } finally {
        sessionReuseRemove($base);
    }
});

it('chmods a session directory that is not already mode 0700 without rebuilding the handler', function (): void {
    $base = sessionReuseBase();
    $sessionRoot = $base.'/sessions';
    try {
        mkdir($sessionRoot, 0700);
        chmod($sessionRoot, 0755);
        ['facility' => $facility, 'store' => $store, 'handler' => $handler] = sessionReuseHarness($sessionRoot);
        $state = sessionReuseFrame();
        $phase = sessionReusePhase($base, $sessionRoot);
        $facility->prepare($phase, $state);
        $facility->activate($phase, $state);
        $current = $store->getHandler();
        if (! $current instanceof GuardedSessionHandler) {
            throw new RuntimeException('session handler was not guarded');
        }

        expect(sessionReuseMode($sessionRoot))->toBe(0700)
            ->and($current->delegate)->toBe($handler);
    } finally {
        sessionReuseRemove($base);
    }
});

it('builds a new file handler when the session root changes', function (): void {
    $base = sessionReuseBase();
    $first = $base.'/sessions-a';
    $second = $base.'/sessions-b';
    try {
        mkdir($first, 0700);
        chmod($first, 0700);
        ['facility' => $facility, 'repository' => $repository, 'store' => $store, 'handler' => $handler] = sessionReuseHarness($first);
        $firstPhase = sessionReusePhase($base, $first);
        $firstState = sessionReuseFrame();
        $facility->prepare($firstPhase, $firstState);
        $facility->activate($firstPhase, $firstState);
        $secondPhase = sessionReusePhase($base, $second);
        $secondState = sessionReuseFrame();
        $facility->prepare($secondPhase, $secondState);
        $facility->activate($secondPhase, $secondState);
        $current = $store->getHandler();
        if (! $current instanceof GuardedSessionHandler) {
            throw new RuntimeException('session handler was not guarded');
        }

        expect($current->delegate)->not->toBe($handler)
            ->and($current->delegate)->toBeInstanceOf(FileSessionHandler::class)
            ->and(sessionReuseFilePath($current))->toBe($second)
            ->and($repository->get('session.files'))->toBe($second)
            ->and(sessionReuseMode($second))->toBe(0700);
    } finally {
        sessionReuseRemove($base);
    }
});

it('builds a new file handler when the session prefix or store changes', function (): void {
    $base = sessionReuseBase();
    $sessionRoot = $base.'/sessions';
    try {
        mkdir($sessionRoot, 0700);
        chmod($sessionRoot, 0700);
        ['facility' => $facility, 'repository' => $repository, 'store' => $store, 'handler' => $handler] = sessionReuseHarness($sessionRoot);
        $phase = sessionReusePhase($base, $sessionRoot);
        $prefixed = sessionReuseFrame();
        $facility->prepare($phase, $prefixed);
        $repository->set('session.prefix', 'tenant-prefix');
        $facility->activate($phase, $prefixed);
        $afterPrefix = $store->getHandler();
        if (! $afterPrefix instanceof GuardedSessionHandler) {
            throw new RuntimeException('session handler was not guarded');
        }

        expect($afterPrefix->delegate)->not->toBe($handler)
            ->and(sessionReuseFilePath($afterPrefix))->toBe($sessionRoot);

        $stored = sessionReuseFrame();
        $facility->prepare($phase, $stored);
        $repository->set('session.store', 'other');
        $facility->activate($phase, $stored);
        $afterStore = $store->getHandler();
        if (! $afterStore instanceof GuardedSessionHandler) {
            throw new RuntimeException('session handler was not guarded');
        }

        expect($afterStore->delegate)->not->toBe($afterPrefix->delegate)
            ->and(sessionReuseFilePath($afterStore))->toBe($sessionRoot);
    } finally {
        sessionReuseRemove($base);
    }
});

it('refuses a symlinked session root or ancestor before creating the directory', function (): void {
    $base = sessionReuseBase();
    $live = $base.'/live';
    try {
        mkdir($live, 0700);
        mkdir($base.'/real', 0700);
        expect(symlink($base.'/real', $base.'/via'))->toBeTrue()
            ->and(symlink($live, $base.'/linked-root'))->toBeTrue();
        ['facility' => $facility, 'store' => $store, 'handler' => $handler] = sessionReuseHarness($live);
        $ancestor = sessionReusePhase($base, $base.'/via/sessions');
        $ancestorState = sessionReuseFrame();
        $facility->prepare($ancestor, $ancestorState);

        expect(fn () => $facility->activate($ancestor, $ancestorState))
            ->toThrow(RuntimeException::class, 'Storage roots must not contain symbolic links.')
            ->and(is_dir($base.'/real/sessions'))->toBeFalse()
            ->and($store->getHandler())->toBe($handler);

        $linked = sessionReusePhase($base, $base.'/linked-root');
        $linkedState = sessionReuseFrame();
        $facility->prepare($linked, $linkedState);

        expect(fn () => $facility->activate($linked, $linkedState))
            ->toThrow(RuntimeException::class, 'Storage roots must not contain symbolic links.')
            ->and($store->getHandler())->toBe($handler);
    } finally {
        sessionReuseRemove($base);
    }
});

function sessionReusePhase(string $base, string $sessionRoot): PhaseContext
{
    $resolved = new ResolvedIsolation(ContextState::active(new IsolationContext(
        'tenant',
        new IsolationIdentity('host', 'tenant-a'),
        new ProfileRef('laravel-scoped', 1),
        new BindingExpectation('resource:a', 'incarnation:1', 'route:1'),
    )), new FacetBindings(['tenant:resource' => new ScopeDescriptor('tenant:a:', $base.'/durable', $base.'/temporary', $sessionRoot)]));

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

/**
 * @return array{facility: SessionFacility, repository: Repository, store: Store, handler: FileSessionHandler}
 */
function sessionReuseHarness(string $sessionFiles): array
{
    $repository = new Repository([
        'session' => [
            'driver' => 'file',
            'files' => $sessionFiles,
            'lifetime' => 120,
            'cookie' => 'tenant_test',
            'encrypt' => false,
        ],
        'cache' => ['default' => 'array'],
    ]);
    $app = new Container;
    $app->instance('config', $repository);
    $app->instance('files', new Filesystem);
    $sessions = new SessionManager($app);
    $store = $sessions->driver();
    $handler = $store->getHandler();
    if (! $store instanceof Store || ! $handler instanceof FileSessionHandler) {
        throw new RuntimeException('expected a file session store');
    }

    return [
        'facility' => new SessionFacility(
            $sessions,
            new CacheManager($app),
            $app,
            new FrameworkConfig(static fn (): Repository => $repository),
            new SessionGeneration(static function (): never {
                throw new LogicException('session runtime is not used');
            }),
        ),
        'repository' => $repository,
        'store' => $store,
        'handler' => $handler,
    ];
}

function sessionReuseFrame(): FrameState
{
    return new class implements FrameState
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
}

function sessionReuseFilePath(SessionHandlerInterface $handler): string
{
    $delegate = $handler instanceof GuardedSessionHandler ? $handler->delegate : $handler;
    if (! $delegate instanceof FileSessionHandler) {
        throw new RuntimeException('expected a file session handler');
    }
    $path = new ReflectionProperty(FileSessionHandler::class, 'path')->getValue($delegate);
    if (! is_string($path)) {
        throw new RuntimeException('expected a file session path');
    }

    return $path;
}

function sessionReuseMode(string $path): int
{
    $mode = fileperms($path);
    if (! is_int($mode)) {
        throw new RuntimeException('expected a session directory mode');
    }

    return $mode & 0777;
}

function sessionReuseWaitForClock(): void
{
    $start = time();
    $deadline = microtime(true) + 2.5;
    while (time() === $start) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('clock did not advance');
        }
        usleep(10000);
    }
}

function sessionReuseBase(): string
{
    $base = sys_get_temp_dir().'/tenant-session-reuse-'.bin2hex(random_bytes(6));
    if (! mkdir($base, 0700) && ! is_dir($base)) {
        throw new RuntimeException('temp directory unavailable');
    }

    return $base;
}

function sessionReuseRemove(string $path): void
{
    if (is_link($path)) {
        unlink($path);

        return;
    }

    if (is_file($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        sessionReuseRemove($path.DIRECTORY_SEPARATOR.$entry);
    }

    rmdir($path);
}
