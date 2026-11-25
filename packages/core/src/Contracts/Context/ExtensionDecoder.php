<?php

declare(strict_types=1);

namespace Tenant\Contracts\Context;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Data\Context\ExtensionRef;
use Tenant\Data\Context\Purpose;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ExtensionDecoder
{
    public function validate(ExtensionRef $reference, PhaseContext $context, Purpose $purpose): void;
}
