<?php

declare(strict_types=1);

use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\Selection;
use Tenant\Data\Runtime\FrameToken;
use Tenant\Data\Verification\BindingExpectation;

/** Test host only. It is not a production runtime or a proof of frame safety. */
final class SingleHost implements TenantRuntime
{
    private ContextState $state;

    private ?FrameToken $token = null;

    public function __construct()
    {
        $this->state = ContextState::missing();
    }

    public function current(): ContextState
    {
        return $this->state;
    }

    public function enter(Selection $selection, Purpose $purpose): FrameToken
    {
        if ($selection->mode !== 'single' || $selection->identity?->namespace !== 'acme' || $selection->identity->id !== 'A' || $selection->expectedBinding === null || $selection->expectedBinding->resourceRef !== 'A' || $selection->expectedBinding->incarnation !== 'i1' || $selection->expectedBinding->routeRevision !== 'r1' || $this->token !== null) {
            throw new InvalidArgumentException('denied');
        }
        $this->state = ContextState::active(new IsolationContext('single', new IsolationIdentity('acme', 'A'), new ProfileRef('single', 1), new BindingExpectation('A', 'i1', 'r1')));

        return $this->token = new FrameToken;
    }

    public function restore(FrameToken $token): void
    {
        if ($token !== $this->token) {
            throw new InvalidArgumentException('token');
        }
        $this->token = null;
        $this->state = ContextState::missing();
    }

    public function run(Selection $selection, Purpose $purpose, Closure $callback): mixed
    {
        $token = $this->enter($selection, $purpose);
        try {
            return $callback($this->state);
        } finally {
            $this->restore($token);
        }
    }
}
