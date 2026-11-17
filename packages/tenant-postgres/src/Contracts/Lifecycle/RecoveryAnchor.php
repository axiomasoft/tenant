<?php

declare(strict_types=1);

namespace Tenant\Postgres\Contracts\Lifecycle;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Independent of catalog backups. Entries are permanent for the configured replay horizon. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface RecoveryAnchor
{
    public function generation(): string;

    /** @return array<string, array<string, mixed>> */
    public function entries(): array;

    /** @param array<string, mixed> $original */
    public function remember(string $keyHash, array $original): void;

    public function advance(string $expectedGeneration): string;
}
