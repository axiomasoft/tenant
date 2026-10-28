<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data;

/** Captured catalog binding, not an authority or proof of current incarnation. */
final readonly class ResourceSnapshot
{
    public function __construct(
        public string $resourceRef,
        public string $incarnation,
        public int $templateVersion,
        public string $clusterId,
        public string $databaseName,
    ) {}
}
