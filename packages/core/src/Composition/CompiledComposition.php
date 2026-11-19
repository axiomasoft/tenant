<?php

declare(strict_types=1);

namespace Tenant\Composition;

use Packman\Attributes\Api;
use Tenant\Data\Composition\ParticipantDeclaration;
use Tenant\Internal\Validation\ArraySnapshot;

#[Api(since: '1.0')]
final readonly class CompiledComposition
{
    /** @var list<string> */
    public array $orderedIds;

    /** @var array<string, mixed> */
    public array $profiles;

    /** @var list<ParticipantDeclaration> */
    public array $participants;

    /** @var array<string, object> */
    public array $services;

    /**
     * @param  list<ParticipantDeclaration>  $participants
     * @param  list<string>  $orderedIds
     * @param  array<string, mixed>  $profiles
     * @param  array<string, object>  $services
     */
    public function __construct(public int $version, array $orderedIds, array $profiles, array $participants, array $services)
    {
        $this->orderedIds = ArraySnapshot::copy($orderedIds);
        $this->profiles = ArraySnapshot::copy($profiles);
        $this->participants = ArraySnapshot::copy($participants);
        $this->services = ArraySnapshot::copy($services);
    }
}
