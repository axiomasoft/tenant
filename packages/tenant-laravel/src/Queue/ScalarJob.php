<?php

declare(strict_types=1);
namespace Tenant\Laravel\Queue;

/** Local producer request; only scalar data is persisted, never this object. */
final readonly class ScalarJob
{
    /** @param array<string, scalar|null> $data */
    public function __construct(public string $producer, public string $handler, public array $data, public string $connection = 'database', public string $queue = 'default') {}
}
