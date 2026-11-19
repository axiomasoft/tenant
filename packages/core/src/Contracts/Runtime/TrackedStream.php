<?php

declare(strict_types=1);

namespace Tenant\Contracts\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface TrackedStream
{
    public function read(int $length): string;

    public function write(string $bytes): int;

    public function close(): void;
}
