<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Boundary;

use LogicException;
use Packman\Attributes\Internal;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Exceptions\Runtime\RuntimePoisoned;

/** @internal Fail-stop after poison or unclosable boundary. Host supervisor replaces process. */
#[Internal]
final readonly class ProcessBoundary
{
    public function __construct(private TenantRuntime $runtime) {}

    public function check(): void
    {
        try {
            $this->runtime->current();
        } catch (RuntimePoisoned) {
            $this->stop();
        }
    }

    public function stop(): never
    {
        // No exception can be caught by a business handler to resume this process.
        error_log('tenant boundary stopped pid='.getmypid());
        if (PHP_SAPI === 'fpm-fcgi' && function_exists('posix_kill')) {
            $pid = getmypid();
            posix_kill($pid === false ? throw new LogicException('Process PID unavailable') : $pid, 9);
        }
        exit(70);
    }
}
