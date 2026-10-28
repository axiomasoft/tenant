<?php

declare(strict_types=1);

namespace Tenant\Contracts;

use Tenant\Attributes\Spi;
use Tenant\Data\{BindingVerificationInput, CapabilityId, ContextReference, ContextState, Diagnostic, ExtensionRef, FrameToken, Purpose, ResolvedIsolation, RuntimeObservation, Selection, VerifiedBinding};

#[Spi]
interface Facility
{
    public function prepare(PhaseContext $context, FrameState $state): void;
    public function activate(PhaseContext $context, FrameState $state): void;
    public function restore(RestoreContext $context, FrameState $state): void;
    public function verify(RestoreContext $context, FrameState $state): void;
}
