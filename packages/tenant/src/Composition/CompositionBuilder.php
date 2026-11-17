<?php

declare(strict_types=1);

namespace Tenant\Composition;

use Packman\Attributes\Api;
use Tenant\Data\Composition\ParticipantDeclaration;
use Tenant\Exceptions\TenantFailure;
use Tenant\Internal\Composition\Compiler;
use Tenant\Internal\Validation\ArraySnapshot;

#[Api(since: '1.0')]
final class CompositionBuilder
{
    /** @var list<ParticipantDeclaration> */
    private array $declarations = [];

    private bool $frozen = false;

    /** @var array<string, mixed> */
    private readonly array $profiles;

    /** @var list<array{id: string, slot: string, spiVersion: int, service: object, capabilities: list<string>}> */
    private readonly array $services;

    /**
     * @param  array<string, mixed>  $profiles
     * @param  list<array{id: string, slot: string, spiVersion: int, service: object, capabilities: list<string>}>  $services
     */
    public function __construct(array $profiles = [], array $services = [])
    {
        $this->profiles = ArraySnapshot::copy($profiles);
        $this->services = ArraySnapshot::copy($services);
    }

    public function register(ParticipantDeclaration $declaration): void
    {
        if ($this->frozen) {
            throw new TenantFailure('composition_frozen');
        }
        $this->declarations[] = $declaration;
    }

    public function compile(): CompiledComposition
    {
        if ($this->frozen) {
            throw new TenantFailure('composition_frozen');
        }
        $this->frozen = true;

        return (new Compiler)->compile($this->declarations, $this->profiles, $this->services);
    }
}
