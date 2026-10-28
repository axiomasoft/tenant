<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal;

use RuntimeException;
use stdClass;

/** @internal Decode this module's PDO values without coercing malformed state. */
final class DatabaseValue
{
    public static function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('Expected a database text value.');
        }

        return $value;
    }

    public static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new RuntimeException('Expected a database integer within the native range.');
    }

    public static function decimal(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $value) !== 1) {
            throw new RuntimeException('Expected an unsigned database decimal value.');
        }

        return (string) $value;
    }

    public static function row(mixed $value): stdClass
    {
        if (! $value instanceof stdClass) {
            throw new RuntimeException('Expected a database result row.');
        }

        return $value;
    }
}
