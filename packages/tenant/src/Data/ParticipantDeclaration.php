<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Spi;

use Tenant\Contracts\ParticipantFactory;
#[Spi]
final readonly class ParticipantDeclaration
{
    /**
     * @param list<CapabilityId> $provides
     * @param list<CapabilityId> $requires
     * @param list<string> $exclusiveSlots
     * @param list<string> $before
     * @param list<string> $after
     */
    public function __construct(public string $id, public int $spiVersion, public string $stage, public array $provides, public array $requires, public array $exclusiveSlots, public array $before, public array $after, public ?string $replaces, public ParticipantFactory $factory) {}
    public function __serialize(): array { throw new \LogicException('Declarations are process local'); }
    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void { throw new \LogicException('Process-local value cannot be unserialized'); }
}

