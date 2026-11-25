<?php

declare(strict_types=1);

namespace Tenant\Data\Composition;

use LogicException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Contracts\Runtime\ParticipantFactory;
use Tenant\Internal\Validation\ArraySnapshot;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ParticipantDeclaration
{
    /** @var list<CapabilityId> */
    public array $provides;

    /** @var list<CapabilityId> */
    public array $requires;

    /** @var list<string> */
    public array $exclusiveSlots;

    /** @var list<string> */
    public array $before;

    /** @var list<string> */
    public array $after;

    /**
     * @param  list<CapabilityId>  $provides
     * @param  list<CapabilityId>  $requires
     * @param  list<string>  $exclusiveSlots
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function __construct(public string $id, public int $spiVersion, public string $stage, array $provides, array $requires, array $exclusiveSlots, array $before, array $after, public ?string $replaces, public ParticipantFactory $factory)
    {
        $this->provides = ArraySnapshot::copy($provides);
        $this->requires = ArraySnapshot::copy($requires);
        $this->exclusiveSlots = ArraySnapshot::copy($exclusiveSlots);
        $this->before = ArraySnapshot::copy($before);
        $this->after = ArraySnapshot::copy($after);
    }

    public function __serialize(): array
    {
        throw new LogicException('Declarations are process local');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Process-local value cannot be unserialized');
    }
}
