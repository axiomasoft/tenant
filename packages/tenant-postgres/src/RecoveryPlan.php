<?php

declare(strict_types=1);
namespace Tenant\Postgres;
/** A verified plan is neither authorization nor a completed recovery. */
final readonly class RecoveryPlan
{
    public function __construct(public string $intentDigest, public string $externalGeneration, public RecoveryIntent $intent, public ArchiveManifest $archive, public array $steps) {}
}
