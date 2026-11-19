<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use Packman\Attributes\Api;

/** A verified plan is neither authorization nor a completed recovery. */
#[Api(since: '1.0')]
final readonly class RecoveryPlan
{
    /** @param list<string> $steps */
    public function __construct(public string $intentDigest, public string $externalGeneration, public RecoveryIntent $intent, public ArchiveManifest $archive, public array $steps) {}
}
