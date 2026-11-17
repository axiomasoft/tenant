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
use Symfony\Component\HttpFoundation\Response;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\Selection;
use Tenant\Laravel\Internal\Boundary\ProcessBoundary;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;

/** Host front-controller boundary. Must wrap both handle and terminate. */
#[Api(since: '1.0')]
final readonly class ContextRequestBoundary
{
    /** @param Closure(Request): Selection $select Trusted host route/admission. */
    public function __construct(private TenantRuntime $runtime, private Container $app, private Closure $select) {}

    public function handle(Request $request, Kernel $kernel): Response
    {
        new ProcessBoundary($this->runtime)->check();
        // Selection must refuse unknown/untrusted hosts before session or tenant IO.
        $selection = ($this->select)($request);
        $this->dropResidentState();
        try {
            $result = $this->runtime->run($selection, new Purpose('tenant:http'), function () use ($request, $kernel): Response {
                $response = null;
                $expected = $this->runtime->current();
                try {
                    return $response = $kernel->handle($request);
                } finally {
                    try {
                        if ($response !== null) {
                            // Termination must never execute on a route's leaked foreign frame.
                            if (serialize($this->runtime->current()) !== serialize($expected)) {
                                new ProcessBoundary($this->runtime)->stop();
                            }
                            $kernel->terminate($request, $response);
                        }
                    } finally {
                        $this->dropResidentState();
                    }
                }
            });
        } finally {
            new ProcessBoundary($this->runtime)->check();
        }
        if (! $result instanceof Response) {
            throw new LogicException('Invalid HTTP boundary result');
        }

        return $result;
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
