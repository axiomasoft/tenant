<?php

declare(strict_types=1);

namespace Tenant\Panels\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Context\Data\ScopeRef;
use Packman\Panels\Builder\Panel;
use Tenant\Data\Context\ContextState;

/** Maps the active, verified isolation context to the host's authorization scope. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PanelScopeResolver
{
    /**
     * Keep namespaces distinct; refuse unavailable mappings. Never switch the runtime.
     * Only explicit central contexts may map to the reserved global/system scopes.
     */
    public function resolve(Panel $panel, ContextState $context): ScopeRef;
}
