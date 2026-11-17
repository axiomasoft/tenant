<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use Closure;
use Packman\Attributes\Api;
use RuntimeException;
use Tenant\Postgres\Contracts\Lifecycle\ArchiveStore;
use Tenant\Postgres\Internal\Database\DbRow;
use Throwable;

/** Native pg_dump writer+qualified verifier mechanism with durable original manifest and native PG tools. */
#[Api(since: '1.0')]
final readonly class PgDumpArchiveStore implements ArchiveStore
{
    /** @param Closure(object): array<string, mixed> $managementRoute Trusted owner route, never wire input. */
    public function __construct(private string $root, private string $destinationRef, private Closure $managementRoute, private string $pgDump) {}

    public function write(object $resource, string $operationId, string $exportId, string $destinationRef): ArchiveManifest
    {
        $this->directory();
        if ($destinationRef !== $this->destinationRef) {
            throw new RuntimeException('Unsupported archive destination.');
        }
        $path = $this->path($exportId);
        if (file_exists($path) || file_exists($path.'.manifest')) {
            throw new RuntimeException('Original archive exists; reconcile instead of overwriting.');
        }
        $databaseName = DbRow::str($resource, 'database_name');
        $route = ($this->managementRoute)($resource);
        if (($route['database'] ?? null) !== $databaseName || ($route['driver'] ?? null) !== 'pgsql' || (isset($route['url']) || isset($route['read']) || isset($route['write']))) {
            throw new RuntimeException('Archive route mismatch.');
        }
        $host = $route['host'] ?? null;
        $username = $route['username'] ?? null;
        $password = $route['password'] ?? null;
        $port = $route['port'] ?? 5432;
        if (! is_string($host) || ! is_string($username) || ! is_string($password) || (! is_int($port) && ! is_string($port))) {
            throw new RuntimeException('Archive route credentials are invalid.');
        }
        $tmp = $path.'.partial';
        $output = fopen($tmp, 'x');
        if ($output === false) {
            throw new RuntimeException('Archive output unavailable.');
        }
        chmod($tmp, 0600);
        $errors = fopen('php://temp', 'w+');
        if ($errors === false) {
            fclose($output);
            throw new RuntimeException('Archive error stream unavailable.');
        }
        $environment = ['PGHOST' => $host, 'PGPORT' => (string) $port, 'PGUSER' => $username, 'PGPASSWORD' => $password, 'PGCONNECT_TIMEOUT' => '10'];
        try {
            $process = proc_open([$this->pgDump, '--format=custom', '--no-owner', '--no-privileges', '--dbname', $databaseName], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $errors], $pipes, env_vars: $environment);
            if (! is_resource($process) || proc_close($process) !== 0) {
                throw new RuntimeException('Native pg_dump failed; archive remains quarantined.');
            }
            if (! fflush($output) || ! fsync($output)) {
                throw new RuntimeException('Archive sync failed.');
            }
        } finally {
            fclose($output);
            fclose($errors);
        }
        $sha256 = hash_file('sha256', $tmp);
        $sizeBytes = filesize($tmp);
        if (! is_string($sha256) || ! is_int($sizeBytes)) {
            throw new RuntimeException('Archive integrity computation failed.');
        }
        $manifest = new ArchiveManifest(
            $exportId,
            $operationId,
            DbRow::str($resource, 'resource_ref'),
            DbRow::str($resource, 'incarnation'),
            DbRow::str($resource, 'route_revision'),
            DbRow::str($resource, 'recovery_generation'),
            $destinationRef,
            $sha256,
            $sizeBytes,
        );
        if (! rename($tmp, $path)) {
            throw new RuntimeException('Archive commit failed.');
        }
        $file = fopen($path.'.manifest.partial', 'x');
        if ($file === false) {
            throw new RuntimeException('Archive manifest unavailable.');
        }chmod($path.'.manifest.partial', 0600);
        try {
            $bytes = json_encode(get_object_vars($manifest), JSON_THROW_ON_ERROR);
            if (fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file) || ! fsync($file)) {
                throw new RuntimeException('Manifest sync failed.');
            }
        } finally {
            fclose($file);
        }
        if (! rename($path.'.manifest.partial', $path.'.manifest')) {
            throw new RuntimeException('Manifest commit failed.');
        }
        $directory = fopen($this->root, 'r');
        if ($directory === false) {
            throw new RuntimeException('Archive directory unavailable.');
        }
        try {
            if (! fsync($directory)) {
                throw new RuntimeException('Archive directory sync failed.');
            }
        } finally {
            fclose($directory);
        }
        if (! $this->verify($manifest)) {
            throw new RuntimeException('archive_unverified');
        }

        return $manifest;
    }

    public function verify(ArchiveManifest $manifest): bool
    {
        try {
            $this->directory();
            $path = $this->path($manifest->exportId);
            clearstatcache(true, $path);
            if ($manifest->destinationRef !== $this->destinationRef || is_link($path) || is_link($path.'.manifest') || ! is_file($path) || ! is_readable($path) || ! is_file($path.'.manifest') || ! is_readable($path.'.manifest')) {
                return false;
            }

            $actualSha = hash_file('sha256', $path);

            return get_object_vars($this->recoverManifest($manifest->exportId)) === get_object_vars($manifest)
                && filesize($path) === $manifest->sizeBytes
                && is_string($actualSha)
                && hash_equals($manifest->sha256, $actualSha);
        } catch (Throwable) {
            return false;
        }
    }

    public function recover(string $exportId): ?ArchiveManifest
    {
        $this->directory();
        if (! is_file($this->path($exportId).'.manifest')) {
            return null;
        }
        $manifest = $this->recoverManifest($exportId);

        return $this->verify($manifest) ? $manifest : null;
    }

    private function recoverManifest(string $exportId): ArchiveManifest
    {
        return DbRow::manifest((string) file_get_contents($this->path($exportId).'.manifest'));
    }

    private function path(string $exportId): string
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $exportId) !== 1) {
            throw new RuntimeException('Invalid original export ID.');
        }

        return $this->root.'/'.$exportId.'.dump';
    }

    private function directory(): void
    {
        if (! is_dir($this->root) || is_link($this->root) || (fileperms($this->root) & 0077) !== 0) {
            throw new RuntimeException('Archive requires a private durable directory.');
        }
    }
}
