<?php

declare(strict_types=1);

namespace Tenant\Laravel\Contracts;

use Tenant\Contracts\TrackedStream;

/** Guarded local operations. Streams are tracked values, never native PHP resources. */
interface ResourceFilesystem
{
    public function path(string $path): never;
    public function exists(string $path): bool;
    public function get(string $path): ?string;
    public function readStream(string $path): TrackedStream;
    public function put(string $path, mixed $contents, array|string $options = []): bool;
    public function putFile(mixed $path, mixed $file = null, array|string $options = []): string|false;
    public function putFileAs(mixed $path, mixed $file, mixed $name = null, array|string $options = []): string|false;
    public function writeStream(string $path, mixed $resource, array $options = []): bool;
    public function getVisibility(string $path): string;
    public function setVisibility(string $path, string $visibility): bool;
    public function prepend(string $path, string $data): bool;
    public function append(string $path, string $data): bool;
    public function delete(string|array $paths): bool;
    public function copy(string $from, string $to): bool;
    public function move(string $from, string $to): bool;
    public function size(string $path): int;
    public function lastModified(string $path): int;
    public function files(?string $directory = null, bool $recursive = false): array;
    public function allFiles(?string $directory = null): array;
    public function directories(?string $directory = null, bool $recursive = false): array;
    public function allDirectories(?string $directory = null): array;
    public function makeDirectory(string $path): bool;
    public function deleteDirectory(string $directory): bool;
    public function url(string $path): string;
}
