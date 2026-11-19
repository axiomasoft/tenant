<?php

declare(strict_types=1);

namespace Tenant\Contracts\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Data\Runtime\Diagnostic;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DiagnosticSink
{
    public function report(Diagnostic $diagnostic): void;
}
