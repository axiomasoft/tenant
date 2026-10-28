<?php

declare(strict_types=1);

namespace Tenant\Laravel\Contracts;



/** Guarded process-local resource storage; obtaining a native path is unsupported. */
interface ResourceStorage
{
    public function disk(): ResourceFilesystem;
    public function ephemeralDisk(): ResourceFilesystem;
    public function usageBytes(): int;
    public function exportArchive(): never;
}
