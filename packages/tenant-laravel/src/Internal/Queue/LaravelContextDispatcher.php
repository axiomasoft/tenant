<?php

declare(strict_types=1);
namespace Tenant\Laravel\Internal\Queue;

use Illuminate\Queue\QueueManager;
use Tenant\Contracts\{ContextCodec, TenantRuntime};
use Tenant\Data\{ContextReference, FrozenDispatch, Purpose};
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Queue\{ContextDispatcher, QueueRegistry, ScalarJob};

/** @internal Explicit capture plus fresh enqueue admission under the original target. */
final readonly class LaravelContextDispatcher implements ContextDispatcher
{
    public function __construct(private TenantRuntime $runtime, private ContextCodec $codec, private QueueRegistry $registry, private QueueManager $queues) {}
    public function freezeTenant(ContextReference $reference, Purpose $purpose): FrozenDispatch
    {
        if ($reference->envelope['mode'] === 'central') throw new TenantFailure('denied');
        $this->codec->encode($reference);
        $this->runtime->run($this->registry->selection($reference), $purpose, static fn () => null);
        return new FrozenDispatch($reference, $purpose);
    }
    public function freezeCentral(Purpose $purpose): FrozenDispatch
    {
        return new FrozenDispatch(new ContextReference(['schema'=>'tenant.context/1','mode'=>'central','profileId'=>$this->registry->centralProfile->id,'profileVersion'=>$this->registry->centralProfile->version,'extensions'=>[]]), $purpose);
    }
    public function enqueue(FrozenDispatch $intent, object $job): void
    {
        if (!$job instanceof ScalarJob || !in_array($job->connection, ['sync', 'database'], true)) throw new TenantFailure('denied');
        ScalarPayload::data($job->data);
        $this->registry->authorize($job->producer, $job->handler, $intent->purpose);
        $this->runtime->run($this->registry->selection($intent->reference, $job->handler), $intent->purpose, function () use ($intent, $job): void {
            $this->registry->admit($job->producer, $job->handler, $intent->purpose, $job->data);
        });
        $data = FrozenIntent::payload($intent, $job, $this->codec, $this->registry);
        $this->queues->connection($job->connection)->push(ScalarHandler::class.'@fire', $data, $job->queue);
    }
}
