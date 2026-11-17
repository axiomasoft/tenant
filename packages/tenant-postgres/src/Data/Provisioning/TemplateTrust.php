<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data\Provisioning;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class TemplateTrust
{
    /** @param array<string, array<string, mixed>> $templateIntegrityManifests
     *  @param array<string, string> $templateIntegrityTrustedKeys */
    public function __construct(public array $templateIntegrityManifests, public array $templateIntegrityTrustedKeys) {}
}
