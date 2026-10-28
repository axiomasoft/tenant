<?php

declare(strict_types=1);

namespace Tenant\Contracts;

use Tenant\Attributes\Spi;
use Tenant\Data\{BindingVerificationInput, CapabilityId, ContextReference, ContextState, Diagnostic, ExtensionRef, FrameToken, Purpose, ResolvedIsolation, RuntimeObservation, Selection, VerifiedBinding};

#[Spi]
interface TrackedStream
{
    public function read(int $length): string;
    public function write(string $bytes): int;
    public function close(): void;
}
