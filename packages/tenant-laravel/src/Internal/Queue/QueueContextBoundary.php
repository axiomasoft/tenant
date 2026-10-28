<?php

declare(strict_types=1);
namespace Tenant\Laravel\Internal\Queue;

use Illuminate\Contracts\Queue\Job;
use Tenant\Contracts\{ContextCodec, TenantRuntime};
use Tenant\Data\{FrameToken, Purpose, Selection};
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Queue\QueueRegistry;
use Tenant\Laravel\Internal\Boundary\ProcessBoundary;

/** @internal Per actual Job object/attempt LIFO; runtime remains the sole frame owner. */
final class QueueContextBoundary
{
    /** @var \WeakMap<Job, array{token: FrameToken, attempt: int, data: ScalarPayload|null}> */
    private \WeakMap $frames;
    /** @var \WeakMap<Job, bool> Transport validated; framework failure callbacks are safe. */
    private \WeakMap $trusted;
    public function __construct(private readonly TenantRuntime $runtime, private readonly ContextCodec $codec, private readonly QueueRegistry $registry, private readonly ProcessBoundary $process) { $this->frames = new \WeakMap(); $this->trusted = new \WeakMap(); }
    public function processing(Job $job): void
    {
        $this->process->check();
        if (isset($this->frames[$job])) throw new TenantFailure('invalid_token');
        $p = $job->payload();
        if (($p['job'] ?? null) !== ScalarHandler::class.'@fire') {
            if (!in_array($p['job'] ?? null, $this->registry->centralJobs, true)) throw new TenantFailure('denied');
            $data = null; $selection = Selection::central($this->registry->centralProfile); $purpose = new Purpose('tenant:central-framework');
        } else {
            $data = ScalarPayload::parse($p['data'] ?? null, $this->registry);
            $this->registry->authorize($data->producer, $data->handler, $data->purpose);
            $selection = $this->registry->selection($this->codec->decode($data->wire), $data->handler);
            $purpose = $data->purpose;
        }
        $this->trusted[$job] = true;
        if ($job->getConnectionName() !== 'sync') {
            $state = $this->runtime->current();
            if ($state->kind === 'missing') $this->runtime->enter(Selection::central($this->registry->centralProfile), new Purpose('tenant:worker'));
            elseif ($state->kind !== 'central') $this->process->stop();
        }
        $token = $this->runtime->enter($selection, $purpose);
        $this->frames[$job] = ['token'=>$token,'attempt'=>$job->attempts(),'data'=>$data];
        // Fresh owning handler admission AFTER verified activation, BEFORE hydration/effect.
        if ($data !== null) $this->admit($data);
    }
    public function allowsFailureCallback(Job $job): bool
    {
        // Poison must stop before any destructive failure completion or ACK.
        $this->process->check();
        return $this->trusted[$job] ?? false;
    }
    private function admit(ScalarPayload $data): void
    {
        $this->registry->admit($data->producer, $data->handler, $data->purpose, $data->data);
    }
    public function handle(Job $job): void
    {
        $data = $this->frames[$job]['data'] ?? throw new TenantFailure('denied');
        // run detects leaked streams/tokens even when the handler throws and emits
        // callback_failed without closing the outer framework callback scope.
        $this->runtime->run($this->registry->selection($this->codec->decode($data->wire), $data->handler), $data->purpose, function () use ($data, $job): void {
            $this->admit($data);
            $this->registry->handle($data->handler, $data->data, $job);
        });
    }
    public function failed(Job $job, \Throwable $error): void
    {
        // Refused messages never hydrate or invoke a product failure callback.
        $data = $this->frames[$job]['data'] ?? null;
        if ($data === null) return;
        try { $this->admit($data); } catch (\Throwable) { return; }
        $this->registry->failed($data->handler, $data->data, $error);
    }
    public function finished(Job $job, bool $successful): void
    {
        $this->process->check();
        unset($this->trusted[$job]);
        $frame = $this->frames[$job] ?? null;
        if ($frame === null || $frame['attempt'] !== $job->attempts()) return;
        // A foreign/out-of-order/duplicate completion never pops another frame.
        try { $this->runtime->restore($frame['token']); }
        catch (\Throwable) { $this->process->stop(); }
        unset($this->frames[$job]);
        $this->process->check();
        if ($successful && !$job->isDeletedOrReleased() && !$job->hasFailed()) $job->delete();
    }
}
