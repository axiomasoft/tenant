<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Packman\Attributes\Internal;
use Throwable;

/** @internal Verified locked Laravel12/13: processing precedes hydration; attempted follows failure/release callbacks. */
#[Internal]
final readonly class QueueEventRegistrar
{
    public function __construct(private QueueContextBoundary $boundary) {}

    public function boot(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($events): void {
            try {
                $this->boundary->processing($event->job);
            } catch (Throwable $error) {
                if (! $this->boundary->allowsFailureCallback($event->job)) {
                    // Untrusted transport must never reach Job::failed()/hydration.
                    // Persist the original raw payload through normal failed-job listeners
                    // before deleting it, so quarantine remains observable and retryable.
                    $event->job->markAsFailed();
                    $events->dispatch(new JobFailed($event->connectionName, $event->job, $error));
                    $event->job->delete();
                }
                // Valid transport keeps Laravel's release/backoff/max-attempt semantics.

                throw $error;
            }
        });
        $events->listen(JobAttempted::class, fn (JobAttempted $event) => $this->boundary->finished($event->job, $event->successful()));
    }
}
