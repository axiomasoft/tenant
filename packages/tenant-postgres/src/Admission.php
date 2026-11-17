<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use Closure;
use LogicException;
use Packman\Attributes\Api;
use Tenant\Exceptions\Context\ContextDenied;
use Throwable;

/** Trusted host capability; cannot be serialized or reconstructed from wire data. */
#[Api(since: '1.0')]
final readonly class Admission
{
    /** @param Closure(string, string): mixed $authorize */
    public function __construct(private Closure $authorize) {}

    public function assertAllowed(string $purpose, string $target): void
    {
        try {
            $allowed = ($this->authorize)($purpose, $target);
        } catch (Throwable $cause) {
            throw new ContextDenied([$cause]);
        }
        if ($allowed !== true) {
            throw new ContextDenied;
        }
    }

    public function __serialize(): array
    {
        throw new LogicException('Admission is process local.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Admission is process local.');
    }
}
