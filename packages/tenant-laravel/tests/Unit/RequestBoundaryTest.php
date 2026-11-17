<?php

declare(strict_types=1);

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\GenericUser;
use Illuminate\Auth\RequestGuard;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Tenant\Data\Context\Selection;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Http\ContextRequestBoundary;
use TenantTests\RuntimeRig;

beforeEach(function (): void {
    $this->rig = new RuntimeRig(ids: []);
    $this->container = new Container;
    $this->container->instance('config', new Repository([
        'session' => ['driver' => 'array', 'cookie' => 'tenant_test', 'lifetime' => 120],
        'auth' => ['defaults' => ['guard' => 'test'], 'guards' => ['test' => ['driver' => 'test']]],
    ]));
    $this->sessions = new SessionManager($this->container);
    $this->container->instance('session', $this->sessions);
    $this->auth = new AuthManager($this->container);
    $this->auth->extend('test', static fn (): RequestGuard => new RequestGuard(static fn (): null => null, Request::create('/')));
    $this->container->instance('auth', $this->auth);
    $this->kernel = $this->createMock(Kernel::class);
});

it('clears resident authentication and session data before even a refused host selection', function (): void {
    $store = $this->sessions->driver();
    $store->put('login', 'previous-user');
    $this->auth->guard()->setUser(new GenericUser(['id' => 'previous-user']));
    $select = function (): never {
        expect($this->sessions->driver()->all())->toBe([])
            ->and($this->auth->guard()->user())->toBeNull();
        $this->sessions->driver()->put('selection-state', 'discard');
        $this->auth->guard()->setUser(new GenericUser(['id' => 'selection-user']));
        throw new RuntimeException('untrusted host');
    };
    $boundary = new ContextRequestBoundary($this->rig->runtime, $this->container, $select);
    $this->kernel->expects($this->never())->method('handle');

    expect(fn (): Response => $boundary->handle(Request::create('/'), $this->kernel))
        ->toThrow(RuntimeException::class, 'untrusted host')
        ->and($store->all())->toBe([])
        ->and($this->auth->guard()->user())->toBeNull()
        ->and($this->rig->runtime->current()->kind)->toBe('missing');
});

it('clears resident state when activation refuses the selected context', function (): void {
    $this->rig->resolver->denied['A'] = true;
    $select = function (): Selection {
        $this->sessions->driver()->put('selection-state', 'discard');
        $this->auth->guard()->setUser(new GenericUser(['id' => 'selection-user']));

        return RuntimeRig::selection('A');
    };
    $boundary = new ContextRequestBoundary($this->rig->runtime, $this->container, $select);
    $this->kernel->expects($this->never())->method('handle');

    expect(fn (): Response => $boundary->handle(Request::create('/'), $this->kernel))
        ->toThrow(TenantFailure::class)
        ->and($this->sessions->driver()->all())->toBe([])
        ->and($this->auth->guard()->user())->toBeNull()
        ->and($this->rig->runtime->current()->kind)->toBe('missing');
});

it('preserves buffered response handling and cleans up after termination failures', function (bool $fails): void {
    $response = new Response('buffered');
    $boundary = new ContextRequestBoundary($this->rig->runtime, $this->container, static fn (): Selection => RuntimeRig::selection('A'));
    $this->kernel->expects($this->once())->method('handle')->willReturn($response);
    $this->kernel->expects($this->once())->method('terminate')->willReturnCallback(function () use ($fails): void {
        expect($this->rig->runtime->current()->context?->identity->id)->toBe('A');
        $this->sessions->driver()->put('during-termination', 'discard');
        if ($fails) {
            throw new RuntimeException('termination failed');
        }
    });

    if ($fails) {
        expect(fn (): Response => $boundary->handle(Request::create('/'), $this->kernel))
            ->toThrow(RuntimeException::class, 'termination failed');
    } else {
        expect($boundary->handle(Request::create('/'), $this->kernel))->toBe($response);
    }

    expect($this->sessions->driver()->all())->toBe([])
        ->and($this->rig->runtime->current()->kind)->toBe('missing');
})->with([false, true]);

it('refuses returning deferred response IO outside the active context', function (string $type): void {
    $sent = false;
    $response = $type === 'stream'
        ? new StreamedResponse(function () use (&$sent): void {
            $sent = true;
        })
        : new BinaryFileResponse(__FILE__);
    $boundary = new ContextRequestBoundary($this->rig->runtime, $this->container, static fn (): Selection => RuntimeRig::selection('A'));
    $this->kernel->expects($this->once())->method('handle')->willReturn($response);
    $this->kernel->expects($this->once())->method('terminate')->willReturnCallback(function (): void {
        expect($this->rig->runtime->current()->context?->identity->id)->toBe('A');
    });

    expect(fn (): Response => $boundary->handle(Request::create('/'), $this->kernel))
        ->toThrow(TenantFailure::class, 'unsupported_capability')
        ->and($sent)->toBeFalse()
        ->and($this->rig->runtime->current()->kind)->toBe('missing');
})->with(['stream', 'file']);

it('sends deferred content before termination and restores the context even on send failure', function (bool $fails): void {
    $events = [];
    $response = new class(function () use (&$events, $fails): void {
        expect($this->rig->runtime->current()->context?->identity->id)->toBe('A');
        $events[] = 'send';
        $this->sessions->driver()->put('during-stream', 'discard');
        if ($fails) {
            throw new RuntimeException('stream failed');
        }
    }) extends StreamedResponse
    {
        public function send(bool $flush = true): static
        {
            return parent::send(false);
        }
    };
    $boundary = new ContextRequestBoundary($this->rig->runtime, $this->container, static fn (): Selection => RuntimeRig::selection('A'));
    $this->kernel->expects($this->once())->method('handle')->willReturnCallback(function () use (&$events, $response): Response {
        expect($this->rig->runtime->current()->context?->identity->id)->toBe('A');
        $events[] = 'handle';

        return $response;
    });
    $this->kernel->expects($this->once())->method('terminate')->willReturnCallback(function () use (&$events): void {
        expect($this->rig->runtime->current()->context?->identity->id)->toBe('A')
            ->and($this->sessions->driver()->get('during-stream'))->toBe('discard');
        $events[] = 'terminate';
    });

    if ($fails) {
        expect(fn () => $boundary->send(Request::create('/'), $this->kernel))->toThrow(RuntimeException::class, 'stream failed');
    } else {
        $boundary->send(Request::create('/'), $this->kernel);
    }

    expect($events)->toBe(['handle', 'send', 'terminate'])
        ->and($this->sessions->driver()->all())->toBe([])
        ->and($this->rig->runtime->current()->kind)->toBe('missing');
})->with([false, true]);

it('reads binary download content before restoring the tenant context', function (): void {
    $file = new SplTempFileObject;
    $file->fwrite('tenant download');
    $response = new class($file, $this->rig) extends BinaryFileResponse
    {
        public function __construct(SplTempFileObject $file, private RuntimeRig $rig)
        {
            parent::__construct($file);
        }

        public function sendContent(): static
        {
            expect($this->rig->runtime->current()->context?->identity->id)->toBe('A');

            return parent::sendContent();
        }
    };
    $boundary = new ContextRequestBoundary($this->rig->runtime, $this->container, static fn (): Selection => RuntimeRig::selection('A'));
    $this->kernel->expects($this->once())->method('handle')->willReturn($response);
    $this->kernel->expects($this->once())->method('terminate')->willReturnCallback(function (): void {
        expect($this->rig->runtime->current()->context?->identity->id)->toBe('A');
    });

    ob_start();
    try {
        $boundary->send(Request::create('/download'), $this->kernel);
        $content = ob_get_contents();
    } finally {
        ob_end_clean();
    }

    expect($content)->toBe('tenant download')
        ->and($this->rig->runtime->current()->kind)->toBe('missing');
});

it('stops a process when host selection leaks a foreign frame even if selection throws', function (string $mode): void {
    $script = <<<'PHP'
require $argv[1];
$rig = new TenantTests\RuntimeRig(ids: []);
$kernel = new class implements Illuminate\Contracts\Http\Kernel {
    public function bootstrap() {}
    public function handle($request) { throw new RuntimeException('kernel must not run'); }
    public function terminate($request, $response) {}
    public function getApplication() { return null; }
};
$boundary = new Tenant\Laravel\Http\ContextRequestBoundary(
    $rig->runtime,
    new Illuminate\Container\Container,
    function () use ($rig, $argv) {
        $rig->enter('A');
        if ($argv[2] === 'throw') {
            throw new RuntimeException('selector failed');
        }
        return TenantTests\RuntimeRig::selection('B');
    },
);
try {
    $boundary->handle(Illuminate\Http\Request::create('/'), $kernel);
} catch (Throwable) {}
echo 'unsafe process continued';
PHP;
    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 4).'/vendor/autoload.php', $mode]);
    $process->run();

    expect($process->getExitCode())->toBe(70)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('tenant boundary stopped pid=');
})->with(['return', 'throw']);

it('supports an HTTP boundary nested in an explicit outer tenant frame', function (): void {
    $this->rig->runtime->run(RuntimeRig::selection('B'), RuntimeRig::purpose(), function (): void {
        $boundary = new ContextRequestBoundary($this->rig->runtime, $this->container, static fn (): Selection => RuntimeRig::selection('A'));
        $this->kernel->expects($this->once())->method('handle')->willReturn(new Response('buffered'));
        $this->kernel->expects($this->once())->method('terminate')->willReturnCallback(function (): void {
            expect($this->rig->runtime->current()->context?->identity->id)->toBe('A');
        });

        $boundary->handle(Request::create('/'), $this->kernel);
        expect($this->rig->runtime->current()->context?->identity->id)->toBe('B');
    });
    expect($this->rig->runtime->current()->kind)->toBe('missing');
});
