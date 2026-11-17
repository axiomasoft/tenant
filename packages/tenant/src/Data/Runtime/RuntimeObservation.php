<?php

declare(strict_types=1);

namespace Tenant\Data\Runtime;

use Packman\Attributes\Api;
use Tenant\Data\Context\ContextState;

#[Api(since: '1.0')]
final readonly class RuntimeObservation
{
    /** @param list<string> $causes */
    public function __construct(public int $version, public string $kind, public string $outcome, public string $frameCorrelation, public ?ContextState $subject, public ?string $reason, public array $causes = []) {}
}
