<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Storage;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Packman\Attributes\Internal;
use Psr\Http\Message\StreamInterface;
use RuntimeException as StorageOperationFailed;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Contracts\Storage\ResourceFilesystem;
use Tenant\Runtime\GenerationGuard;
use Throwable;

/** @internal Filesystem/path/symlink guards, with permanent generation and tracked streams. */
#[Internal]
final readonly class GuardedFilesystem implements ResourceFilesystem
{
    public function __construct(
        private Filesystem $filesystem,
        private GenerationGuard $guard,
        private string $root,
        private bool $localPaths = true,
    ) {}

    public function path(string $path): never
    {
        $this->assertCurrentContext();
        throw new TenantFailure('unsupported_capability');
    }

    public function exists(string $path): bool
    {
        $arguments = [$this->safePath($path)];

        return $this->call(method: 'exists', arguments: $arguments, operation: fn () => $this->filesystem->exists(...$arguments));
    }

    public function get(string $path): ?string
    {
        $arguments = [$this->safePath($path)];

        return $this->call(method: 'get', arguments: $arguments, operation: fn () => $this->filesystem->get(...$arguments));
    }

    public function readStream(string $path): TrackedLocalStream
    {
        $arguments = [$this->safePath($path)];

        return $this->call(method: 'readStream', arguments: $arguments, operation: function () use ($arguments): TrackedLocalStream {
            $release = $this->guard->acquire();
            try {
                $stream = $this->filesystem->readStream(...$arguments);
                if (! is_resource($stream)) {
                    throw new StorageOperationFailed('Cannot open stream');
                }

                return new TrackedLocalStream($stream, $this->guard, $release);
            } catch (Throwable $failure) {
                $release();
                throw $failure;
            }
        });
    }

    /** @param array<mixed>|string $options */
    public function put(string $path, mixed $contents, array|string $options = []): bool
    {
        if (! is_string($contents) && ! is_resource($contents) && ! $contents instanceof File && ! $contents instanceof UploadedFile && ! $contents instanceof StreamInterface) {
            throw new StorageOperationFailed('Invalid contents');
        }
        $arguments = [$this->safePath($path), $contents, $options];

        return $this->call(method: 'put', arguments: $arguments, operation: fn () => $this->filesystem->put(...$arguments));
    }

    /** @param array<mixed>|string $options */
    public function putFile(mixed $path, mixed $file = null, array|string $options = []): string|false
    {
        if ($file === null || is_array($file)) {
            [$path, $file, $options] = ['', $path, $file ?? []];
        }

        if (! is_string($file) && ! $file instanceof File && ! $file instanceof UploadedFile && $file !== null && ! is_array($file)) {
            throw new StorageOperationFailed('Invalid upload');
        }
        $arguments = [$this->safePath($path), $file, $options];

        return $this->call(method: 'putFile', arguments: $arguments, operation: fn () => $this->filesystem->putFile(...$arguments));
    }

    /** @param array<mixed>|string $options */
    public function putFileAs(mixed $path, mixed $file, mixed $name = null, array|string $options = []): string|false
    {
        if ($name === null || is_array($name)) {
            [$path, $file, $name, $options] = ['', $path, $file, $name ?? []];
        }

        if (! is_string($file) && ! $file instanceof File && ! $file instanceof UploadedFile && $file !== null && ! is_array($file)) {
            throw new StorageOperationFailed('Invalid upload');
        }
        $arguments = [$this->safePath($path), $file, $this->safePath($name), $options];

        return $this->call(method: 'putFileAs', arguments: $arguments, operation: fn () => $this->filesystem->putFileAs(...$arguments));
    }

    /** @param array<mixed> $options */
    public function writeStream(string $path, mixed $resource, array $options = []): bool
    {
        if (! is_resource($resource)) {
            throw new StorageOperationFailed('Invalid borrowed resource');
        }
        $arguments = [$this->safePath($path), $resource, $options];

        return $this->call(method: 'writeStream', arguments: $arguments, operation: fn () => $this->filesystem->writeStream(...$arguments));
    }

    public function getVisibility(string $path): string
    {
        $arguments = [$this->safePath($path)];

        return $this->call(method: 'getVisibility', arguments: $arguments, operation: fn () => $this->filesystem->getVisibility(...$arguments));
    }

    public function setVisibility(string $path, string $visibility): bool
    {
        $arguments = [$this->safePath($path), $visibility];

        return $this->call(method: 'setVisibility', arguments: $arguments, operation: fn () => $this->filesystem->setVisibility(...$arguments));
    }

    public function prepend(string $path, string $data): bool
    {
        $arguments = [$this->safePath($path), $data];

        return $this->call(method: 'prepend', arguments: $arguments, operation: fn () => $this->filesystem->prepend(...$arguments));
    }

    public function append(string $path, string $data): bool
    {
        $arguments = [$this->safePath($path), $data];

        return $this->call(method: 'append', arguments: $arguments, operation: fn () => $this->filesystem->append(...$arguments));
    }

    /** @param string|array<mixed> $paths */
    public function delete(string|array $paths): bool
    {
        $arguments = [is_array($paths) ? array_map($this->safePath(...), $paths) : $this->safePath($paths)];

        return $this->call(method: 'delete', arguments: $arguments, operation: fn () => $this->filesystem->delete(...$arguments));
    }

    public function copy(string $from, string $to): bool
    {
        $arguments = [$this->safePath($from), $this->safePath($to)];

        return $this->call(method: 'copy', arguments: $arguments, operation: fn () => $this->filesystem->copy(...$arguments));
    }

    public function move(string $from, string $to): bool
    {
        $arguments = [$this->safePath($from), $this->safePath($to)];

        return $this->call(method: 'move', arguments: $arguments, operation: fn () => $this->filesystem->move(...$arguments));
    }

    public function size(string $path): int
    {
        $arguments = [$this->safePath($path)];

        return $this->call(method: 'size', arguments: $arguments, operation: fn () => $this->filesystem->size(...$arguments));
    }

    public function lastModified(string $path): int
    {
        $arguments = [$this->safePath($path)];

        return $this->call(method: 'lastModified', arguments: $arguments, operation: fn () => $this->filesystem->lastModified(...$arguments));
    }

    /** @return array<string> */
    public function files(?string $directory = null, bool $recursive = false): array
    {
        $arguments = [$directory === null ? null : $this->safePath($directory), $recursive];

        return $this->call(method: 'files', arguments: $arguments, operation: fn () => $this->filesystem->files(...$arguments));
    }

    /** @return array<string> */
    public function allFiles(?string $directory = null): array
    {
        $arguments = [$directory === null ? null : $this->safePath($directory)];

        return $this->call(method: 'allFiles', arguments: $arguments, operation: fn () => $this->filesystem->allFiles(...$arguments));
    }

    /** @return array<string> */
    public function directories(?string $directory = null, bool $recursive = false): array
    {
        $arguments = [$directory === null ? null : $this->safePath($directory), $recursive];

        return $this->call(method: 'directories', arguments: $arguments, operation: fn () => $this->filesystem->directories(...$arguments));
    }

    /** @return array<string> */
    public function allDirectories(?string $directory = null): array
    {
        $arguments = [$directory === null ? null : $this->safePath($directory)];

        return $this->call(method: 'allDirectories', arguments: $arguments, operation: fn () => $this->filesystem->allDirectories(...$arguments));
    }

    public function makeDirectory(string $path): bool
    {
        $arguments = [$this->safePath($path)];

        return $this->call(method: 'makeDirectory', arguments: $arguments, operation: fn () => $this->filesystem->makeDirectory(...$arguments));
    }

    public function deleteDirectory(string $directory): bool
    {
        $arguments = [$this->safePath($directory)];

        return $this->call(method: 'deleteDirectory', arguments: $arguments, operation: fn () => $this->filesystem->deleteDirectory(...$arguments));
    }

    public function url(string $path): string
    {
        $this->safePath($path);
        $this->assertCurrentContext();

        throw new StorageOperationFailed('The scoped storage driver does not expose file URLs.');
    }

    /**
     * @template T
     *
     * @param  array<mixed>  $arguments
     * @param  Closure(): T  $operation
     * @return T
     */
    private function call(string $method, array $arguments, Closure $operation): mixed
    {
        $this->assertCurrentContext();
        $this->assertNoSymlink(path: '');

        if (! $this->localPaths) {
            $optionIndex = match ($method) {
                'put', 'putFile', 'writeStream' => 2,
                'putFileAs' => 3,
                default => null,
            };
            $options = $optionIndex === null ? [] : ($arguments[$optionIndex] ?? []);
            $visibility = $method === 'setVisibility' ? $arguments[1] : (is_array($options) ? ($options['visibility'] ?? 'private') : $options);

            if ($visibility !== 'private' || (is_array($options) && ($options['ACL'] ?? 'private') !== 'private')) {
                throw new StorageOperationFailed('Scoped object storage supports private visibility only.');
            }
        }

        try {
            return $operation();
        } catch (StorageOperationFailed $exception) {
            throw new StorageOperationFailed('Scoped storage operation failed.', previous: $exception);
        }
    }

    private function assertCurrentContext(): void
    {
        $this->guard->check();
    }

    private function safePath(mixed $path): string
    {
        if (! is_string($path) || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new StorageOperationFailed('Storage path is invalid.');
        }

        $this->assertCurrentContext();
        $decoded = $path;
        do {
            $previous = $decoded;
            $decoded = rawurldecode($decoded);
        } while ($decoded !== $previous);

        if (str_contains($decoded, "\0") || str_contains($decoded, '\\')) {
            throw new StorageOperationFailed('Storage path is invalid.');
        }

        if (str_starts_with($decoded, '/') || preg_match('/^[A-Za-z]:/', $decoded) === 1) {
            throw new StorageOperationFailed('Storage path must be relative.');
        }

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '..') {
                throw new StorageOperationFailed('Storage path traversal is forbidden.');
            }
        }

        $this->assertNoSymlink(path: $decoded);

        return $decoded;
    }

    private function assertNoSymlink(string $path): void
    {
        if (! $this->localPaths) {
            return;
        }

        self::assertRoot($this->root);
        $candidate = rtrim($this->root, DIRECTORY_SEPARATOR);

        for ($ancestor = $candidate; $ancestor !== '' && $ancestor !== dirname($ancestor); $ancestor = dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new StorageOperationFailed('Storage roots must not contain symbolic links.');
            }
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            $candidate .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($candidate)) {
                throw new StorageOperationFailed('Storage paths must not contain symbolic links.');
            }
        }
    }

    public static function assertRoot(string $root): void
    {
        for ($path = $root; $path !== '' && $path !== dirname($path); $path = dirname($path)) {
            if (is_link($path)) {
                throw new StorageOperationFailed('Storage roots must not contain symbolic links.');
            }
        }
    }
}
