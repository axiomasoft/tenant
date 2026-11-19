<?php

declare(strict_types=1);

namespace Tenant\Contracts\Runtime;

use Closure;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\Selection;
use Tenant\Data\Runtime\FrameToken;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TenantRuntime
{
    public function current(): ContextState;

    public function enter(Selection $selection, Purpose $purpose): FrameToken;

    /** @param Closure(ContextState): mixed $callback */
    public function run(Selection $selection, Purpose $purpose, Closure $callback): mixed;

    public function restore(FrameToken $token): void;
}
