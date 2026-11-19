<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Queue;

use Illuminate\Contracts\Queue\Job;
use Packman\Attributes\Internal;
use Tenant\Laravel\Internal\Boundary\ProcessBoundary;
use Throwable;

/** @internal Framework callable; object/model hydration is restricted to registered host handlers. */
#[Internal]
final readonly class ScalarHandler
{
    public function __construct(private QueueContextBoundary $boundary, private ProcessBoundary $process) {}

    /** @param array<string, mixed> $data */
    public function fire(Job $job, array $data): void
    {
        try {
            $this->boundary->handle($job);
        } finally {
            $this->process->check();
        }
        // Success ACK is deferred until final framework callbacks and restoration.
    }

    /** @param array<string, mixed> $data */
    public function failed(array $data, Throwable $error, string $uuid, Job $job): void
    {
        try {
            $this->boundary->failed($job, $error);
        } finally {
            $this->process->check();
        }
    }
}
