<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Database;

use Packman\Attributes\Internal;
use RuntimeException;

/** Allowlisted PostgreSQL identifier for DDL that cannot take a bound parameter. */
#[Internal]
final class PostgresIdentifier
{
    public static function isValid(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9_]{1,63}\z/D', $value) === 1;
    }

    public static function assert(string $value, string $message = 'Invalid PostgreSQL identifier.'): void
    {
        if (! self::isValid($value)) {
            throw new RuntimeException($message);
        }
    }

    public static function quote(string $value): string
    {
        self::assert($value);

        return '"'.$value.'"';
    }
}
