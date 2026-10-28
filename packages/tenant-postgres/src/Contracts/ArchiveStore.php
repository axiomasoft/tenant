<?php

declare(strict_types=1);
namespace Tenant\Postgres\Contracts;
use Tenant\Postgres\ArchiveManifest;
/** Trusted owner adapter. Must bind readable bytes, size, digest and exact original ownership. */
interface ArchiveStore
{
    public function write(object $resource, string $operationId, string $exportId, string $destinationRef): ArchiveManifest;
    public function verify(ArchiveManifest $manifest): bool;
    /** Read the original durable result after interruption; never start another export. */
    public function recover(string $exportId): ?ArchiveManifest;
}
