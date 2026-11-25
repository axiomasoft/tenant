<?php

declare(strict_types=1);

namespace Tenant\Data\Runtime;

use Packman\Attributes\Api;
use Tenant\Data\Context\ContextState;
use Tenant\Internal\Validation\ArraySnapshot;

#[Api(since: '1.0')]
final readonly class RuntimeObservation
{
    /** @var list<string> */
    public array $causes;

    /** @param list<string> $causes */
    public function __construct(public int $version, public string $kind, public string $outcome, public string $frameCorrelation, public ?ContextState $subject, public ?string $reason, array $causes = [])
    {
        $this->causes = ArraySnapshot::copy($causes);
    }
}
