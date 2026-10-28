<?php

declare(strict_types=1);

namespace Tenant\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tenant\Contracts\TenantRuntime;
use Tenant\Exceptions\ScopeRequired;

/** Binds session data after StartSession, before any authentication/admission. */
final readonly class ScopeSessionToResource
{
    public const string KEY = 'tenant.resource';
    public function __construct(private TenantRuntime $runtime) {}
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->runtime->current()->context ?? throw new ScopeRequired();
        $binding = [$context->identity->namespace, $context->binding->resourceRef, $context->binding->incarnation];
        $session = $request->session();
        $bound = $session->get(self::KEY);
        if ($bound === null) {
            $session->invalidate();
            $session->regenerateToken();
            $session->put(self::KEY, $binding);
        } elseif ($bound !== $binding) {
            $session->invalidate();
            throw new HttpException(403);
        }
        return $next($request);
    }
}
