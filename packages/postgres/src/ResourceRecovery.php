<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use Packman\Attributes\Api;

/** Replacement binding remains quarantined until independently verified for admission. */
#[Api(since: '1.0')]
final readonly class ResourceRecovery
{
    public function __construct(public string $resourceRef, public string $incarnation, public string $routeRevision, public string $recoveryGeneration) {}
}
