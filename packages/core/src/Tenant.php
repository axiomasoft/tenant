<?php

declare(strict_types=1);

namespace Tenant;

use Closure;
use Packman\Attributes\Api;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\Selection;
use Tenant\Data\Runtime\FrameToken;

#[Api(since: '1.0')]
final readonly class Tenant
{
    public function __construct(private TenantRuntime $runtime) {}

    public function current(): ContextState
    {
        return $this->runtime->current();
    }

    public function enter(Selection $selection, Purpose $purpose): FrameToken
    {
        return $this->runtime->enter($selection, $purpose);
    }

    /** @param Closure(ContextState): mixed $callback */
    public function run(Selection $selection, Purpose $purpose, Closure $callback): mixed
    {
        return $this->runtime->run($selection, $purpose, $callback);
    }

    public function restore(FrameToken $token): void
    {
        $this->runtime->restore($token);
    }
}
