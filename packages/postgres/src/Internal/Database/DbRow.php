<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Database;

use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use RuntimeException;
use Tenant\Postgres\ArchiveManifest;
use Tenant\Postgres\ExportRef;
use Tenant\Postgres\OperationRef;

/**
 * Typed property and config accessors over catalog database rows and serialized value payloads.
 *
 * @internal
 */
#[Internal]
final class DbRow
{
    public static function expect(mixed $row, string $context = 'Expected database row object.'): object
    {
        return is_object($row) ? $row : throw new LogicException($context);
    }

    public static function raw(object $row, string $field): mixed
    {
        return get_object_vars($row)[$field] ?? null;
    }

    public static function str(object $row, string $field): string
    {
        $value = get_object_vars($row)[$field] ?? null;
        if (! is_string($value)) {
            throw new LogicException("Database field [{$field}] must be a string.");
        }

        return $value;
    }

    public static function optStr(object $row, string $field): ?string
    {
        $value = get_object_vars($row)[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new LogicException("Database field [{$field}] must be a string or null.");
        }

        return $value;
    }

    public static function int(object $row, string $field): int
    {
        try {
            return DatabaseValue::integer(get_object_vars($row)[$field] ?? null);
        } catch (RuntimeException $exception) {
            throw new LogicException("Database field [{$field}] must be an integer.", 0, $exception);
        }
    }

    public static function bool(object $row, string $field): bool
    {
        $value = get_object_vars($row)[$field] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if ($value === 't' || $value === '1') {
            return true;
        }
        if ($value === 'f' || $value === '0') {
            return false;
        }

        throw new LogicException("Database field [{$field}] must be a boolean.");
    }

    /** @return array<string, mixed> */
    public static function configMap(Connection $connection): array
    {
        $config = $connection->getConfig();
        if (! is_array($config)) {
            return [];
        }

        $map = [];
        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    public static function manifest(string $json): ArchiveManifest
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new LogicException('Archive manifest JSON must decode to an object.');
        }

        $exportId = $decoded['exportId'] ?? null;
        $operationId = $decoded['operationId'] ?? null;
        $resourceRef = $decoded['resourceRef'] ?? null;
        $incarnation = $decoded['incarnation'] ?? null;
        $routeRevision = $decoded['routeRevision'] ?? null;
        $recoveryGeneration = $decoded['recoveryGeneration'] ?? null;
        $destinationRef = $decoded['destinationRef'] ?? null;
        $sha256 = $decoded['sha256'] ?? null;
        $sizeBytes = $decoded['sizeBytes'] ?? null;

        if (! is_string($exportId)
            || ! is_string($operationId)
            || ! is_string($resourceRef)
            || ! is_string($incarnation)
            || ! is_string($routeRevision)
            || ! is_string($recoveryGeneration)
            || ! is_string($destinationRef)
            || ! is_string($sha256)
            || ! is_int($sizeBytes)) {
            throw new LogicException('Archive manifest payload is malformed.');
        }

        return new ArchiveManifest(
            $exportId,
            $operationId,
            $resourceRef,
            $incarnation,
            $routeRevision,
            $recoveryGeneration,
            $destinationRef,
            $sha256,
            $sizeBytes,
        );
    }

    public static function exportRef(string $json): ExportRef
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new LogicException('Export reference JSON must decode to an object.');
        }

        $exportId = $decoded['exportId'] ?? null;
        $operation = $decoded['operation'] ?? null;
        if (! is_string($exportId) || ! is_array($operation)) {
            throw new LogicException('Export reference payload is malformed.');
        }

        $namespace = $operation['namespace'] ?? null;
        $operationId = $operation['operationId'] ?? null;
        $resourceRef = $operation['resourceRef'] ?? null;
        $incarnation = $operation['incarnation'] ?? null;

        if (! is_string($namespace)
            || ! is_string($operationId)
            || ! is_string($resourceRef)
            || ! is_string($incarnation)) {
            throw new LogicException('Export operation payload is malformed.');
        }

        return new ExportRef(
            $exportId,
            new OperationRef($namespace, $operationId, $resourceRef, $incarnation),
        );
    }
}
