<?php

declare(strict_types=1);

namespace Tenant\Laravel\Http;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use LogicException;
use Packman\Attributes\Api;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\Selection;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Internal\Boundary\ProcessBoundary;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;

/** Host front-controller boundary. Use send() to include deferred response IO. */
#[Api(since: '1.0')]
final readonly class ContextRequestBoundary
{
    /** @param Closure(Request): Selection $select Trusted host route/admission. */
    public function __construct(private TenantRuntime $runtime, private Container $app, private Closure $select) {}

    public function handle(Request $request, Kernel $kernel): Response
    {
        return $this->dispatch($request, $kernel, false);
    }

    /** Send streaming/download responses while their tenant context remains active. */
    public function send(Request $request, Kernel $kernel): void
    {
        $this->dispatch($request, $kernel, true);
    }

    private function dispatch(Request $request, Kernel $kernel, bool $send): Response
    {
        new ProcessBoundary($this->runtime)->check();
        $baseline = $this->runtime->current();
        $this->dropResidentState();
        try {
            // Selection must refuse unknown/untrusted hosts before session or tenant IO.
            $selection = ($this->select)($request);
            $this->assertContext($baseline);
            $this->dropResidentState();
            $result = $this->runtime->run($selection, new Purpose('tenant:http'), function () use ($request, $kernel, $send): Response {
                $response = null;
                $expected = $this->runtime->current();
                try {
                    $response = $kernel->handle($request);
                    $this->assertContext($expected);
                    if ($send) {
                        $response->send();
                    } elseif ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
                        // Deferred callbacks or file reads cannot escape this frame.
                        throw new TenantFailure('unsupported_capability');
                    }

                    return $response;
                } finally {
                    try {
                        if ($response !== null) {
                            // Termination must never execute on a route's leaked foreign frame.
                            $this->assertContext($expected);
                            $kernel->terminate($request, $response);
                        }
                    } finally {
                        $this->dropResidentState();
                    }
                }
            });
        } finally {
            try {
                $this->dropResidentState();
            } finally {
                new ProcessBoundary($this->runtime)->check();
                $this->assertContext($baseline);
            }
        }
        if (! $result instanceof Response) {
            throw new LogicException('Invalid HTTP boundary result');
        }

        return $result;
    }

    private function assertContext(ContextState $expected): void
    {
        if (serialize($this->runtime->current()) !== serialize($expected)) {
            new ProcessBoundary($this->runtime)->stop();
        }
    }

    private function dropResidentState(): void
    {
        if ($this->app->bound('session')) {
            foreach (FrameworkConfig::stores(FrameworkConfig::service($this->app, 'session', SessionManager::class)) as $store) {
                $store->flush();
                $store->setId(null);
                $store->setExists(false);
            }
        }
        if ($this->app->bound('auth')) {
            FrameworkConfig::service($this->app, 'auth', AuthManager::class)->forgetGuards();
        }
    }
}
