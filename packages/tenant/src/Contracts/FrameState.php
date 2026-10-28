<?php

declare(strict_types=1);

namespace Tenant\Contracts;

use Tenant\Attributes\Spi;
use Tenant\Data\{BindingVerificationInput, CapabilityId, ContextReference, ContextState, Diagnostic, ExtensionRef, FrameToken, Purpose, ResolvedIsolation, RuntimeObservation, Selection, VerifiedBinding};

#[Spi]
interface FrameState
{
    public function put(string $key, mixed $value): void;
    public function get(string $key): mixed;
}
