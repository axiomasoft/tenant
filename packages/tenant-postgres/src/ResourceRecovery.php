<?php

declare(strict_types=1);
namespace Tenant\Postgres;
/** Replacement binding remains quarantined until independently verified for admission. */
final readonly class ResourceRecovery
{
    public function __construct(public string $resourceRef,public string $incarnation,public string $routeRevision,public string $recoveryGeneration) {}
}
