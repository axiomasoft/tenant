<?php

declare(strict_types=1);

namespace Tenant\Composition;

use Tenant\Attributes\Api;

use Tenant\Data\ParticipantDeclaration;
use Tenant\Exceptions\TenantFailure;
use Tenant\Internal\Composition\Compiler;

#[Api]
final class CompositionBuilder
{
    /** @var list<ParticipantDeclaration> */
    private array $declarations = [];
    private bool $frozen = false;

    /**
     * @param array<string, mixed> $profiles
     * @param list<array{id: string, slot: string, spiVersion: int, service: object, capabilities: list<string>}> $services
     */
    public function __construct(private readonly array $profiles = [], private readonly array $services = []) {}

    public function register(ParticipantDeclaration $declaration): void
    {
        if ($this->frozen) throw new TenantFailure('composition_frozen');
        $this->declarations[] = $declaration;
    }

    public function compile(): CompiledComposition
    {
        if ($this->frozen) throw new TenantFailure('composition_frozen');
        $this->frozen = true;
        return (new Compiler())->compile($this->declarations, $this->profiles, $this->services);
    }
}
