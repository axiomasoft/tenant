<?php

declare(strict_types=1);

namespace Tenant\Contracts\Verification;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Data\Verification\VerifiedBinding;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface BindingVerifier
{
    public function verify(BindingVerificationInput $input): VerifiedBinding;
}
