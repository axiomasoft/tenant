<?php

declare(strict_types=1);
namespace Tenant\Laravel\Internal\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\{JobProcessing, JobAttempted};

/** @internal Verified locked Laravel12/13: processing precedes hydration; attempted follows failure/release callbacks. */
final readonly class QueueEventRegistrar
{
    public function __construct(private QueueContextBoundary $boundary) {}
    public function boot(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            try { $this->boundary->processing($event->job); }
            catch (\Throwable $error) {
                // Prevent Laravel's failure path from resolving/deserialising rejected jobs.
                $event->job->markAsFailed();
                $event->job->delete();
                throw $error;
            }
        });
        $events->listen(JobAttempted::class, fn (JobAttempted $event) => $this->boundary->finished($event->job, $event->successful()));
    }
}
