<?php

declare(strict_types=1);

namespace Tenant\Internal\Runtime;

use Tenant\Contracts\FrameState;

/** @internal Mutable snapshots belong to a single participant in a single frame. */
final class ParticipantFrameState implements FrameState
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function put(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function get(string $key): mixed { return $this->values[$key] ?? null; }
}
