<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data;

final readonly class ClaimResult
{
    public function __construct(
        public string $resourceId,
        public bool $replayed,
    ) {}
}
