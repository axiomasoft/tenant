<?php

declare(strict_types=1);

namespace Tenant\Composition;

use Tenant\Attributes\Api;

use Tenant\Data\ParticipantDeclaration;

#[Api]
final readonly class CompiledComposition
{
    /**
     * @param list<ParticipantDeclaration> $participants
     * @param list<string> $orderedIds
     * @param array<string, mixed> $profiles
     * @param array<string, object> $services
     */
    public function __construct(public int $version, public array $orderedIds, public array $profiles, public array $participants, public array $services) {}
}
