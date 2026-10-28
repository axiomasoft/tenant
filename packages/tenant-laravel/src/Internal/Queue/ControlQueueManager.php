<?php

declare(strict_types=1);
namespace Tenant\Laravel\Internal\Queue;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\QueueManager;
use function Illuminate\Support\enum_value;

/** @internal Framework queue interruption state belongs to a named central cache. */
final class ControlQueueManager extends QueueManager
{
    public function __construct(\Illuminate\Contracts\Foundation\Application $app, private readonly Repository $control) { parent::__construct($app); }
    /**
     * @param string|\UnitEnum $connection
     * @param string|\UnitEnum $queue */
    public function isPaused($connection, $queue): bool
    {
        $connection=$this->name($connection);$queue=$this->name($queue);
        return (bool) ($this->control->get('illuminate:queues:paused') ?: $this->control->get("illuminate:queue:paused:{$connection}:{$queue}"));
    }
    /**
     * @param string $connection
     * @param list<string> $queues
     * @return list<string> */
    public function getPausedQueues($connection, $queues): array { return array_values(array_filter($queues, fn (string $q): bool => $this->isPaused($connection,$q))); }
    /**
     * @param string|\UnitEnum $connection
     * @param string|\UnitEnum $queue */
    public function pause($connection, $queue): void
    {
        $connection=$this->name($connection);$queue=$this->name($queue);
        $this->control->forever("illuminate:queue:paused:{$connection}:{$queue}",true);
        $this->event(new \Illuminate\Queue\Events\QueuePaused($connection,$queue));
    }
    /**
     * @param string|\UnitEnum $connection
     * @param string|\UnitEnum $queue
     * @param \DateTimeInterface|\DateInterval|int $ttl */
    public function pauseFor($connection, $queue, $ttl): void
    {
        $connection=$this->name($connection);$queue=$this->name($queue);
        $this->control->put("illuminate:queue:paused:{$connection}:{$queue}",true,$ttl);
        $this->event(new \Illuminate\Queue\Events\QueuePaused($connection,$queue,$ttl));
    }
    private function event(object $event): void
    {
        $events=$this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);
        $events->dispatch($event);
    }
    private function name(mixed $value): string
    {
        $name=enum_value($value);
        if(!is_string($name))throw new \InvalidArgumentException('Queue name must be a string');
        return $name;
    }
    public function pauseAll(): void { $this->control->forever('illuminate:queues:paused',true); }
    public function resumeAll(): void { $this->control->forget('illuminate:queues:paused'); }
    /**
     * @param string|\UnitEnum $connection
     * @param string|\UnitEnum $queue */
    public function resume($connection, $queue): void { $connection=$this->name($connection);$queue=$this->name($queue);$this->control->forget("illuminate:queue:paused:{$connection}:{$queue}");$this->event(new \Illuminate\Queue\Events\QueueResumed($connection,$queue)); }
}
