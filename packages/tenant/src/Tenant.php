<?php

declare(strict_types=1);

namespace Tenant;

use Closure;
use Tenant\Attributes\Api;
use Tenant\Contracts\TenantRuntime;
use Tenant\Data\{ContextState, FrameToken, Purpose, Selection};

#[Api]
final readonly class Tenant
{
    public function __construct(private TenantRuntime $runtime) {}
    public function current(): ContextState { return $this->runtime->current(); }
    public function enter(Selection $selection, Purpose $purpose): FrameToken { return $this->runtime->enter($selection, $purpose); }
    public function run(Selection $selection, Purpose $purpose, Closure $callback): mixed { return $this->runtime->run($selection, $purpose, $callback); }
    public function restore(FrameToken $token): void { $this->runtime->restore($token); }
}
