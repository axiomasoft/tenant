<?php

declare(strict_types=1);

namespace Tenant\Postgres\Contracts\Lifecycle;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Postgres\ArchiveManifest;

/** Trusted owner adapter. Must bind readable bytes, size, digest and exact original ownership. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ArchiveStore
{
    public function write(object $resource, string $operationId, string $exportId, string $destinationRef): ArchiveManifest;

    public function verify(ArchiveManifest $manifest): bool;

    /** Read the original durable result after interruption; never start another export. */
    public function recover(string $exportId): ?ArchiveManifest;
}
