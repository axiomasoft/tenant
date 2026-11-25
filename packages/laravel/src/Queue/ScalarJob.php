<?php

declare(strict_types=1);

namespace Tenant\Laravel\Queue;

use Packman\Attributes\Api;

/** Local producer request; only scalar data is persisted, never this object. */
#[Api(since: '1.0')]
final readonly class ScalarJob
{
    /** @param array<string, scalar|null> $data */
    public function __construct(public string $producer, public string $handler, public array $data, public string $connection = 'database', public string $queue = 'default') {}
}
