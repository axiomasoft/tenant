<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Closure;
use SessionHandlerInterface;

/** @internal Wraps actual file/Redis handlers; native handles are never exposed. */
final readonly class GuardedSessionHandler implements SessionHandlerInterface
{
    public function __construct(public SessionHandlerInterface $delegate, private Closure $check) {}
    public function open(string $path, string $name): bool { ($this->check)(); return $this->delegate->open($path, $name); }
    public function close(): bool { ($this->check)(); return $this->delegate->close(); }
    public function read(string $id): string|false { ($this->check)(); return $this->delegate->read($id); }
    public function write(string $id, string $data): bool { ($this->check)(); return $this->delegate->write($id, $data); }
    public function destroy(string $id): bool { ($this->check)(); if ($this->delegate instanceof \Illuminate\Session\FileSessionHandler && $this->delegate->read($id) === '') return true; return $this->delegate->destroy($id); }
    public function gc(int $max_lifetime): int|false { ($this->check)(); return $this->delegate->gc($max_lifetime); }
}
