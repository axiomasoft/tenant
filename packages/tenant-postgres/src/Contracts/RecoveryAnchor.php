<?php

declare(strict_types=1);
namespace Tenant\Postgres\Contracts;
/** Independent of catalog backups. Entries are permanent for the configured replay horizon. */
interface RecoveryAnchor
{
    public function generation(): string;
    public function entries(): array;
    public function remember(string $keyHash, array $original): void;
    public function advance(string $expectedGeneration): string;
}
