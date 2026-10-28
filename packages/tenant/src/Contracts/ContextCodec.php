<?php

declare(strict_types=1);

namespace Tenant\Contracts;

use Tenant\Attributes\Spi;
use Tenant\Data\{BindingVerificationInput, CapabilityId, ContextReference, ContextState, Diagnostic, ExtensionRef, FrameToken, Purpose, ResolvedIsolation, RuntimeObservation, Selection, VerifiedBinding};

#[Spi]
interface ContextCodec
{
    public function encode(ContextReference $reference): string;
    public function decode(string $json): ContextReference;
}
