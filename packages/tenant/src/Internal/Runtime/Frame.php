<?php

declare(strict_types=1);

namespace Tenant\Internal\Runtime;

use Tenant\Contracts\Facility;
use Tenant\Data\{ContextState, FrameToken, Purpose, ResolvedIsolation, Selection};

/** @internal Source: CoreX DatabaseTenancyManager::$started, extended to frame-local state. */
final class Frame
{
    /** @var list<array{Facility, ParticipantFrameState}> Recorded BEFORE prepare. */
    public array $started = [];
    public readonly FrameToken $token;
    public readonly string $correlation;

    public function __construct(
        public readonly Selection $selection,
        public readonly Purpose $purpose,
        public readonly ResolvedIsolation $resolved,
        public readonly ContextState $previous,
    ) {
        $this->token = new FrameToken();
        $this->correlation = bin2hex(random_bytes(16));
    }
}
