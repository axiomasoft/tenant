<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data;

final readonly class ClaimRequest
{
    public function __construct(
        public string $requestKey,
        public string $clusterId,
        public string $templateId,
        public string $requestDigest,
        public ?int $expectedTemplateVersion = null,
        public ?string $expectedManifestHash = null,
    ) {}
}
