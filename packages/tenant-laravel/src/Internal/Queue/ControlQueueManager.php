<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Queue;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\QueuePaused;
use Illuminate\Queue\Events\QueueResumed;
use Illuminate\Queue\QueueManager;
use InvalidArgumentException;
use Packman\Attributes\Internal;

use function Illuminate\Support\enum_value;

/** @internal Framework queue interruption state belongs to a named central cache. */
#[Internal]
final class ControlQueueManager extends QueueManager
{
    public function __construct(Application $app, private readonly Repository $control)
    {
        parent::__construct($app);
    }

    /**
     * @param string|\UnitEnum $connection
     * @param string|\UnitEnum $queue */
    public function isPaused($connection, $queue): bool
    {
        $connection = $this->name($connection);
        $queue = $this->name($queue);

        return (bool) $this->control->get('illuminate:queues:paused') || (bool) $this->control->get("illuminate:queue:paused:{$connection}:{$queue}");
    }

    /**
     * @param  string  $connection
     * @param  array<mixed>  $queues
     * @return list<string>
     */
    public function getPausedQueues($connection, $queues): array
    {
        return array_values(array_filter(
            $queues,
            fn (mixed $q): bool => is_string($q) && $this->isPaused($connection, $q),
        ));
    }

    /**
     * @param string|\UnitEnum $connection
     * @param string|\UnitEnum $queue */
    public function pause($connection, $queue): void
    {
        $connection = $this->name($connection);
        $queue = $this->name($queue);
        $this->control->forever("illuminate:queue:paused:{$connection}:{$queue}", true);
        $this->event(new QueuePaused($connection, $queue));
    }

    /**
     * @param string|\UnitEnum $connection
     * @param string|\UnitEnum $queue
     * @param \DateTimeInterface|\DateInterval|int $ttl */
    public function pauseFor($connection, $queue, $ttl): void
    {
        $connection = $this->name($connection);
        $queue = $this->name($queue);
        $this->control->put("illuminate:queue:paused:{$connection}:{$queue}", true, $ttl);
        $this->event(new QueuePaused($connection, $queue, $ttl));
    }

    private function event(object $event): void
    {
        $events = $this->app->make(Dispatcher::class);
        $events->dispatch($event);
    }

    private function name(mixed $value): string
    {
        $name = enum_value($value);
        if (! is_string($name)) {
            throw new InvalidArgumentException('Queue name must be a string');
        }

        return $name;
    }

    public function pauseAll(): void
    {
        $this->control->forever('illuminate:queues:paused', true);
    }

    public function resumeAll(): void
    {
        $this->control->forget('illuminate:queues:paused');
    }

    /**
     * @param string|\UnitEnum $connection
     * @param string|\UnitEnum $queue */
    public function resume($connection, $queue): void
    {
        $connection = $this->name($connection);
        $queue = $this->name($queue);
        $this->control->forget("illuminate:queue:paused:{$connection}:{$queue}");
        $this->event(new QueueResumed($connection, $queue));
    }
}
