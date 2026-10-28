<?php

declare(strict_types=1);
namespace Tenant\Laravel\Internal\Boundary;

use Tenant\Contracts\TenantRuntime;
use Tenant\Exceptions\RuntimePoisoned;

/** @internal Fail-stop after poison or unclosable boundary. Host supervisor replaces process. */
final readonly class ProcessBoundary
{
    public function __construct(private TenantRuntime $runtime) {}
    public function check(): void
    {
        try { $this->runtime->current(); } catch (RuntimePoisoned) { $this->stop(); }
    }
    public function stop(): never
    {
        // No exception can be caught by a business handler to resume this process.
        error_log('tenant boundary stopped pid='.getmypid());
        if (PHP_SAPI === 'fpm-fcgi' && function_exists('posix_kill')) posix_kill(getmypid() ?: throw new \LogicException('Process PID unavailable'), 9);
        exit(70);
    }
}
