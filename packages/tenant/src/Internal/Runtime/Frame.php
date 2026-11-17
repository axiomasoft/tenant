<?php

declare(strict_types=1);

namespace Tenant\Internal\Runtime;

use Packman\Attributes\Internal;
use Tenant\Contracts\Runtime\Facility;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Runtime\FrameToken;

/** @internal Tracks started participants in frame-local LIFO order. */
#[Internal]
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
        $this->token = new FrameToken;
        $this->correlation = bin2hex(random_bytes(16));
    }
}
