<?php

declare(strict_types=1);
namespace Tenant\Postgres;
final readonly class ExportReceipt
{
    public function __construct(public ExportRef $exportRef, public string $state, public ?ArchiveManifest $manifest) {}
}
