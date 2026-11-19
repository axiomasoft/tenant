<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Queue;

use Packman\Attributes\Internal;
use Tenant\Data\Context\Purpose;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Queue\QueueRegistry;

/** @internal Validated transport data, never an authority verdict. */
#[Internal]
final readonly class ScalarPayload
{
    /** @param array<string, bool|int|string|null> $data */
    private function __construct(public string $wire, public Purpose $purpose, public string $producer, public string $handler, public array $data) {}

    public static function parse(mixed $raw, QueueRegistry $registry): self
    {
        if (! is_array($raw) || array_keys($raw) !== ['wire', 'purpose', 'producer', 'handler', 'data', 'signature']) {
            throw new TenantFailure('denied');
        }
        foreach (['wire', 'purpose', 'producer', 'handler', 'signature'] as $key) {
            if (! is_string($raw[$key])) {
                throw new TenantFailure('denied');
            }
        }
        if (! hash_equals($registry->sign(FrozenIntent::bytes($raw)), $raw['signature'])) {
            throw new TenantFailure('denied');
        }

        // Assign validated fields explicitly so no untrusted value is cast/coerced.
        return new self($raw['wire'], new Purpose($raw['purpose']), $raw['producer'], $raw['handler'], self::data($raw['data']));
    }

    /** @return array<string, bool|int|string|null> */
    public static function data(mixed $raw): array
    {
        if (! is_array($raw) || count($raw) > 64) {
            throw new TenantFailure('denied');
        }
        $data = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) || (is_string($value) && strlen($value) > 16384)) {
                throw new TenantFailure('denied');
            }
            $data[$key] = $value;
        }

        return $data;
    }
}
