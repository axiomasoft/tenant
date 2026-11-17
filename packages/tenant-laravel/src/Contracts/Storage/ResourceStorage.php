<?php

declare(strict_types=1);

namespace Tenant\Laravel\Contracts\Storage;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Guarded process-local resource storage; obtaining a native path is unsupported. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ResourceStorage
{
    public function disk(): ResourceFilesystem;

    public function ephemeralDisk(): ResourceFilesystem;

    public function usageBytes(): int;

    public function exportArchive(): never;
}
