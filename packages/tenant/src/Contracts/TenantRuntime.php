<?php

declare(strict_types=1);

namespace Tenant\Contracts;

use Tenant\Attributes\Spi;
use Tenant\Data\{BindingVerificationInput, CapabilityId, ContextReference, ContextState, Diagnostic, ExtensionRef, FrameToken, Purpose, ResolvedIsolation, RuntimeObservation, Selection, VerifiedBinding};

#[Spi]
interface TenantRuntime
{
    public function current(): ContextState;
    public function enter(Selection $selection, Purpose $purpose): FrameToken;
    public function run(Selection $selection, Purpose $purpose, \Closure $callback): mixed;
    public function restore(FrameToken $token): void;
}
