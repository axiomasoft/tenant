<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tenant\Laravel\Internal\Storage\GuardedFilesystem;
use Tenant\Runtime\GenerationGuard;

it('accepts a nested file and refuses the root again after that path becomes a symlink', function (): void {
    $base = guardedFilesystemBase();
    $root = $base.'/root';
    try {
        mkdir($root, 0700);
        $filesystem = guardedFilesystem($root);
        GuardedFilesystem::assertRoot($root);

        expect($filesystem->put('nested/file.txt', 'ok'))->toBeTrue()
            ->and($filesystem->get('nested/file.txt'))->toBe('ok')
            ->and($filesystem->files('nested'))->toContain('nested/file.txt');

        $moved = $base.'/moved';
        expect(rename($root, $moved))->toBeTrue()
            ->and(symlink($moved, $root))->toBeTrue();

        expect(fn (): ?string => $filesystem->get('nested/file.txt'))
            ->toThrow(RuntimeException::class, 'Storage roots must not contain symbolic links.');
    } finally {
        guardedFilesystemRemove($base);
    }
});

it('rejects a symlinked root and a symlinked ancestor', function (): void {
    $base = guardedFilesystemBase();
    try {
        mkdir($base.'/real/storage', 0700, true);
        expect(symlink($base.'/real/storage', $base.'/root-link'))->toBeTrue();
        $root = guardedFilesystem($base.'/root-link');

        expect(fn () => GuardedFilesystem::assertRoot($base.'/root-link'))
            ->toThrow(RuntimeException::class, 'Storage roots must not contain symbolic links.')
            ->and(fn (): array => $root->files())
            ->toThrow(RuntimeException::class, 'Storage roots must not contain symbolic links.')
            ->and(fn (): bool => $root->delete([]))
            ->toThrow(RuntimeException::class, 'Storage roots must not contain symbolic links.')
            ->and(fn (): bool => $root->exists('nested/file.txt'))
            ->toThrow(RuntimeException::class, 'Storage roots must not contain symbolic links.');

        expect(symlink($base.'/real', $base.'/ancestor'))->toBeTrue();
        $ancestor = guardedFilesystem($base.'/ancestor/storage');

        expect(fn () => GuardedFilesystem::assertRoot($base.'/ancestor/storage'))
            ->toThrow(RuntimeException::class, 'Storage roots must not contain symbolic links.')
            ->and(fn (): bool => $ancestor->exists('nested/file.txt'))
            ->toThrow(RuntimeException::class, 'Storage roots must not contain symbolic links.');
    } finally {
        guardedFilesystemRemove($base);
    }
});

it('rejects a symlink in a relative path without reading outside the root', function (): void {
    $base = guardedFilesystemBase();
    $root = $base.'/root';
    try {
        mkdir($root.'/nested', 0700, true);
        mkdir($base.'/outside', 0700);
        file_put_contents($base.'/outside/secret.txt', 'secret');
        expect(symlink($base.'/outside', $root.'/alias'))->toBeTrue();
        $filesystem = guardedFilesystem($root);

        expect($filesystem->put('nested/file.txt', 'ok'))->toBeTrue()
            ->and($filesystem->get('nested/file.txt'))->toBe('ok')
            ->and(fn (): ?string => $filesystem->get('alias/secret.txt'))
            ->toThrow(RuntimeException::class, 'Storage paths must not contain symbolic links.')
            ->and(is_file($base.'/outside/secret.txt'))->toBeTrue();
    } finally {
        guardedFilesystemRemove($base);
    }
});

function guardedFilesystem(string $root): GuardedFilesystem
{
    $adapter = new LocalFilesystemAdapter($root, lazyRootCreation: true);

    return new GuardedFilesystem(
        new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $root]),
        new GenerationGuard(static function (): void {}, static function (int $leases): void {}),
        $root,
    );
}

function guardedFilesystemBase(): string
{
    $base = sys_get_temp_dir().'/tenant-guarded-fs-'.bin2hex(random_bytes(6));
    if (! mkdir($base, 0700) && ! is_dir($base)) {
        throw new RuntimeException('temp directory unavailable');
    }

    return $base;
}

function guardedFilesystemRemove(string $path): void
{
    if (is_link($path)) {
        unlink($path);

        return;
    }

    if (is_file($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        guardedFilesystemRemove($path.DIRECTORY_SEPARATOR.$entry);
    }

    rmdir($path);
}
