<?php

declare(strict_types=1);

namespace Tenant\Postgres;

/** Trusted host capability; cannot be serialized or reconstructed from wire data. */
final readonly class Admission
{
    public function __construct(private \Closure $authorize) {}

    public function assertAllowed(string $purpose, string $target): void
    {
        if (($this->authorize)($purpose, $target) !== true) throw new \RuntimeException('Admission denied.');
    }

    public function __serialize(): array { throw new \LogicException('Admission is process local.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Admission is process local.'); }
}
