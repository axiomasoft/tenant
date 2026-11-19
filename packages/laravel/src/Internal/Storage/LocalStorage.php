<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Storage;

use FilesystemIterator;
use Illuminate\Filesystem\FilesystemManager;
use Packman\Attributes\Internal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Contracts\Facilities\ResourceScope;
use Tenant\Laravel\Contracts\Storage\ResourceStorage;
use Tenant\Runtime\GenerationGuard;

/** @internal Durable/ephemeral local roots; no native path or cloud claim. */
#[Internal]
final readonly class LocalStorage implements ResourceStorage
{
    public function __construct(private FilesystemManager $filesystems, private GenerationGuard $guard, private ResourceScope $descriptor) {}

    public function disk(): GuardedFilesystem
    {
        return $this->build($this->descriptor->durableRoot);
    }

    public function ephemeralDisk(): GuardedFilesystem
    {
        return $this->build($this->descriptor->ephemeralRoot);
    }

    private function build(string $root): GuardedFilesystem
    {
        $this->guard->check();
        GuardedFilesystem::assertRoot($root);

        return new GuardedFilesystem($this->filesystems->build(['driver' => 'local', 'root' => $root, 'visibility' => 'private', 'directory_visibility' => 'private', 'links' => 'disallow', 'throw' => true]), $this->guard, $root);
    }

    public function usageBytes(): int
    {
        $this->guard->check();
        $root = $this->descriptor->durableRoot;
        GuardedFilesystem::assertRoot($root);
        if (! is_dir($root)) {
            return 0;
        }
        $bytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo) {
                throw new RuntimeException('Invalid storage entry');
            }
            if ($file->isLink()) {
                throw new RuntimeException('Storage root contains a symbolic link.');
            }
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return $bytes;
    }

    public function exportArchive(): never
    {
        $this->guard->check();
        throw new TenantFailure('unsupported_capability');
    }
}
