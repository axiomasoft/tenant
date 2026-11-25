<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Storage;

use Closure;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use RuntimeException;
use Tenant\Contracts\Runtime\TrackedStream;
use Tenant\Exceptions\Runtime\StaleHandle;
use Tenant\Runtime\GenerationGuard;

/** @internal Explicit close releases once; destructor cannot hide a boundary leak. */
#[Internal]
final class TrackedLocalStream implements TrackedStream
{
    private bool $closed = false;

    /**
     * @param  resource  $stream
     * @param  Closure(): void  $release
     */
    public function __construct(private mixed $stream, private readonly GenerationGuard $guard, private readonly Closure $release) {}

    private function check(): void
    {
        $this->guard->check();
        if ($this->closed) {
            throw new StaleHandle;
        }
    }

    public function read(int $length): string
    {
        $this->check();
        if ($length < 1) {
            throw new InvalidArgumentException('length');
        }
        $bytes = fread($this->stream, $length);
        if ($bytes === false) {
            throw new RuntimeException('Stream read failed');
        }

        return $bytes;
    }

    public function write(string $bytes): int
    {
        $this->check();
        $size = fwrite($this->stream, $bytes);
        if ($size === false) {
            throw new RuntimeException('Stream write failed');
        }

        return $size;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            fclose($this->stream);
        } finally {
            ($this->release)();
        }
    }

    public function __destruct()
    {
        if (! $this->closed && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
