<?php

declare(strict_types=1);

namespace Tenant\Laravel\Http;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\HttpFoundation\Response;
use Tenant\Contracts\TenantRuntime;
use Tenant\Data\Purpose;

/** Host front-controller boundary. Must wrap both handle and terminate. */
final readonly class ContextRequestBoundary
{
    /** @param Closure(Request): \Tenant\Data\Selection $select Trusted host route/admission. */
    public function __construct(private TenantRuntime $runtime, private Container $app, private Closure $select) {}
    public function handle(Request $request, Kernel $kernel): Response
    {
        (new \Tenant\Laravel\Internal\Boundary\ProcessBoundary($this->runtime))->check();
        // Selection must refuse unknown/untrusted hosts before session or tenant IO.
        $selection = ($this->select)($request);
        $this->dropResidentState();
        try {
        $result = $this->runtime->run($selection, new Purpose('tenant:http'), function () use ($request, $kernel): Response {
            $response = null;
            $expected = $this->runtime->current();
            try { return $response = $kernel->handle($request); }
            finally {
                try {
                    if ($response !== null) {
                        // Termination must never execute on a route's leaked foreign frame.
                        if ($this->runtime->current() != $expected) (new \Tenant\Laravel\Internal\Boundary\ProcessBoundary($this->runtime))->stop();
                        $kernel->terminate($request, $response);
                    }
                }
                finally { $this->dropResidentState(); }
            }
        });
        } finally { (new \Tenant\Laravel\Internal\Boundary\ProcessBoundary($this->runtime))->check(); }
        if (!$result instanceof Response) throw new \LogicException('Invalid HTTP boundary result');
        return $result;
    }
    private function dropResidentState(): void
    {
        if ($this->app->bound('session')) {
            foreach (\Tenant\Laravel\Internal\FrameworkConfig::stores(\Tenant\Laravel\Internal\FrameworkConfig::service($this->app, 'session', \Illuminate\Session\SessionManager::class)) as $store) {
                $store->flush();
                $store->setId(null);
                $store->setExists(false);
            }
        }
        if ($this->app->bound('auth')) \Tenant\Laravel\Internal\FrameworkConfig::service($this->app, 'auth', \Illuminate\Auth\AuthManager::class)->forgetGuards();
    }
}
